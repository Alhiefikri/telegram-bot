<?php

namespace Tests\Feature;

use App\ApiDocs\CustomerApiDocumentation;
use App\ApiDocs\CustomerApiSamples;
use App\Enums\BalancePaymentPreference;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Services\CustomerApi\CustomerApiAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StorefrontApiDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_documentation_page_renders_both_languages_and_every_endpoint(): void
    {
        $response = $this->get(route('storefront.api-docs.index'))->assertOk()->assertViewIs('storefront.api-docs');

        $response->assertSee('API Supplier Lhie Store');
        $response->assertSee('Dokumentasi API Supplier | Lhie Store', false);
        $response->assertSee('<html lang="id"', false);
        $response->assertSee('id="overview"', false);
        $response->assertSee('lang="en"', false);
        $response->assertSee('Bahasa Indonesia');
        $response->assertSee('Keduanya');

        foreach ((new CustomerApiDocumentation)->operations() as $operation) {
            $response->assertSee($operation['method'].' '.$operation['path'], false);
            $response->assertSee($operation['title']['id']);
            $response->assertSee($operation['title']['en']);
        }

        $response->assertSee(route('storefront.api-docs.openapi'), false);
        $response->assertSee(route('storefront.api-docs.reference'), false);
        $response->assertSee(route('storefront.llms'), false);
    }

    public function test_documentation_page_includes_runnable_examples_in_four_languages(): void
    {
        $response = $this->get(route('storefront.api-docs.index'))->assertOk();

        foreach (['cURL', 'Python', 'JavaScript / TypeScript', 'PHP'] as $label) {
            $response->assertSee($label);
        }

        $body = (string) $response->getContent();
        $this->assertStringContainsString('--connect-timeout 5 --max-time 15', $body);
        $this->assertStringContainsString('AbortSignal.timeout(15_000)', $body);
        $this->assertStringContainsString('CURLOPT_TIMEOUT =&gt; 15', $body);
        $this->assertStringContainsString('urlopen(request, timeout=15)', $body);
        $this->assertStringContainsString('Idempotency-Key', $body);
        $this->assertStringNotContainsString('psk_', $body);
        $this->assertStringNotContainsString('capi_', $body);
    }

    public function test_openapi_document_parses_and_matches_the_implemented_routes(): void
    {
        $response = $this->get(route('storefront.api-docs.openapi'))->assertOk();
        $response->assertHeader('Content-Type', 'application/json; charset=UTF-8');

        $document = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(url('/'), $document['servers'][0]['url']);
        $this->assertArrayHasKey('bearerAuth', $document['components']['securitySchemes']);
        $this->assertSame('http', $document['components']['securitySchemes']['bearerAuth']['type']);
        $this->assertSame('bearer', $document['components']['securitySchemes']['bearerAuth']['scheme']);

        $routes = $this->registeredRoutes();
        $operations = (new CustomerApiDocumentation)->operations();
        $this->assertCount(count(array_unique(array_column($operations, 'path'))), $document['paths']);

        foreach ($operations as $operation) {
            $uri = ltrim($operation['path'], '/');
            $this->assertArrayHasKey($operation['method'].' '.$uri, $routes, $operation['path'].' is documented but not routed');
            $this->assertSame([$operation['tag']], $document['paths'][$operation['path']][strtolower($operation['method'])]['tags']);
            $this->assertSame($operation['errors'], $document['paths'][$operation['path']][strtolower($operation['method'])]['x-error-codes']);

            $responses = $document['paths'][$operation['path']][strtolower($operation['method'])]['responses'];
            foreach ($operation['errors'] as $code) {
                $status = (string) (new CustomerApiDocumentation)->errors()[$code]['status'];
                $this->assertArrayHasKey($status, $responses, $code.' has no documented response on '.$operation['path']);
                $this->assertSame('ErrorEnvelope', $this->referencedSchema($responses[$status]['content']['application/json']['schema']));
            }
        }

        $documented = [];
        foreach ($operations as $operation) {
            $documented = [...$documented, ...$operation['errors']];
        }
        $expectedCodes = array_keys((new CustomerApiDocumentation)->errors());
        $actualCodes = array_values(array_unique($documented));
        sort($expectedCodes);
        sort($actualCodes);
        $this->assertSame($expectedCodes, $actualCodes);

        $balanceResponses = $document['paths']['/api/v1/balance']['get']['responses'];
        foreach (['400', '401', '403', '422', '429', '503'] as $status) {
            $this->assertArrayHasKey($status, $balanceResponses);
        }

        $this->assertEveryReferenceResolves($document);
    }

    public function test_error_statuses_codes_and_meanings_match_across_all_public_formats(): void
    {
        $documentation = new CustomerApiDocumentation;
        $html = (string) $this->get('/api-docs/')->assertOk()->getContent();
        $reference = (string) $this->get('/api-docs/reference.md')->assertOk()->getContent();
        $openapi = $this->get('/api-docs/openapi.json')->assertOk()->json();
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($dom);
        $this->assertSame(array_keys($documentation->errors()), $openapi['components']['schemas']['Error']['properties']['code']['enum']);

        foreach ($documentation->operations() as $operation) {
            $heading = '### `'.$operation['method'].' '.$operation['path'].'`';
            $section = explode("\n### ", explode($heading, $reference, 2)[1], 2)[0];
            $responses = $openapi['paths'][$operation['path']][strtolower($operation['method'])]['responses'];
            $expected = [];
            foreach ($operation['errors'] as $code) {
                $error = $documentation->errors()[$code];
                $expected[$code] = (string) $error['status'];
                $this->assertStringContainsString('| `'.$error['status'].'` | `'.$code.'` | '.$error['meaning']['en'].' |', $section);
                $this->assertStringContainsString('`'.$code.'`: '.$error['meaning']['en'], $responses[$error['status']]['description']);
                if ($error['status'] === 503) {
                    $this->assertArrayHasKey('Retry-After', $responses[503]['headers']);
                }
            }
            foreach (['id', 'en'] as $locale) {
                $rows = $xpath->query('//article[@id="endpoint-'.strtolower($operation['id']).'"]//div[@lang="'.$locale.'"]//table[caption="'.$operation['method'].' '.$operation['path'].' error codes"]/tbody/tr');
                $actual = [];
                foreach ($rows as $row) {
                    $cells = $row->getElementsByTagName('td');
                    $code = trim($cells->item(1)->textContent);
                    $actual[$code] = trim($cells->item(0)->textContent);
                    $this->assertSame($documentation->errors()[$code]['meaning'][$locale], trim($cells->item(2)->textContent));
                }
                ksort($actual);
                ksort($expected);
                $this->assertSame($expected, $actual, $operation['id'].' '.$locale);
            }
            preg_match_all('/^\| `([0-9]{3})` \| `([a-z_]+)` \|/m', $section, $matches);
            $markdownErrors = array_combine($matches[2], $matches[1]);
            ksort($markdownErrors);
            $this->assertSame($expected, $markdownErrors, $operation['id'].' Markdown');
        }
    }

    public function test_openapi_document_publishes_valid_examples_and_implementation_bounds(): void
    {
        $document = json_decode((string) $this->get(route('storefront.api-docs.openapi'))->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $schemas = $document['components']['schemas'];

        $create = $document['paths']['/api/v1/orders']['post'];
        $this->assertTrue($create['requestBody']['required']);
        $this->assertSame('CreateOrderRequest', $this->referencedSchema($create['requestBody']['content']['application/json']['schema']));

        $idempotency = collect($create['parameters'])->firstWhere('name', 'Idempotency-Key');
        $this->assertNotNull($idempotency);
        $this->assertSame('header', $idempotency['in']);
        $this->assertTrue($idempotency['required']);

        $requestSample = $create['requestBody']['content']['application/json']['example'];
        $this->assertSame(['product_id', 'quantity', 'preference', 'expected_totals'], array_keys($requestSample));
        $this->assertJson(json_encode($requestSample, JSON_THROW_ON_ERROR));
        $this->assertMatchesRegularExpression('/'.CustomerApiDocumentation::POSITIVE_IDR_PATTERN.'/', $requestSample['expected_totals']['IDR']);

        foreach ($schemas as $name => $schema) {
            if (isset($schema['required'])) {
                $this->assertIsArray($schema['required'], $name.' must declare required fields');
            }
            foreach ($schema['properties'] ?? [] as $property => $definition) {
                if (isset($definition['$ref'])) {
                    $this->assertArrayHasKey(basename($definition['$ref']), $schemas, $name.'.'.$property.' has an unresolved reference');

                    continue;
                }
                if (isset($definition['oneOf'])) {
                    continue;
                }
                $this->assertArrayHasKey('description', $definition, $name.'.'.$property.' needs a description');
            }
            foreach ($schema['required'] ?? [] as $required) {
                $this->assertArrayHasKey($required, $schema['properties'] ?? [], $name.' requires an undocumented field '.$required);
            }
        }

        $this->assertSame(BalancePaymentPreference::cases(), array_map(
            static fn (string $value): BalancePaymentPreference => BalancePaymentPreference::from($value),
            $schemas['PaymentPreference']['enum'],
        ));
        $this->assertSame(
            array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases()),
            $schemas['OrderSummary']['properties']['status']['enum'],
        );
        $this->assertSame(
            array_map(static fn (PaymentStatus $status): string => $status->value, PaymentStatus::cases()),
            $schemas['OrderSummary']['properties']['payment_status']['enum'],
        );

        $this->assertSame(CustomerApiDocumentation::BODY_LIMIT_BYTES, 32_768);
        $this->assertSame(CustomerApiAdmission::PERMIT_SECONDS, 90);
        $this->assertSame(CustomerApiDocumentation::PAGE_LIMIT_MAX, 100);
        $this->assertSame(CustomerApiDocumentation::IDEMPOTENCY_KEY_MAX_LENGTH, 128);
        $this->assertSame([CustomerApiDocumentation::QUANTITY_MIN, CustomerApiDocumentation::QUANTITY_MAX], [1, 100]);

        $reference = (string) $this->get(route('storefront.api-docs.reference'))->getContent();
        $this->assertStringContainsString('## Rate limits', $reference);
        foreach ((new CustomerApiDocumentation)->budgets() as $budget) {
            $this->assertStringContainsString($budget['bound']['en'], $reference);
        }
    }

    public function test_documentation_samples_cover_every_required_field(): void
    {
        $documentation = new CustomerApiDocumentation;
        $schemas = $documentation->schemas();
        $samples = (new CustomerApiSamples)->all();

        foreach ($documentation->operations() as $operation) {
            foreach ($operation['responses'] as $response) {
                $this->assertArrayHasKey($response['schema'], $samples, $response['schema'].' has no published example');
            }
            if ($operation['request'] !== null) {
                $this->assertArrayHasKey($operation['request']['schema'], $samples);
            }
        }

        foreach ($samples as $name => $sample) {
            $schema = $schemas[$name];

            foreach ($schema['required'] ?? [] as $field) {
                $this->assertArrayHasKey($field, $sample, $name.' example misses required field '.$field);
            }
            foreach (array_keys($sample) as $field) {
                $this->assertArrayHasKey($field, $schema['properties'] ?? [], $name.' example has undeclared field '.$field);
            }
        }
    }

    public function test_markdown_reference_is_plain_text_and_covers_the_contract(): void
    {
        $response = $this->get(route('storefront.api-docs.reference'))->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('<script', strtolower($body));
        $this->assertStringNotContainsString('<div', strtolower($body));

        $documentation = new CustomerApiDocumentation;
        foreach ($documentation->operations() as $operation) {
            $this->assertStringContainsString('### `'.$operation['method'].' '.$operation['path'].'`', $body);
            foreach (['```bash', '```python', '```javascript', '```php'] as $fence) {
                $this->assertStringContainsString($fence, $body);
            }
        }

        foreach (array_keys($documentation->errors()) as $code) {
            $this->assertStringContainsString('`'.$code.'`', $body);
        }

        $this->assertStringContainsString('not a fiat-custody claim', $body);
        $this->assertStringContainsString('Never retry a purchase with a new key', $body);
        $this->assertStringContainsString('Authorization: Bearer <secret>', $body);
        $this->assertStringContainsString(route('storefront.api-docs.openapi'), $body);
    }

    public function test_llms_index_links_only_public_documentation_and_store_pages(): void
    {
        $response = $this->get(route('storefront.llms'))->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $body = (string) $response->getContent();
        $this->assertStringStartsWith('# Lhie Store', $body);

        foreach ([
            route('storefront.api-docs.reference'),
            route('storefront.api-docs.openapi'),
            route('storefront.api-docs.index'),
            route('storefront.home'),
            route('storefront.order-access.create'),
        ] as $url) {
            $this->assertStringContainsString('('.$url.')', $body);
        }

        $this->assertStringNotContainsString('/admin', $body);
        $this->assertStringNotContainsString('api/v1/balance', $body);
    }

    public function test_documentation_paths_are_crawlable_and_in_the_sitemap(): void
    {
        $robots = (string) $this->get(route('storefront.robots'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Disallow: /api-docs', $robots);
        $this->assertStringNotContainsString('Disallow: /llms.txt', $robots);

        $sitemap = (string) $this->get(route('storefront.sitemap'))->assertOk()->getContent();
        $this->assertStringContainsString(route('storefront.api-docs.index'), $sitemap);
    }

    public function test_docs_route_accepts_the_trailing_slash_form(): void
    {
        $this->get('/api-docs')->assertOk();
        $this->get('/api-docs/')->assertOk();
    }

    public function test_unenforced_shared_admission_fails_closed_as_documented(): void
    {
        // The SQLite lane has no Redis, so the documented fail-closed 503 must be
        // observable instead of an unbounded unauthenticated read.
        $response = $this->getJson('/api/v1/balance');
        $response->assertStatus(503);
        $response->assertJsonPath('error.code', 'temporarily_unavailable');
        $response->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<string, bool>
     */
    private function registeredRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $routes[$method.' '.$route->uri()] = true;
            }
        }

        return $routes;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function referencedSchema(array $schema): string
    {
        return basename((string) $schema['$ref']);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function assertEveryReferenceResolves(array $document): void
    {
        $names = array_keys($document['components']['schemas']);
        $this->assertGreaterThan(10, count($names));

        $walk = function (mixed $node) use (&$walk, $names): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if ($key === '$ref') {
                    $name = basename((string) $value);
                    $this->assertContains($name, $names, 'Unresolved reference '.$value);
                } else {
                    $walk($value);
                }
            }
        };

        $walk($document['paths']);
    }
}
