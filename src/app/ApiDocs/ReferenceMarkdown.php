<?php

namespace App\ApiDocs;

/**
 * Renders `/api-docs/reference.md`: one plain Markdown document with the
 * operation, parameter, schema, error, limit and example content of the
 * canonical contract, readable by humans and by machines without JavaScript.
 * The same source feeds the documentation page and the OpenAPI document.
 */
final class ReferenceMarkdown
{
    public function __construct(
        private readonly CustomerApiDocumentation $documentation,
        private readonly DocumentationTables $tables,
        private readonly CustomerApiGuide $guide,
        private readonly CustomerApiSamples $samples,
        private readonly CustomerApiExamples $examples,
    ) {}

    public function render(string $origin): string
    {
        $origin = rtrim($origin, '/');
        $sections = $this->guide->sections();
        $operations = $this->documentation->operations();

        $lines = [
            '# '.$this->documentation->title()['en'],
            '',
            $this->documentation->lead()['en'],
            '',
            '| Item | Value |',
            '| --- | --- |',
            '| Base path | `'.$origin.CustomerApiDocumentation::API_PREFIX.'` |',
            '| Authentication | `'.$this->documentation->security()['format'].'`, server to server only |',
            '| OpenAPI 3.1 | '.$origin.'/api-docs/openapi.json |',
            '| Bilingual documentation | '.$origin.'/api-docs/ |',
            '| AI index | '.$origin.'/llms.txt |',
            '| Document version | '.CustomerApiDocumentation::DOCUMENT_VERSION.' |',
            '| Body limit | '.CustomerApiDocumentation::BODY_LIMIT_BYTES.' bytes (`'.(CustomerApiDocumentation::BODY_LIMIT_BYTES / 1024).' KiB`) |',
            '| Page size | default '.CustomerApiDocumentation::PAGE_LIMIT_DEFAULT.', maximum '.CustomerApiDocumentation::PAGE_LIMIT_MAX.' |',
            '| Quantity | '.CustomerApiDocumentation::QUANTITY_MIN.' to '.CustomerApiDocumentation::QUANTITY_MAX.' per request |',
            '| Idempotency key | 1 to '.CustomerApiDocumentation::IDEMPOTENCY_KEY_MAX_LENGTH.' printable ASCII characters |',
            '',
            '## Contents',
            '',
        ];

        foreach ($sections as $section) {
            $lines[] = '- ['.$section['title']['en'].'](#'.$this->anchor($section['title']['en']).')';
        }
        $lines[] = '- [Endpoints](#endpoints)';
        foreach ($operations as $operation) {
            $lines[] = '  - [`'.$operation['method'].' '.$operation['path'].'`](#'.$this->anchor($operation['method'].' '.$operation['path']).')';
        }
        $lines[] = '- [Schemas](#schemas)';
        $lines[] = '- [Error codes](#error-codes)';
        $lines[] = '- [Rate limits](#rate-limits)';
        $lines[] = '';

        foreach ($sections as $section) {
            $lines[] = '## '.$section['title']['en'];
            $lines[] = '';
            $lines[] = $section['body']['en'];
            $lines[] = '';
        }

        $lines[] = '## Endpoints';
        $lines[] = '';
        foreach ($operations as $operation) {
            $lines = array_merge($lines, $this->operation($operation));
        }

        $lines[] = '## Schemas';
        $lines[] = '';
        foreach (array_keys($this->documentation->schemas()) as $schema) {
            $rows = $this->tables->fields($schema);
            if ($rows === []) {
                continue;
            }
            $lines[] = '### `'.$schema.'`';
            $lines[] = '';
            $lines = array_merge($lines, $this->table(
                ['Field', 'Type', 'Required', 'Description'],
                array_map(static fn (array $row): array => [
                    '`'.$row['field'].'`',
                    $row['type'],
                    $row['required'] ? 'yes' : 'no',
                    $row['description'],
                ], $rows),
            ));
            $lines[] = '';
        }

        $lines[] = '## Error codes';
        $lines[] = '';
        $lines = array_merge($lines, $this->table(
            ['Status', 'Code', 'Meaning'],
            array_map(static fn (array $row): array => ['`'.$row['status'].'`', '`'.$row['code'].'`', $row['meaning']], $this->tables->allErrors('en')),
        ));
        $lines[] = '';

        $lines[] = '## Rate limits';
        $lines[] = '';
        $lines[] = 'All budgets apply at the same time and are initial admission policy, not measured capacity. Authenticated responses report `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`; a 429 adds `Retry-After`. When shared admission cannot be enforced, authenticated routes fail closed with 503 and no mutation.';
        $lines[] = '';
        $lines = array_merge($lines, $this->table(
            ['Budget', 'Bound'],
            array_map(static fn (array $row): array => [$row['scope'], $row['bound']], $this->tables->budgets('en')),
        ));
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return list<string>
     */
    private function operation(array $operation): array
    {
        $lines = [
            '### `'.$operation['method'].' '.$operation['path'].'`',
            '',
            '**'.$operation['title']['en'].'**',
            '',
            $operation['summary']['en'],
            '',
            $operation['description']['en'],
            '',
        ];

        $parameters = $this->tables->parameters($operation, 'en');
        if ($parameters !== []) {
            $lines[] = '#### Parameters';
            $lines[] = '';
            $lines = array_merge($lines, $this->table(
                ['Name', 'In', 'Required', 'Type', 'Description'],
                array_map(static fn (array $row): array => ['`'.$row['name'].'`', $row['location'], $row['required'] ? 'yes' : 'no', $row['type'], $row['description']], $parameters),
            ));
            $lines[] = '';
        }

        if ($operation['request'] !== null) {
            $lines[] = '#### Request body';
            $lines[] = '';
            $lines[] = $operation['request']['description']['en'];
            $lines[] = '';
            $lines = array_merge($lines, $this->table(
                ['Field', 'Type', 'Required', 'Description'],
                array_map(static fn (array $row): array => ['`'.$row['field'].'`', $row['type'], $row['required'] ? 'yes' : 'no', $row['description']], $this->tables->fields($operation['request']['schema'])),
            ));
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = $this->json($this->samples->all()[$operation['request']['schema']] ?? []);
            $lines[] = '```';
            $lines[] = '';
        }

        $lines[] = '#### Responses';
        $lines[] = '';
        $lines = array_merge($lines, $this->table(
            ['Status', 'Meaning', 'Schema'],
            array_map(static fn (array $response): array => [
                '`'.$response['status'].'`',
                $response['description']['en'],
                '`'.$response['schema'].'`',
            ], $operation['responses']),
        ));
        $lines[] = '';

        $errors = $this->tables->errors($operation, 'en');
        if ($errors !== []) {
            $lines[] = '#### Errors';
            $lines[] = '';
            $lines = array_merge($lines, $this->table(
                ['Status', 'Code', 'Meaning'],
                array_map(static fn (array $row): array => ['`'.$row['status'].'`', '`'.$row['code'].'`', $row['meaning']], $errors),
            ));
            $lines[] = '';
        }

        if ($operation['notes'] !== []) {
            $lines[] = '#### Notes';
            $lines[] = '';
            foreach ($operation['notes'] as $note) {
                $lines[] = '- '.$note['en'];
            }
            $lines[] = '';
        }

        $samples = $this->samples->all();
        $sample = $samples[$operation['responses'][0]['schema']] ?? null;
        if ($sample !== null) {
            $lines[] = '#### Response example';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = $this->json($sample);
            $lines[] = '```';
            $lines[] = '';
        }

        $lines[] = '#### Examples';
        $lines[] = '';
        $languages = $this->examples->languages();
        foreach ($this->examples->for($operation['id']) as $language => $code) {
            $lines[] = '**'.$languages[$language]['label'].'**';
            $lines[] = '';
            $lines[] = '```'.$languages[$language]['syntax'];
            $lines[] = $code;
            $lines[] = '```';
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function table(array $headers, array $rows): array
    {
        $lines = [
            '| '.implode(' | ', $headers).' |',
            '| '.implode(' | ', array_fill(0, count($headers), '---')).' |',
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', array_map($this->cell(...), $row)).' |';
        }

        return $lines;
    }

    private function cell(string $value): string
    {
        return trim(str_replace(["\r\n", "\n", '|'], [' ', ' ', '\\|'], $value));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function anchor(string $heading): string
    {
        $slug = strtolower(str_replace('`', '', $heading));

        return trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9]+/', '-', $slug)), '-');
    }
}
