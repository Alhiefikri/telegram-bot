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

test.beforeEach(async ({ page }) => {
  await page.route("**/assets/storefront-banner-test.svg", (route) =>
    route.fulfill({
      contentType: "image/svg+xml",
      body: '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="400"><defs><linearGradient id="bg"><stop stop-color="#dbeafe"/><stop offset="1" stop-color="#eef5ff"/></linearGradient></defs><rect width="1600" height="400" fill="url(#bg)"/><text x="100" y="200" fill="#2563eb" font-family="sans-serif" font-size="60">Banner toko · 1600 × 400</text></svg>',
    })
  );
});

test.afterAll(async () => {
  if (serverProcess && !serverProcess.killed) serverProcess.kill();
  if (dbPath) rmSync(path.dirname(dbPath), { recursive: true, force: true });
});

test("shows verified activity as a temporary toast and pauses exit during inspection", async ({
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
  await expect(activity).toHaveAttribute("data-activity-state", "visible");
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
  await page.waitForTimeout(5_200);
  await expect(activity).toHaveAttribute("data-activity-state", "visible");
  await expect(message).toHaveText(initialMessage ?? "");

  await activity.dispatchEvent("mouseleave");
  await page.waitForFunction(
    () =>
      document.getElementById("verified-purchase-activity")?.dataset
        .activityState === "hidden",
    { timeout: 6_000 }
  );
  await page.waitForFunction(
    () =>
      document.getElementById("verified-purchase-activity")?.dataset
        .activityState === "visible",
    { timeout: 5_000 }
  );
  await expect(message).not.toHaveText(initialMessage ?? "");
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
  await expect(page.locator("#verified-purchase-activity")).toHaveAttribute(
    "data-activity-state",
    "visible"
  );
  await expect(message).toHaveText(initialMessage ?? "");
});

test("carousel supports navigation, pause, reduced motion and mobile layout", async ({
  page,
}, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  const carousel = page.locator(".storefront-banner-carousel");
  const heroBox = await carousel.boundingBox();
  expect(heroBox?.width ?? 0).toBeGreaterThanOrEqual(350);
  const dots = carousel.locator("[data-carousel-dot]");
  await expect(dots).toHaveCount(3);
  await expect(dots.first()).toHaveAttribute("aria-current", "true");
  await page.waitForTimeout(6500);
  await expect(dots.first()).toHaveAttribute("aria-current", "true");
  await carousel.getByRole("button", { name: "Banner berikutnya" }).click();
  await expect(dots.nth(1)).toHaveAttribute("aria-current", "true");
  await carousel
    .getByRole("button", { name: "Jeda pergantian otomatis" })
    .click();
  await expect(carousel.locator("[data-carousel-pause]")).toHaveAttribute(
    "aria-pressed",
    "true"
  );
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth
    )
  ).toBe(true);
  await page.screenshot({
    path: testInfo.outputPath("storefront-mobile.png"),
    fullPage: true,
  });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: "instant" }));
  const desktopHeroBox = await carousel.boundingBox();
  const desktopSlideBox = await carousel
    .locator("[data-carousel-slide]")
    .first()
    .boundingBox();
  expect(desktopHeroBox?.width ?? 0).toBeGreaterThan(1200);
  expect(desktopSlideBox?.height ?? 0).toBeLessThan(320);
  await page.screenshot({
    path: testInfo.outputPath("storefront-desktop.png"),
    fullPage: true,
  });
});

test("carousel advances automatically and pauses on hover", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  const carousel = page.locator(".storefront-banner-carousel");
  await carousel.scrollIntoViewIfNeeded();
  await page.mouse.move(0, 0);
  const dots = carousel.locator("[data-carousel-dot]");
  await expect(dots.nth(1)).toHaveAttribute("aria-current", "true", {
    timeout: 9000,
  });
  await carousel.hover();
  await page.waitForTimeout(6500);
  await expect(dots.nth(1)).toHaveAttribute("aria-current", "true");
});

test("family image stays contained and preview closes with Escape", async ({
  page,
}, testInfo) => {
  await page.emulateMedia({ reducedMotion: "reduce" });
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto(`${baseUrl}/pilihan/preview-family`, {
      waitUntil: "domcontentloaded",
    });
    const image = page.locator(".family-hero-art img");
    await expect(image).toBeVisible();
    await expect
      .poll(() =>
        image.evaluate((node) => node.complete && node.naturalWidth > 0)
      )
      .toBe(true);
    expect(
      await image.evaluate((node) => getComputedStyle(node).objectFit)
    ).toBe("contain");
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth
      )
    ).toBe(true);
    await expect(page.locator("#verified-purchase-activity")).toBeVisible();
    const toastBox = await page
      .locator("#verified-purchase-activity")
      .boundingBox();
    const stickyBox = await page
      .locator("[data-family-quick-buy]")
      .boundingBox();
    expect(toastBox.y + toastBox.height).toBeLessThan(stickyBox.y);
    const button = page.getByRole("button", {
      name: "Perbesar gambar Preview Family",
    });
    await button.click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog")).not.toBeVisible();
    await expect(button).toBeFocused();
    await page.screenshot({
      path: testInfo.outputPath(`family-${width}.png`),
      fullPage: true,
    });
  }
});

test("purchase activity can be dismissed", async ({ page }) => {
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  const activity = page.locator("#verified-purchase-activity");
  await expect(activity).toHaveAttribute("data-activity-state", "visible");
  await activity
    .getByRole("button", { name: "Tutup aktivitas pembelian" })
    .click();
  await expect(activity).not.toBeVisible();
  await expect(activity).toHaveAttribute("data-activity-state", "hidden");
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

\App\Models\StorefrontSetting::current()->update(['homepage_banners' => [
    ['path' => '/assets/storefront-banner-test.svg', 'title' => 'Banner Satu', 'link' => '/katalog'],
    ['path' => '/assets/storefront-banner-test.svg', 'title' => 'Banner Dua', 'link' => null],
    ['path' => '/assets/storefront-banner-test.svg', 'title' => 'Banner Tiga', 'link' => null],
]]);
$customer = Customer::factory()->create(['first_name' => 'Alya']);
$family = \App\Models\ProductFamily::factory()->create(['name' => 'Preview Family', 'slug' => 'preview-family', 'image_path' => '/assets/lhie-store-logo-512.png']);
Product::factory()->create(['name' => 'Preview Package', 'product_family_id' => $family->id, 'family_option_label' => 'Private', 'is_active' => true, 'stock' => 5]);

foreach (['Produk Aktivitas Satu', 'Produk Aktivitas Dua'] as $name) {
    $product = Product::factory()->create(['name' => $name, 'is_active' => true, 'stock' => 5, 'image_path' => '/assets/lhie-store-logo-512.png']);
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
