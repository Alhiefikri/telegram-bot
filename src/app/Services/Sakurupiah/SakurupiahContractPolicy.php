<?php

namespace App\Services\Sakurupiah;

final class SakurupiahContractPolicy
{
    public const CURRENT_RECORD_VERSION = 1;

    /**
     * @var array<string, string>
     */
    public const REQUIRED_GATES = [
        'request_encoding' => 'SAKURUPIAH_EXTERNAL_REQUEST_ENCODING',
        'signature_canonicalization' => 'SAKURUPIAH_EXTERNAL_SIGNATURE_CANONICALIZATION',
        'channel_contract' => 'SAKURUPIAH_EXTERNAL_CHANNEL_CONTRACT',
        'expiry_contract' => 'SAKURUPIAH_EXTERNAL_EXPIRY_CONTRACT',
        'create_response' => 'SAKURUPIAH_EXTERNAL_CREATE_RESPONSE',
        'presentation' => 'SAKURUPIAH_EXTERNAL_PRESENTATION',
        'amount_evidence' => 'SAKURUPIAH_EXTERNAL_AMOUNT_EVIDENCE',
        'duplicate_reference' => 'SAKURUPIAH_EXTERNAL_DUPLICATE_REFERENCE',
        'callback_contract' => 'SAKURUPIAH_EXTERNAL_CALLBACK_CONTRACT',
        'error_limits' => 'SAKURUPIAH_EXTERNAL_ERROR_LIMITS',
        'production_network' => 'SAKURUPIAH_EXTERNAL_PRODUCTION_NETWORK',
        'credential_operations' => 'SAKURUPIAH_EXTERNAL_CREDENTIAL_OPERATIONS',
    ];

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, string>
     */
    public static function invalid(array $policy): array
    {
        $invalid = [];

        if (($policy['record_version'] ?? null) !== self::CURRENT_RECORD_VERSION
            || ! self::filledString($policy['record_reference'] ?? null)) {
            $invalid['SAKURUPIAH_EXTERNAL_CONTRACT_RECORD'] = 'Sakurupiah external contract policy record must have a supported version and redacted evidence reference.';
        }

        $gates = $policy['gates'] ?? null;
        $evidenceReferences = $policy['evidence_references'] ?? null;
        if (! is_array($gates)) {
            $invalid['SAKURUPIAH_EXTERNAL_CONTRACT_GATES'] = 'Sakurupiah external contract gates must be explicitly configured.';
        }
        if (! is_array($evidenceReferences)) {
            $invalid['SAKURUPIAH_EXTERNAL_CONTRACT_EVIDENCE'] = 'Sakurupiah external contract evidence references must be explicitly configured.';
        }

        foreach (self::REQUIRED_GATES as $key => $label) {
            if (! is_array($gates) || ($gates[$key] ?? null) !== true) {
                $invalid[$label] = "{$label}: Sakurupiah external contract gate {$key} is not qualified.";
            }
            if (! is_array($evidenceReferences)
                || ! self::filledString($evidenceReferences[$key] ?? null)) {
                $invalid[$label.'_EVIDENCE'] = "{$label}_EVIDENCE: Sakurupiah external contract gate {$key} needs a redacted evidence reference.";
            }
        }

        if (($policy['automatic_fulfillment_approved'] ?? false) !== true) {
            $invalid['SAKURUPIAH_EXTERNAL_OWNER_APPROVAL'] = 'Sakurupiah automatic fulfillment requires explicit Owner approval.';
        } elseif (! self::filledString($policy['owner_approval_reference'] ?? null)) {
            $invalid['SAKURUPIAH_EXTERNAL_OWNER_APPROVAL_RECORD'] = 'Sakurupiah automatic fulfillment requires a redacted Owner approval reference.';
        }

        return $invalid;
    }

    private static function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
