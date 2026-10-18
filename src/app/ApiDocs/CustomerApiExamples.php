<?php

namespace App\ApiDocs;

/**
 * Runnable code examples, one per operation and language.
 *
 * Every snippet reads its origin, key and identifiers from environment
 * variables, uses explicit connect and total timeouts, checks the HTTP status
 * before trusting a body, and keeps one stable `Idempotency-Key` for a
 * purchase intent. None of them retries a purchase with a new key.
 *
 * The same strings are published on `/api-docs/` and in
 * `/api-docs/reference.md`, and the verification walkthrough extracts them
 * from the published Markdown and executes them against a disposable runtime.
 */
final class CustomerApiExamples
{
    public const ENVIRONMENT = [
        'STORE_ORIGIN' => 'Origin of this store, for example `https://store.example`.',
        'CUSTOMER_API_KEY' => 'Customer API secret from the Store Bot. Backend only.',
        'PRODUCT_ID' => 'Product id from `GET /api/v1/products`.',
        'ORDER_ID' => 'Order id returned by `POST /api/v1/orders`.',
        'IDEMPOTENCY_KEY' => 'One stable key per purchase intent.',
        'EXPECTED_IDR_TOTAL' => 'Whole-rupiah `total` copied from the quote.',
        'EXPECTED_USD_TOTAL' => 'USD `total` copied from the quote.',
    ];

    /**
     * @return array<string, array{label: string, syntax: string, path: string}>
     */
    public function languages(): array
    {
        return [
            'curl' => ['label' => 'cURL', 'syntax' => 'bash', 'path' => 'buy.sh'],
            'python' => ['label' => 'Python', 'syntax' => 'python', 'path' => 'buy.py'],
            'javascript' => ['label' => 'JavaScript / TypeScript', 'syntax' => 'javascript', 'path' => 'buy.mjs'],
            'php' => ['label' => 'PHP', 'syntax' => 'php', 'path' => 'buy.php'],
        ];
    }

    /**
     * @return array{curl: string, python: string, javascript: string, php: string}
     */
    public function for(string $operationId): array
    {
        return match ($operationId) {
            'getBalance' => [
                'curl' => $this->balanceCurl(),
                'python' => $this->balancePython(),
                'javascript' => $this->balanceJavaScript(),
                'php' => $this->balancePhp(),
            ],
            'listProducts' => [
                'curl' => $this->productsCurl(),
                'python' => $this->productsPython(),
                'javascript' => $this->productsJavaScript(),
                'php' => $this->productsPhp(),
            ],
            'getProduct' => [
                'curl' => $this->productCurl(),
                'python' => $this->productPython(),
                'javascript' => $this->productJavaScript(),
                'php' => $this->productPhp(),
            ],
            'createOrder' => [
                'curl' => $this->orderCurl(),
                'python' => $this->orderPython(),
                'javascript' => $this->orderJavaScript(),
                'php' => $this->orderPhp(),
            ],
            'listOrders' => [
                'curl' => $this->ordersCurl(),
                'python' => $this->ordersPython(),
                'javascript' => $this->ordersJavaScript(),
                'php' => $this->ordersPhp(),
            ],
            'getOrder' => [
                'curl' => $this->orderStatusCurl(),
                'python' => $this->orderStatusPython(),
                'javascript' => $this->orderStatusJavaScript(),
                'php' => $this->orderStatusPhp(),
            ],
            'getOrderDelivery' => [
                'curl' => $this->deliveryCurl(),
                'python' => $this->deliveryPython(),
                'javascript' => $this->deliveryJavaScript(),
                'php' => $this->deliveryPhp(),
            ],
            default => throw new \InvalidArgumentException('Unknown operation '.$operationId.'.'),
        };
    }

    private function balanceCurl(): string
    {
        return <<<'CODE'
# Reads this account's own wallets. No identity parameter exists.
: "${STORE_ORIGIN:?export STORE_ORIGIN first}"
: "${CUSTOMER_API_KEY:?export CUSTOMER_API_KEY first}"

curl --fail-with-body --silent --show-error --connect-timeout 5 --max-time 15 \
  --header "Authorization: Bearer ${CUSTOMER_API_KEY}" \
  --header 'Accept: application/json' \
  "${STORE_ORIGIN}/api/v1/balance"
CODE;
    }

    private function balancePython(): string
    {
        return <<<'CODE'
import json, os, urllib.error, urllib.request

ORIGIN = os.environ["STORE_ORIGIN"].rstrip("/")
KEY = os.environ["CUSTOMER_API_KEY"]


def call(method: str, path: str, body: dict | None = None, headers: dict | None = None) -> tuple[int, dict]:
    request = urllib.request.Request(
        ORIGIN + path,
        method=method,
        data=None if body is None else json.dumps(body).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {KEY}",
            "Accept": "application/json",
            **(headers or {}),
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read() or b"{}")


status, payload = call("GET", "/api/v1/balance")
if status != 200:
    raise SystemExit(f"balance failed: {status} {payload}")

for wallet in payload["data"]["wallets"]:
    print(wallet["currency"], wallet["available"], wallet["amount"])
CODE;
    }

    private function balanceJavaScript(): string
    {
        return <<<'CODE'
const origin = process.env.STORE_ORIGIN.replace(/\/+$/, "");
const key = process.env.CUSTOMER_API_KEY;

async function call(method, path, body, headers = {}) {
  const response = await fetch(origin + path, {
    method,
    signal: AbortSignal.timeout(15_000),
    headers: {
      Authorization: `Bearer ${key}`,
      Accept: "application/json",
      ...(body === undefined ? {} : { "Content-Type": "application/json" }),
      ...headers,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const payload = await response.json().catch(() => ({}));
  return { status: response.status, payload };
}

const { status, payload } = await call("GET", "/api/v1/balance");
if (status !== 200) throw new Error(`balance failed: ${status} ${JSON.stringify(payload)}`);

for (const wallet of payload.data.wallets) {
  console.log(wallet.currency, wallet.available, wallet.amount);
}
CODE;
    }

    private function balancePhp(): string
    {
        return <<<'CODE'
<?php

$origin = rtrim((string) getenv('STORE_ORIGIN'), '/');
$key = (string) getenv('CUSTOMER_API_KEY');

/** @return array{0: int, 1: array<string, mixed>} */
$call = function (string $method, string $path, ?array $body = null, array $headers = []) use ($origin, $key): array {
    $curl = curl_init($origin.$path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge([
            'Authorization: Bearer '.$key,
            'Accept: application/json',
        ], $body === null ? [] : ['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) {
        throw new RuntimeException('transport: '.$error);
    }

    return [$status, json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
};

[$status, $payload] = $call('GET', '/api/v1/balance');
if ($status !== 200) {
    exit('balance failed: '.$status.' '.json_encode($payload));
}

foreach ($payload['data']['wallets'] as $wallet) {
    printf("%s available=%s amount=%s\n", $wallet['currency'], var_export($wallet['available'], true), var_export($wallet['amount'], true));
}
CODE;
    }

    private function productsCurl(): string
    {
        return <<<'CODE'
# First page of the catalog with quotes for two units. Follow `pagination.next_cursor`
# for the next page and keep the same filters.
: "${STORE_ORIGIN:?export STORE_ORIGIN first}"
: "${CUSTOMER_API_KEY:?export CUSTOMER_API_KEY first}"

curl --fail-with-body --silent --show-error --connect-timeout 5 --max-time 15 \
  --header "Authorization: Bearer ${CUSTOMER_API_KEY}" \
  --header 'Accept: application/json' \
  "${STORE_ORIGIN}/api/v1/products?limit=20&quantity=2"
CODE;
    }

    private function productsPython(): string
    {
        return <<<'CODE'
import json, os, urllib.error, urllib.request

ORIGIN = os.environ["STORE_ORIGIN"].rstrip("/")
KEY = os.environ["CUSTOMER_API_KEY"]


def call(method: str, path: str, body: dict | None = None) -> tuple[int, dict]:
    request = urllib.request.Request(
        ORIGIN + path,
        method=method,
        data=None if body is None else json.dumps(body).encode("utf-8"),
        headers={"Authorization": f"Bearer {KEY}", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read() or b"{}")


status, page = call("GET", "/api/v1/products?limit=20&quantity=2")
if status != 200:
    raise SystemExit(f"catalog failed: {status} {page}")

for product in page["data"]:
    quotes = {quote["currency"]: quote["total"] for quote in product["quotes"] if quote["eligible"]}
    print(product["id"], product["name"], quotes, product["stock"]["quantity_available"])

print("next_cursor:", page["pagination"]["next_cursor"])
CODE;
    }

    private function productsJavaScript(): string
    {
        return <<<'CODE'
const origin = process.env.STORE_ORIGIN.replace(/\/+$/, "");
const key = process.env.CUSTOMER_API_KEY;

async function call(method, path, body) {
  const response = await fetch(origin + path, {
    method,
    signal: AbortSignal.timeout(15_000),
    headers: {
      Authorization: `Bearer ${key}`,
      Accept: "application/json",
      ...(body === undefined ? {} : { "Content-Type": "application/json" }),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const payload = await response.json().catch(() => ({}));
  return { status: response.status, payload };
}

const { status, payload } = await call("GET", "/api/v1/products?limit=20&quantity=2");
if (status !== 200) throw new Error(`catalog failed: ${status} ${JSON.stringify(payload)}`);

for (const product of payload.data) {
  const totals = Object.fromEntries(
    product.quotes.filter((quote) => quote.eligible).map((quote) => [quote.currency, quote.total]),
  );
  console.log(product.id, product.name, totals, product.stock.quantity_available);
}

console.log("next_cursor:", payload.pagination.next_cursor);
CODE;
    }

    private function productsPhp(): string
    {
        return <<<'CODE'
<?php

$origin = rtrim((string) getenv('STORE_ORIGIN'), '/');
$key = (string) getenv('CUSTOMER_API_KEY');

$call = function (string $method, string $path) use ($origin, $key): array {
    $curl = curl_init($origin.$path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key, 'Accept: application/json'],
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) {
        throw new RuntimeException('transport: '.$error);
    }

    return [$status, json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
};

[$status, $page] = $call('GET', '/api/v1/products?limit=20&quantity=2');
if ($status !== 200) {
    exit('catalog failed: '.$status.' '.json_encode($page));
}

foreach ($page['data'] as $product) {
    $totals = [];
    foreach ($product['quotes'] as $quote) {
        if ($quote['eligible']) {
            $totals[$quote['currency']] = $quote['total'];
        }
    }
    printf("%s %s %s available=%s\n", $product['id'], $product['name'], json_encode($totals), var_export($product['stock']['quantity_available'], true));
}

echo 'next_cursor: '.json_encode($page['pagination']['next_cursor']).PHP_EOL;
CODE;
    }

    private function productCurl(): string
    {
        return <<<'CODE'
# Quotes are informational: they neither reserve stock nor freeze the price.
: "${STORE_ORIGIN:?export STORE_ORIGIN first}"
: "${CUSTOMER_API_KEY:?export CUSTOMER_API_KEY first}"
: "${PRODUCT_ID:?export PRODUCT_ID first}"

curl --fail-with-body --silent --show-error --connect-timeout 5 --max-time 15 \
  --header "Authorization: Bearer ${CUSTOMER_API_KEY}" \
  --header 'Accept: application/json' \
  "${STORE_ORIGIN}/api/v1/products/${PRODUCT_ID}?quantity=1"
CODE;
    }

    private function productPython(): string
    {
        return <<<'CODE'
import json, os, urllib.error, urllib.request

ORIGIN = os.environ["STORE_ORIGIN"].rstrip("/")
KEY = os.environ["CUSTOMER_API_KEY"]
PRODUCT_ID = os.environ["PRODUCT_ID"]


def call(method: str, path: str, body: dict | None = None) -> tuple[int, dict]:
    request = urllib.request.Request(
        ORIGIN + path,
        method=method,
        data=None if body is None else json.dumps(body).encode("utf-8"),
        headers={"Authorization": f"Bearer {KEY}", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read() or b"{}")


status, product = call("GET", f"/api/v1/products/{PRODUCT_ID}?quantity=1")
if status != 200:
    raise SystemExit(f"product failed: {status} {product}")

for quote in product["data"]["quotes"]:
    print(quote["currency"], quote["eligible"], quote["regular_total"], quote["total"])
CODE;
    }

    private function productJavaScript(): string
    {
        return <<<'CODE'
const origin = process.env.STORE_ORIGIN.replace(/\/+$/, "");
const key = process.env.CUSTOMER_API_KEY;
const productId = process.env.PRODUCT_ID;

async function call(method, path) {
  const response = await fetch(origin + path, {
    method,
    signal: AbortSignal.timeout(15_000),
    headers: { Authorization: `Bearer ${key}`, Accept: "application/json" },
  });
  const payload = await response.json().catch(() => ({}));
  return { status: response.status, payload };
}

const { status, payload } = await call("GET", `/api/v1/products/${productId}?quantity=1`);
if (status !== 200) throw new Error(`product failed: ${status} ${JSON.stringify(payload)}`);

for (const quote of payload.data.quotes) {
  console.log(quote.currency, quote.eligible, quote.regular_total, quote.total);
}
CODE;
    }

    private function productPhp(): string
    {
        return <<<'CODE'
<?php

$origin = rtrim((string) getenv('STORE_ORIGIN'), '/');
$key = (string) getenv('CUSTOMER_API_KEY');
$productId = (string) getenv('PRODUCT_ID');

$curl = curl_init($origin.'/api/v1/products/'.$productId.'?quantity=1');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key, 'Accept: application/json'],
]);
$raw = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
curl_close($curl);
if ($raw === false) {
    exit('transport: '.$error);
}
$product = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
if ($status !== 200) {
    exit('product failed: '.$status.' '.json_encode($product));
}

foreach ($product['data']['quotes'] as $quote) {
    printf("%s eligible=%s regular=%s total=%s\n", $quote['currency'], var_export($quote['eligible'], true), var_export($quote['regular_total'], true), var_export($quote['total'], true));
}
CODE;
    }

    private function orderCurl(): string
    {
        return <<<'CODE'
# One purchase intent, one stable key. Send EXPECTED_*_TOTAL for every currency you
# authorize (copy `total` from the quote above). Repeating this exact command after a
# timeout replays the same order. Never change the body under the same key.
: "${STORE_ORIGIN:?export STORE_ORIGIN first}"
: "${CUSTOMER_API_KEY:?export CUSTOMER_API_KEY first}"
: "${PRODUCT_ID:?export PRODUCT_ID first}"
: "${IDEMPOTENCY_KEY:?export IDEMPOTENCY_KEY first}"
: "${EXPECTED_IDR_TOTAL:?export EXPECTED_IDR_TOTAL first}"
: "${EXPECTED_USD_TOTAL:?export EXPECTED_USD_TOTAL first}"

curl --fail-with-body --silent --show-error --connect-timeout 5 --max-time 15 \
  --request POST \
  --header "Authorization: Bearer ${CUSTOMER_API_KEY}" \
  --header 'Content-Type: application/json' \
  --header 'Accept: application/json' \
  --header "Idempotency-Key: ${IDEMPOTENCY_KEY}" \
  --data "{\"product_id\":\"${PRODUCT_ID}\",\"quantity\":1,\"expected_totals\":{\"IDR\":\"${EXPECTED_IDR_TOTAL}\",\"USD\":\"${EXPECTED_USD_TOTAL}\"}}" \
  "${STORE_ORIGIN}/api/v1/orders"
CODE;
    }

    private function orderPython(): string
    {
        return <<<'CODE'
import json, os, urllib.error, urllib.request

ORIGIN = os.environ["STORE_ORIGIN"].rstrip("/")
KEY = os.environ["CUSTOMER_API_KEY"]
PRODUCT_ID = os.environ["PRODUCT_ID"]
IDEMPOTENCY_KEY = os.environ["IDEMPOTENCY_KEY"]


def call(method: str, path: str, body: dict | None = None, headers: dict | None = None) -> tuple[int, dict]:
    request = urllib.request.Request(
        ORIGIN + path,
        method=method,
        data=None if body is None else json.dumps(body).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {KEY}",
            "Accept": "application/json",
            **(headers or {}),
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read() or b"{}")


# 1. Quote the exact quantity and authorize every currency you accept.
status, product = call("GET", f"/api/v1/products/{PRODUCT_ID}?quantity=1")
if status != 200:
    raise SystemExit(f"quote failed: {status} {product}")
expected = {quote["currency"]: quote["total"] for quote in product["data"]["quotes"] if quote["eligible"]}
if not expected:
    raise SystemExit("this Product has no eligible price")

# 2. One purchase intent: the same key and the same body on every retry.
order_body = {"product_id": PRODUCT_ID, "quantity": 1, "expected_totals": expected}
status, order = call("POST", "/api/v1/orders", order_body, {"Idempotency-Key": IDEMPOTENCY_KEY})

if status == 409 and order.get("error", {}).get("code") == "in_progress":
    raise SystemExit(f"identical request still running, retry after {order['error'].get('request_id')}")
if status not in (200, 201):
    raise SystemExit(f"order failed: {status} {order}")

print("order", order["data"]["id"], order["data"]["currency"], order["data"]["amount"], "replay:", order["idempotent_replay"])
CODE;
    }

    private function orderJavaScript(): string
    {
        return <<<'CODE'
const origin = process.env.STORE_ORIGIN.replace(/\/+$/, "");
const key = process.env.CUSTOMER_API_KEY;
const productId = process.env.PRODUCT_ID;
const idempotencyKey = process.env.IDEMPOTENCY_KEY;

async function call(method, path, body, headers = {}) {
  const response = await fetch(origin + path, {
    method,
    signal: AbortSignal.timeout(15_000),
    headers: {
      Authorization: `Bearer ${key}`,
      Accept: "application/json",
      ...(body === undefined ? {} : { "Content-Type": "application/json" }),
      ...headers,
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const payload = await response.json().catch(() => ({}));
  return { status: response.status, payload };
}

// 1. Quote the exact quantity and authorize every currency you accept.
const quote = await call("GET", `/api/v1/products/${productId}?quantity=1`);
if (quote.status !== 200) throw new Error(`quote failed: ${quote.status} ${JSON.stringify(quote.payload)}`);
const expectedTotals = Object.fromEntries(
  quote.payload.data.quotes.filter((entry) => entry.eligible).map((entry) => [entry.currency, entry.total]),
);
if (Object.keys(expectedTotals).length === 0) throw new Error("this Product has no eligible price");

// 2. One purchase intent: the same key and the same body on every retry.
const order = await call(
  "POST",
  "/api/v1/orders",
  { product_id: productId, quantity: 1, expected_totals: expectedTotals },
  { "Idempotency-Key": idempotencyKey },
);

if (order.status === 409 && order.payload?.error?.code === "in_progress") {
  throw new Error(`identical request still running: ${order.payload.error.request_id}`);
}
if (order.status !== 200 && order.status !== 201) {
  throw new Error(`order failed: ${order.status} ${JSON.stringify(order.payload)}`);
}

console.log("order", order.payload.data.id, order.payload.data.currency, order.payload.data.amount, "replay:", order.payload.idempotent_replay);
CODE;
    }

    private function orderPhp(): string
    {
        return <<<'CODE'
<?php

$origin = rtrim((string) getenv('STORE_ORIGIN'), '/');
$key = (string) getenv('CUSTOMER_API_KEY');
$productId = (string) getenv('PRODUCT_ID');
$idempotencyKey = (string) getenv('IDEMPOTENCY_KEY');

$call = function (string $method, string $path, ?array $body = null, array $headers = []) use ($origin, $key): array {
    $curl = curl_init($origin.$path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge([
            'Authorization: Bearer '.$key,
            'Accept: application/json',
        ], $body === null ? [] : ['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) {
        throw new RuntimeException('transport: '.$error);
    }

    return [$status, json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
};

// 1. Quote the exact quantity and authorize every currency you accept.
[$status, $product] = $call('GET', '/api/v1/products/'.$productId.'?quantity=1');
if ($status !== 200) {
    exit('quote failed: '.$status.' '.json_encode($product));
}
$expected = [];
foreach ($product['data']['quotes'] as $quote) {
    if ($quote['eligible']) {
        $expected[$quote['currency']] = $quote['total'];
    }
}
if ($expected === []) {
    exit('this Product has no eligible price');
}

// 2. One purchase intent: the same key and the same body on every retry.
[$status, $order] = $call(
    'POST',
    '/api/v1/orders',
    ['product_id' => $productId, 'quantity' => 1, 'expected_totals' => $expected],
    ['Idempotency-Key: '.$idempotencyKey],
);

if ($status === 409 && ($order['error']['code'] ?? null) === 'in_progress') {
    exit('identical request still running, request id: '.($order['error']['request_id'] ?? 'unknown'));
}
if ($status !== 200 && $status !== 201) {
    exit('order failed: '.$status.' '.json_encode($order));
}

printf("order %s %s %s replay=%s\n", $order['data']['id'], $order['data']['currency'], $order['data']['amount'], var_export($order['idempotent_replay'], true));
CODE;
    }

    private function ordersCurl(): string
    {
        return <<<'CODE'
# Owned API history. Add `&status=processing` to filter, or `&cursor=...` for the next page.
: "${STORE_ORIGIN:?export STORE_ORIGIN first}"
: "${CUSTOMER_API_KEY:?export CUSTOMER_API_KEY first}"

curl --fail-with-body --silent --show-error --connect-timeout 5 --max-time 15 \
  --header "Authorization: Bearer ${CUSTOMER_API_KEY}" \
  --header 'Accept: application/json' \
  "${STORE_ORIGIN}/api/v1/orders?limit=20"
CODE;
    }

    private function ordersPython(): string
    {
        return <<<'CODE'
import json, os, urllib.error, urllib.request

ORIGIN = os.environ["STORE_ORIGIN"].rstrip("/")
KEY = os.environ["CUSTOMER_API_KEY"]


def call(path: str) -> tuple[int, dict]:
    request = urllib.request.Request(
        ORIGIN + path,
        headers={"Authorization": f"Bearer {KEY}", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read() or b"{}")


status, page = call("/api/v1/orders?limit=20")
if status != 200:
    raise SystemExit(f"history failed: {status} {page}")

for order in page["data"]:
    print(order["id"], order["status"], order["payment_status"], order["currency"], order["amount"], order["fulfillment"]["state"])

print("next_cursor:", page["pagination"]["next_cursor"])
CODE;
    }

    private function ordersJavaScript(): string
    {
        return <<<'CODE'
const origin = process.env.STORE_ORIGIN.replace(/\/+$/, "");
const key = process.env.CUSTOMER_API_KEY;

const response = await fetch(`${origin}/api/v1/orders?limit=20`, {
  signal: AbortSignal.timeout(15_000),
  headers: { Authorization: `Bearer ${key}`, Accept: "application/json" },
});
const page = await response.json().catch(() => ({}));
if (response.status !== 200) throw new Error(`history failed: ${response.status} ${JSON.stringify(page)}`);

for (const order of page.data) {
  console.log(order.id, order.status, order.payment_status, order.currency, order.amount, order.fulfillment.state);
}

console.log("next_cursor:", page.pagination.next_cursor);
CODE;
    }

    private function ordersPhp(): string
    {
        return <<<'CODE'
<?php

$origin = rtrim((string) getenv('STORE_ORIGIN'), '/');
$key = (string) getenv('CUSTOMER_API_KEY');

$curl = curl_init($origin.'/api/v1/orders?limit=20');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key, 'Accept: application/json'],
]);
$raw = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
curl_close($curl);
if ($raw === false) {
    exit('transport: '.$error);
}
$page = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
if ($status !== 200) {
    exit('history failed: '.$status.' '.json_encode($page));
}

foreach ($page['data'] as $order) {
    printf("%s %s %s %s %s %s\n", $order['id'], $order['status'], $order['payment_status'], $order['currency'], var_export($order['amount'], true), $order['fulfillment']['state']);
}

echo 'next_cursor: '.json_encode($page['pagination']['next_cursor']).PHP_EOL;
CODE;
    }

    private function orderStatusCurl(): string
    {
        return <<<'CODE'
# A foreign, missing, malformed or retail order id returns the same 404 shape.
: "${STORE_ORIGIN:?export STORE_ORIGIN first}"
: "${CUSTOMER_API_KEY:?export CUSTOMER_API_KEY first}"
: "${ORDER_ID:?export ORDER_ID first}"

curl --fail-with-body --silent --show-error --connect-timeout 5 --max-time 15 \
  --header "Authorization: Bearer ${CUSTOMER_API_KEY}" \
  --header 'Accept: application/json' \
  "${STORE_ORIGIN}/api/v1/orders/${ORDER_ID}"
CODE;
    }

    private function orderStatusPython(): string
    {
        return <<<'CODE'
import json, os, urllib.error, urllib.request

ORIGIN = os.environ["STORE_ORIGIN"].rstrip("/")
KEY = os.environ["CUSTOMER_API_KEY"]
ORDER_ID = os.environ["ORDER_ID"]


def call(path: str) -> tuple[int, dict]:
    request = urllib.request.Request(
        ORIGIN + path,
        headers={"Authorization": f"Bearer {KEY}", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read() or b"{}")


status, order = call(f"/api/v1/orders/{ORDER_ID}")
if status != 200:
    raise SystemExit(f"status failed: {status} {order}")

summary = order["data"]
print(summary["status"], summary["payment_status"], summary["fulfillment"]["state"], summary["delivery"]["state"])
print("released:", summary["fulfillment"]["released_units"], "of", summary["fulfillment"]["ordered_units"])
print("refund:", summary["refund"]["state"], summary["refund"]["confirmed_amount"])
CODE;
    }

    private function orderStatusJavaScript(): string
    {
        return <<<'CODE'
const origin = process.env.STORE_ORIGIN.replace(/\/+$/, "");
const key = process.env.CUSTOMER_API_KEY;
const orderId = process.env.ORDER_ID;

const response = await fetch(`${origin}/api/v1/orders/${orderId}`, {
  signal: AbortSignal.timeout(15_000),
  headers: { Authorization: `Bearer ${key}`, Accept: "application/json" },
});
const payload = await response.json().catch(() => ({}));
if (response.status !== 200) throw new Error(`status failed: ${response.status} ${JSON.stringify(payload)}`);

const summary = payload.data;
console.log(summary.status, summary.payment_status, summary.fulfillment.state, summary.delivery.state);
console.log("released:", summary.fulfillment.released_units, "of", summary.fulfillment.ordered_units);
console.log("refund:", summary.refund.state, summary.refund.confirmed_amount);
CODE;
    }

    private function orderStatusPhp(): string
    {
        return <<<'CODE'
<?php

$origin = rtrim((string) getenv('STORE_ORIGIN'), '/');
$key = (string) getenv('CUSTOMER_API_KEY');
$orderId = (string) getenv('ORDER_ID');

$curl = curl_init($origin.'/api/v1/orders/'.$orderId);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key, 'Accept: application/json'],
]);
$raw = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
curl_close($curl);
if ($raw === false) {
    exit('transport: '.$error);
}
$payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
if ($status !== 200) {
    exit('status failed: '.$status.' '.json_encode($payload));
}

$summary = $payload['data'];
printf("%s %s %s %s\n", $summary['status'], $summary['payment_status'], $summary['fulfillment']['state'], $summary['delivery']['state']);
printf("released: %d of %d\n", $summary['fulfillment']['released_units'], $summary['fulfillment']['ordered_units']);
printf("refund: %s %s\n", $summary['refund']['state'], var_export($summary['refund']['confirmed_amount'], true));
CODE;
    }

    private function deliveryCurl(): string
    {
        return <<<'CODE'
# Recovery read. `not_ready` and `partial` are normal 200 answers, not errors.
# When `pagination.has_more` is true, request the same path with
# `&cursor=${pagination.next_cursor}` until it is false. Each unit appears once.
: "${STORE_ORIGIN:?export STORE_ORIGIN first}"
: "${CUSTOMER_API_KEY:?export CUSTOMER_API_KEY first}"
: "${ORDER_ID:?export ORDER_ID first}"

curl --fail-with-body --silent --show-error --connect-timeout 5 --max-time 15 \
  --header "Authorization: Bearer ${CUSTOMER_API_KEY}" \
  --header 'Accept: application/json' \
  "${STORE_ORIGIN}/api/v1/orders/${ORDER_ID}/delivery"
CODE;
    }

    private function deliveryPython(): string
    {
        return <<<'CODE'
import json, os, time, urllib.error, urllib.request

ORIGIN = os.environ["STORE_ORIGIN"].rstrip("/")
KEY = os.environ["CUSTOMER_API_KEY"]
ORDER_ID = os.environ["ORDER_ID"]


def call(path: str) -> tuple[int, dict]:
    request = urllib.request.Request(
        ORIGIN + path,
        headers={"Authorization": f"Bearer {KEY}", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return response.status, json.loads(response.read() or b"{}")
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read() or b"{}")


# Bounded polling: six attempts, 5 seconds apart. An existing result never needs a new purchase.
for attempt in range(6):
    status, payload = call(f"/api/v1/orders/{ORDER_ID}/delivery")
    if status != 200:
        raise SystemExit(f"delivery failed: {status} {payload}")

    result = payload["data"]
    if result["state"] == "ready" or attempt == 5:
        break
    time.sleep(5)

# Reuse the first page and follow the continuation cursor. Each unit appears exactly once.
units = list(result["units"])
cursor = result["pagination"]["next_cursor"]
while cursor is not None:
    status, payload = call(f"/api/v1/orders/{ORDER_ID}/delivery?cursor={cursor}")
    if status != 200:
        raise SystemExit(f"delivery failed: {status} {payload}")

    page = payload["data"]
    units.extend(page["units"])
    cursor = page["pagination"]["next_cursor"] if page["pagination"]["has_more"] else None

print("state:", result["state"], "progress:", result["progress"]["state"], "units:", len(units))
for unit in units:
    print(unit["number"], unit["format"], unit["text"])
for guide in result["usage_guides"]:
    print("guide:", guide["title"], guide["url"])
CODE;
    }

    private function deliveryJavaScript(): string
    {
        return <<<'CODE'
const origin = process.env.STORE_ORIGIN.replace(/\/+$/, "");
const key = process.env.CUSTOMER_API_KEY;
const orderId = process.env.ORDER_ID;

const read = async (cursor) => {
  const suffix = cursor === undefined ? "" : `?cursor=${cursor}`;
  const response = await fetch(`${origin}/api/v1/orders/${orderId}/delivery${suffix}`, {
    signal: AbortSignal.timeout(15_000),
    headers: { Authorization: `Bearer ${key}`, Accept: "application/json" },
  });
  const payload = await response.json().catch(() => ({}));
  if (response.status !== 200) throw new Error(`delivery failed: ${response.status} ${JSON.stringify(payload)}`);
  return payload.data;
};

// Bounded polling: six attempts, 5 seconds apart. An existing result never needs a new purchase.
let result = await read();
for (let attempt = 1; attempt < 6 && result.state !== "ready"; attempt += 1) {
  await new Promise((resolve) => setTimeout(resolve, 5_000));
  result = await read();
}

// Traverse every bounded page. Each unit appears exactly once.
const units = [];
let cursor;
do {
  const page = cursor === undefined ? result : await read(cursor);
  units.push(...page.units);
  cursor = page.pagination.has_more ? page.pagination.next_cursor : undefined;
} while (cursor !== undefined);

console.log("state:", result.state, "progress:", result.progress.state, "units:", units.length);
for (const unit of units) console.log(unit.number, unit.format, unit.text);
for (const guide of result.usage_guides) console.log("guide:", guide.title, guide.url);
CODE;
    }

    private function deliveryPhp(): string
    {
        return <<<'CODE'
<?php

$origin = rtrim((string) getenv('STORE_ORIGIN'), '/');
$key = (string) getenv('CUSTOMER_API_KEY');
$orderId = (string) getenv('ORDER_ID');

$read = function (?string $cursor) use ($origin, $key, $orderId): array {
    $suffix = $cursor === null ? '' : '?cursor='.$cursor;
    $curl = curl_init($origin.'/api/v1/orders/'.$orderId.'/delivery'.$suffix);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key, 'Accept: application/json'],
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) {
        throw new RuntimeException('transport: '.$error);
    }

    return [$status, json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
};

// Bounded polling: six attempts, 5 seconds apart. An existing result never needs a new purchase.
for ($attempt = 0; $attempt < 6; $attempt++) {
    [$status, $payload] = $read(null);
    if ($status !== 200) {
        exit('delivery failed: '.$status.' '.json_encode($payload));
    }
    $result = $payload['data'];
    if ($result['state'] === 'ready' || $attempt === 5) {
        break;
    }
    sleep(5);
}

// Traverse every bounded page. Each unit appears exactly once.
$units = [];
$cursor = null;
$first = true;
while (true) {
    if ($first) {
        $page = $result;
        $first = false;
    } else {
        [$status, $payload] = $read($cursor);
        if ($status !== 200) {
            exit('delivery failed: '.$status.' '.json_encode($payload));
        }
        $page = $payload['data'];
    }
    $units = array_merge($units, $page['units']);
    if (! $page['pagination']['has_more']) {
        break;
    }
    $cursor = $page['pagination']['next_cursor'];
}

printf("state: %s progress: %s units: %d\n", $result['state'], $result['progress']['state'], count($units));
foreach ($units as $unit) {
    printf("%d %s %s\n", $unit['number'], $unit['format'], $unit['text']);
}
foreach ($result['usage_guides'] as $guide) {
    printf("guide: %s %s\n", $guide['title'], $guide['url']);
}
CODE;
    }
}
