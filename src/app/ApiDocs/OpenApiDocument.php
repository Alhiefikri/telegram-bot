<?php

namespace App\ApiDocs;

/**
 * Builds the published OpenAPI 3.1 document from the canonical contract, so
 * operation, schema, enum, limit and error descriptions cannot drift from the
 * documentation page or the Markdown reference.
 */
final class OpenApiDocument
{
    public function __construct(
        private readonly CustomerApiDocumentation $documentation,
        private readonly CustomerApiSamples $samples = new CustomerApiSamples,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $origin): array
    {
        $origin = rtrim($origin, '/');
        $security = $this->documentation->security();
        $paths = [];

        foreach ($this->documentation->operations() as $operation) {
            $paths[$operation['path']][strtolower($operation['method'])] = $this->operation($operation);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $this->documentation->title()['en'],
                'version' => CustomerApiDocumentation::DOCUMENT_VERSION,
                'description' => implode("\n\n", [
                    $this->documentation->lead()['en'],
                    'Every request needs the `'.$security['scheme'].'` security scheme: send `'.$security['format'].'` over HTTPS. Keys are issued from the private Store Bot.',
                    'Money is always a decimal string. IDR is whole rupiah; USD has up to six decimals and is funded 1:1 by USDT as the store denomination, not a fiat-custody claim.',
                    'Responses use `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer` and `X-Request-ID`. Errors carry `error.code`, `error.message` and `error.request_id`.',
                    'Markdown reference: '.$origin.'/api-docs/reference.md',
                    'Bilingual documentation: '.$origin.'/api-docs/',
                ]),
            ],
            'servers' => [['url' => $origin, 'description' => 'Existing store origin. Documentation paths below already include the `/api/v1` prefix.']],
            'externalDocs' => ['description' => 'Bilingual documentation website with Indonesian and English explanations', 'url' => $origin.'/api-docs/'],
            'tags' => $this->documentation->tags(),
            'security' => [[$security['scheme'] => []]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    $security['scheme'] => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Customer API key',
                        'description' => $security['description'],
                    ],
                ],
                'schemas' => $this->documentation->schemas(),
            ],
        ];
    }

    public function json(string $origin): string
    {
        return json_encode(
            $this->build($origin),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function operation(array $operation): array
    {
        $errors = $this->documentation->errors();
        $responses = [];

        foreach ($operation['responses'] as $response) {
            $responses[(string) $response['status']] = [
                'description' => $response['description']['en'],
                'headers' => $this->successHeaders(),
                'content' => ['application/json' => $this->content($response['schema'])],
            ];
        }

        foreach ($operation['errors'] as $code) {
            $status = (string) $errors[$code]['status'];
            $responses[$status] ??= [
                'description' => '',
                'headers' => $this->errorHeaders($errors[$code]['status']),
                'content' => ['application/json' => $this->content('ErrorEnvelope')],
            ];
            $responses[$status]['description'] .= ($responses[$status]['description'] === '' ? '' : "\n\n")
                .'`'.$code.'`: '.$errors[$code]['meaning']['en'];
        }

        ksort($responses, SORT_NUMERIC);

        $payload = [
            'tags' => [$operation['tag']],
            'operationId' => $operation['id'],
            'summary' => $operation['summary']['en'],
            'description' => $operation['description']['en'],
            'parameters' => array_map($this->parameter(...), $operation['parameters']),
        ];

        if ($operation['request'] !== null) {
            $payload['requestBody'] = [
                'required' => (bool) $operation['request']['required'],
                'description' => $operation['request']['description']['en'],
                'content' => ['application/json' => $this->content($operation['request']['schema'])],
            ];
        }

        $payload['responses'] = $responses;

        if ($operation['errors'] !== []) {
            $payload['x-error-codes'] = $operation['errors'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $parameter
     * @return array<string, mixed>
     */
    private function parameter(array $parameter): array
    {
        return [
            'name' => $parameter['name'],
            'in' => $parameter['in'],
            'required' => (bool) $parameter['required'],
            'description' => $parameter['description']['en'],
            'schema' => $parameter['schema'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $schema): array
    {
        $samples = $this->samples->all();

        return array_filter([
            'schema' => ['$ref' => '#/components/schemas/'.$schema],
            'example' => $samples[$schema] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function successHeaders(): array
    {
        return [
            'X-Request-ID' => ['description' => 'Request id, also returned in `error.request_id`.', 'schema' => ['type' => 'string', 'format' => 'uuid']],
            'X-RateLimit-Limit' => ['description' => 'Limit of the budget with the least remaining headroom.', 'schema' => ['type' => 'integer']],
            'X-RateLimit-Remaining' => ['description' => 'Remaining requests in that budget.', 'schema' => ['type' => 'integer']],
            'X-RateLimit-Reset' => ['description' => 'Epoch-second conservative ceiling for that budget window.', 'schema' => ['type' => 'integer']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorHeaders(int $status): array
    {
        $headers = [
            'X-Request-ID' => ['description' => 'Request id matching `error.request_id`.', 'schema' => ['type' => 'string', 'format' => 'uuid']],
        ];
        if ($status === 429) {
            $headers['Retry-After'] = ['description' => 'Seconds to wait before retrying the same request.', 'schema' => ['type' => 'integer']];
        }
        if ($status === 503) {
            $headers['Retry-After'] = ['description' => 'Seconds to wait before retrying; nothing was charged.', 'schema' => ['type' => 'integer']];
        }

        return $headers;
    }
}
