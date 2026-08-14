import { expect, test } from "@playwright/test";
import { spawn, spawnSync } from "node:child_process";
import { closeSync, mkdtempSync, openSync, rmSync } from "node:fs";
import net from "node:net";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const appDir = path.resolve(__dirname, "../..");

let baseUrl;
let dbPath;
let serverProcess;

test.describe.configure({ mode: "serial" });

test.beforeAll(async () => {
  const tempDir = mkdtempSync(path.join(tmpdir(), "telegram-bot-activity-"));
  dbPath = path.join(tempDir, "database.sqlite");
  closeSync(openSync(dbPath, "w"));
  baseUrl = `http://127.0.0.1:${await findAvailablePort()}`;

  runArtisan(["optimize:clear"]);
  runArtisan(["migrate:fresh", "--force"]);
  runArtisan(["tinker", "--execute", seedScript]);

  serverProcess = spawn(
    "php",
    [
      "-S",
      `127.0.0.1:${new URL(baseUrl).port}`,
      "-t",
      path.join(appDir, "public"),
      path.join(__dirname, "laravel-browser-router.php"),
    ],
    { cwd: appDir, env: appEnv(), stdio: ["ignore", "ignore", "ignore"] }
  );

  await waitForServer(`${baseUrl}/`);
});

test.afterAll(async () => {
  if (serverProcess && !serverProcess.killed) serverProcess.kill();
  if (dbPath) rmSync(path.dirname(dbPath), { recursive: true, force: true });
});

test("shows safe verified activity and pauses rotation during inspection", async ({
  page,
}) => {
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const activity = page.getByRole("complementary", {
    name: "Aktivitas pembelian terverifikasi",
  });
  const message = activity.locator("[data-activity-message]");

  await expect(
    activity.getByText("Aktivitas pembelian terverifikasi")
  ).toBeVisible();
  await expect(message).toContainText(/[AB]\*\*\* membeli Produk Aktivitas/);
  await expect(message).not.toContainText("Rp");
  await expect
    .poll(() =>
      page.evaluate(async () => {
        const response = await fetch("/aktivitas-pembelian-terverifikasi");
        return (await response.json()).items.length;
      })
    )
    .toBe(2);

  const initialMessage = await message.textContent();
  await activity.hover();
  await page.waitForTimeout(7_200);
  await expect(message).toHaveText(initialMessage ?? "");

  await activity.dispatchEvent("mouseleave");
  await expect
    .poll(() => message.textContent(), { timeout: 8_000 })
    .not.toBe(initialMessage);
});

test("does not rotate verified activity for reduced-motion users", async ({
  page,
}) => {
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const message = page.locator(
    "#verified-purchase-activity [data-activity-message]"
  );
  await expect(message).toBeVisible();
  const initialMessage = await message.textContent();

  await page.waitForTimeout(7_200);
  await expect(message).toHaveText(initialMessage ?? "");
});

function runArtisan(args) {
  const result = spawnSync("php", ["artisan", ...args], {
    cwd: appDir,
    env: appEnv(),
    encoding: "utf8",
  });

  if (result.status !== 0) {
    throw new Error(
      `php artisan ${args.join(" ")} failed\n${result.stdout}\n${result.stderr}`
    );
  }

  return result.stdout.trim();
}

function appEnv() {
  return {
    ...process.env,
    APP_ENV: "testing",
    APP_DEBUG: "true",
    APP_URL: baseUrl ?? "http://127.0.0.1",
    DB_CONNECTION: "sqlite",
    DB_DATABASE: dbPath,
    CACHE_STORE: "array",
    QUEUE_CONNECTION: "sync",
    SESSION_DRIVER: "array",
  };
}

function findAvailablePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once("error", reject);
    server.listen(0, "127.0.0.1", () => {
      const address = server.address();
      server.close(() => resolve(address.port));
    });
  });
}

async function waitForServer(url) {
  const deadline = Date.now() + 15_000;

  while (Date.now() < deadline) {
    try {
      if ((await fetch(url)).ok) return;
    } catch {
      // The PHP built-in server has not accepted connections yet.
    }

    await new Promise((resolve) => setTimeout(resolve, 250));
  }

  throw new Error(`Timed out waiting for ${url}`);
}

const seedScript = String.raw`
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

$customer = Customer::factory()->create(['first_name' => 'Alya']);

foreach (['Produk Aktivitas Satu', 'Produk Aktivitas Dua'] as $name) {
    $product = Product::factory()->create(['name' => $name]);
    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'order_status' => OrderStatus::Processing,
        'payment_status' => PaymentStatus::Paid,
    ]);
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 1,
    ]);
    InventoryMovement::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'type' => InventoryMovementType::Sale,
        'quantity' => -1,
    ]);
}
`;
