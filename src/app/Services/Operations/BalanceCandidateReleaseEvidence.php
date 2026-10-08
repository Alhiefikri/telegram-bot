<?php

namespace App\Services\Operations;

use JsonException;

/** Reads an operator-recorded result index; never executes checks or grants activation. */
final class BalanceCandidateReleaseEvidence
{
    private const CHECKS = ['v1', 'v2', 'restore_drill', 'receipt_cutover'];

    /** @return array<string, mixed> */
    public function inspect(?string $candidateSha, ?string $manifestPath): array
    {
        $candidate = is_string($candidateSha) && preg_match('/\A[0-9a-f]{40}\z/D', $candidateSha) === 1
            ? $candidateSha : null;
        $result = ['ready' => false, 'candidate_sha' => $candidate, 'manifest_sha256' => null, 'reason' => 'missing_candidate', 'results' => []];
        if ($candidate === null) {
            return $result;
        }

        $result['reason'] = 'missing_manifest';
        if ($manifestPath === null || $manifestPath === '' || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return $result;
        }

        $content = file_get_contents($manifestPath, false, null, 0, 65_537);
        $result['reason'] = 'invalid_manifest';
        if ($content === false || strlen($content) > 65_536) {
            return $result;
        }
        $result['manifest_sha256'] = hash('sha256', $content);
        try {
            $manifest = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $result;
        }
        if (! is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1 || ! is_array($manifest['checks'] ?? null)) {
            return $result;
        }
        if (($manifest['candidate_sha'] ?? null) !== $candidate) {
            $result['reason'] = 'candidate_mismatch';

            return $result;
        }

        foreach (self::CHECKS as $key) {
            $row = $manifest['checks'][$key] ?? null;
            $reason = 'missing_result';
            $reference = null;
            $digest = null;
            if (is_array($row)) {
                $reference = $this->artifactReference($row['reference'] ?? null);
                $digest = is_string($row['sha256'] ?? null) && preg_match('/\A[0-9a-f]{64}\z/D', $row['sha256']) === 1
                    ? $row['sha256'] : null;
                $reason = match (true) {
                    ($row['candidate_sha'] ?? null) !== $candidate => 'candidate_mismatch',
                    ! is_int($row['exit_code'] ?? null) => 'missing_outcome',
                    $row['exit_code'] !== 0 => 'failed',
                    $key === 'v2' && ($row['profile'] ?? null) !== 'full' => 'diagnostic_profile',
                    $reference === null || $digest === null => 'missing_artifact',
                    ! hash_equals($digest, hash_file('sha256', base_path('../'.$reference)) ?: '') => 'artifact_mismatch',
                    default => 'passed',
                };
            }
            $result['results'][$key] = ['status' => $reason === 'passed' ? 'pass' : 'blocked', 'reason' => $reason, 'reference' => $reference, 'sha256' => $digest];
        }
        $result['ready'] = array_filter($result['results'], static fn (array $row): bool => $row['status'] !== 'pass') === [];
        $result['reason'] = $result['ready'] ? 'verified' : 'incomplete_or_failed_results';

        return $result;
    }

    private function artifactReference(mixed $reference): ?string
    {
        // Only retained local artifacts, never arbitrary files, URLs or payloads.
        if (! is_string($reference) || strlen($reference) > 512 || preg_match('#\Aartifacts/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\z#D', $reference) !== 1) {
            return null;
        }
        $root = realpath(base_path('../artifacts'));
        $path = realpath(base_path('../'.$reference));
        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return $reference;
    }
}
