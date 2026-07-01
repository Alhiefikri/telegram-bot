<?php

namespace App\Services\Admin;

final class ProductDescriptionSectionProtocol
{
    private const FEATURES_HEADING = 'Fitur utama:';

    private const SPECIFICATIONS_HEADING = 'Paket/spesifikasi:';

    public function encode(string $description, string $features, string $specifications): string
    {
        $sections = [trim($description)];

        $featureRows = $this->lines($features);
        if ($featureRows !== []) {
            $sections[] = self::FEATURES_HEADING."\n- ".implode("\n- ", $featureRows);
        }

        $specificationRows = $this->lines($specifications);
        if ($specificationRows !== []) {
            $sections[] = self::SPECIFICATIONS_HEADING."\n- ".implode("\n- ", $specificationRows);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return array{description: string, features: string, specifications: string}
     */
    public function decode(?string $description): array
    {
        $description = trim((string) $description);
        $sections = preg_split("/\n{2,}/", $description) ?: [];
        $base = trim((string) ($sections[0] ?? ''));
        $features = '';
        $specifications = '';

        foreach (array_slice($sections, 1) as $section) {
            $section = trim((string) $section);

            if (str_starts_with($section, self::FEATURES_HEADING)) {
                $features = $this->decodeListBody($section, self::FEATURES_HEADING);
            }

            if (str_starts_with($section, self::SPECIFICATIONS_HEADING)) {
                $specifications = $this->decodeListBody($section, self::SPECIFICATIONS_HEADING);
            }
        }

        return [
            'description' => $base,
            'features' => $features,
            'specifications' => $specifications,
        ];
    }

    /**
     * @return list<string>
     */
    private function lines(string $value): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $value) ?: [] as $row) {
            $row = trim($row);

            if ($row !== '') {
                $lines[] = $row;
            }
        }

        return $lines;
    }

    private function decodeListBody(string $section, string $heading): string
    {
        $lines = [];
        $body = trim(substr($section, strlen($heading)));

        foreach (preg_split('/\R/u', $body) ?: [] as $row) {
            $row = preg_replace('/^- /', '', trim($row)) ?? '';

            if ($row !== '') {
                $lines[] = $row;
            }
        }

        return implode("\n", $lines);
    }
}
