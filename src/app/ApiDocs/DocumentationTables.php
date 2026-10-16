<?php

namespace App\ApiDocs;

/**
 * Row shapes shared by the documentation page and the Markdown reference, so a
 * parameter, schema field, error or budget row is computed once and only
 * formatted twice.
 */
final class DocumentationTables
{
    public function __construct(private readonly CustomerApiDocumentation $documentation) {}

    /**
     * @param  array<string, mixed>  $operation
     * @return list<array{name: string, location: string, required: bool, type: string, description: string}>
     */
    public function parameters(array $operation, string $locale): array
    {
        $rows = [];

        foreach ($operation['parameters'] as $parameter) {
            $rows[] = [
                'name' => $parameter['in'] === 'path' ? $parameter['name'] : $parameter['name'],
                'location' => $parameter['in'],
                'required' => (bool) $parameter['required'],
                'type' => $this->describe($parameter['schema'], $parameter['type']),
                'description' => $parameter['description'][$locale],
            ];
        }

        return $rows;
    }

    /**
     * Field rows for one documented schema, including nested object and array paths.
     *
     * @return list<array{field: string, type: string, required: bool, description: string}>
     */
    public function fields(string $schema, string $locale = 'en'): array
    {
        $schemas = $this->documentation->schemas();
        if (! isset($schemas[$schema])) {
            throw new \InvalidArgumentException('Unknown schema '.$schema.'.');
        }

        $rows = [];
        $this->walk($this->resolve($schemas[$schema], $schemas), '', $schemas, $rows, $locale);

        return $rows;
    }

    /**
     * Lower-cased haystack for the documentation page filter, built from the
     * canonical operation so the page list and its table of contents agree.
     *
     * @param  array<string, mixed>  $operation
     */
    public function searchText(array $operation): string
    {
        return strtolower(implode(' ', array_filter([
            $operation['method'],
            $operation['path'],
            $operation['tag'],
            $operation['title']['id'],
            $operation['title']['en'],
            ...array_map(static fn (array $parameter): string => $parameter['name'], $operation['parameters']),
            $operation['request']['schema'] ?? null,
        ])));
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return list<array{status: int, code: string, meaning: string}>
     */
    public function errors(array $operation, string $locale): array
    {
        $errors = $this->documentation->errors();
        $rows = [];

        foreach ($operation['errors'] as $code) {
            $rows[] = ['status' => $errors[$code]['status'], 'code' => $code, 'meaning' => $errors[$code]['meaning'][$locale]];
        }

        usort($rows, static fn (array $left, array $right): int => [$left['status'], $left['code']] <=> [$right['status'], $right['code']]);

        return $rows;
    }

    /**
     * @return list<array{status: int, code: string, meaning: string}>
     */
    public function allErrors(string $locale): array
    {
        $rows = [];
        foreach ($this->documentation->errors() as $code => $error) {
            $rows[] = ['status' => $error['status'], 'code' => $code, 'meaning' => $error['meaning'][$locale]];
        }

        usort($rows, static fn (array $left, array $right): int => [$left['status'], $left['code']] <=> [$right['status'], $right['code']]);

        return $rows;
    }

    /**
     * @return list<array{scope: string, bound: string}>
     */
    public function budgets(string $locale): array
    {
        return array_map(
            static fn (array $budget): array => ['scope' => $budget['scope'][$locale], 'bound' => $budget['bound'][$locale]],
            $this->documentation->budgets(),
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, array<string, mixed>>  $schemas
     * @param  list<array{field: string, type: string, required: bool, description: string}>  $rows
     */
    private function walk(array $schema, string $prefix, array $schemas, array &$rows, string $locale, int $depth = 0): void
    {
        if ($depth > 6) {
            return;
        }

        if (isset($schema['oneOf'])) {
            foreach ($schema['oneOf'] as $branch) {
                $branch = $this->resolve($branch, $schemas);
                if (($branch['type'] ?? null) === 'null') {
                    continue;
                }
                $this->walk($branch, $prefix, $schemas, $rows, $locale, $depth + 1);
            }

            return;
        }

        $type = $schema['type'] ?? null;
        if ($type === 'array' && isset($schema['items'])) {
            $items = $this->resolve($schema['items'], $schemas);
            if (isset($items['properties']) || isset($items['oneOf'])) {
                $this->walk($items, $prefix.'[]', $schemas, $rows, $locale, $depth + 1);
            }

            return;
        }

        if (! isset($schema['properties'])) {
            return;
        }

        $required = (array) ($schema['required'] ?? []);
        foreach ($schema['properties'] as $name => $property) {
            $field = $prefix === '' ? (string) $name : $prefix.'.'.$name;
            $property = $this->resolve($property, $schemas);
            $rows[] = [
                'field' => $field,
                'type' => $this->describe($property, null),
                'required' => in_array((string) $name, array_map('strval', $required), true),
                'description' => (string) ($property['description'] ?? ''),
            ];
            $this->walk($property, $field, $schemas, $rows, $locale, $depth + 1);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, array<string, mixed>>  $schemas
     * @return array<string, mixed>
     */
    private function resolve(array $schema, array $schemas): array
    {
        $guard = 0;
        while (isset($schema['$ref']) && $guard < 10) {
            $name = substr((string) $schema['$ref'], (int) strrpos((string) $schema['$ref'], '/') + 1);
            if (! isset($schemas[$name])) {
                return $schema;
            }
            $schema = $schemas[$name];
            $guard++;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    private function describe(?array $schema, ?string $fallbackType): string
    {
        $schema ??= [];

        if (isset($schema['$ref'])) {
            return basename((string) $schema['$ref']);
        }

        $types = (array) ($schema['type'] ?? ($fallbackType === null ? [] : [$fallbackType]));
        $nullable = in_array('null', $types, true);
        $types = array_values(array_filter($types, static fn (mixed $type): bool => $type !== 'null'));
        $type = (string) ($types[0] ?? $fallbackType ?? 'object');

        if (array_key_exists('const', $schema)) {
            $type = 'constant '.$this->scalar($schema['const']);
        } elseif (isset($schema['enum'])) {
            $type = implode(' | ', array_map(fn (mixed $value): string => $this->scalar($value), $schema['enum']));
        } elseif ($type === 'array') {
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];
            $inner = isset($items['$ref']) ? basename((string) $items['$ref']) : (string) ($items['type'] ?? 'object');
            $type = $inner === 'object' ? 'array of objects' : 'array of '.$inner;
        }

        if (($schema['pattern'] ?? null) !== null) {
            $type .= ' (`'.$schema['pattern'].'`)';
        }
        if (($schema['format'] ?? null) !== null) {
            $type .= ' ('.$schema['format'].')';
        }
        if (isset($schema['minimum']) && isset($schema['maximum'])) {
            $type .= ' ('.$schema['minimum'].' to '.$schema['maximum'].')';
        } elseif (isset($schema['minimum'])) {
            $type .= ' (minimum '.$schema['minimum'].')';
        } elseif (isset($schema['maxItems'])) {
            $type .= ' (at most '.$schema['maxItems'].' items)';
        }

        return $nullable ? $type.' or null' : $type;
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return (string) $value;
    }
}
