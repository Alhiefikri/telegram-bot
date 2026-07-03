import { expect, test } from '@playwright/test';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, openSync, closeSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const appDir = path.resolve(__dirname, '../..');

let baseUrl;
let dbPath;
let serverProcess;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async () => {
    const tempDir = mkdtempSync(path.join(tmpdir(), 'telegram-bot-admin-responsive-'));
    dbPath = path.join(tempDir, 'database.sqlite');
    closeSync(openSync(dbPath, 'w'));

    baseUrl = `http://127.0.0.1:${await findAvailablePort()}`;

    runArtisan(['optimize:clear']);
    runArtisan(['migrate:fresh', '--force']);
    runArtisan(['tinker', '--execute', responsiveSeedScript]);

    serverProcess = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${new URL(baseUrl).port}`], {
        cwd: appDir,
        env: appEnv(),
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    await waitForServer(`${baseUrl}/admin/login`);
});

test.afterAll(async () => {
    if (serverProcess && !serverProcess.killed) {
        serverProcess.kill();
    }

    if (dbPath) {
        rmSync(path.dirname(dbPath), { recursive: true, force: true });
    }
});

test('renders the compact admin dashboard at desktop width', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await installClipboardProbe(page);
    await login(page);

    await expect(page.getByRole('heading', { name: 'Test User' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Navigasi desktop' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Navigasi dashboard' })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Bagikan Toko' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Mode pembayaran' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'QR Manual /payment' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Pakasir' })).toBeVisible();
    await expectStoreLinkCopyAndShare(page);

    await expectNoDocumentOverflow(page);
    await expectPrimaryActionsAtLeast44px(page);

    await page.goto(`${baseUrl}/admin?tab=produk`);
    await exerciseProductManagementModals(page);
    await expectNoDocumentOverflow(page);
});

test('keeps tabs, cards, and the stock modal usable at Telegram mini app width', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 900 });
    await login(page);

    await expectNoDocumentOverflow(page);
    await expectBottomNavUsable(page);
    await page.getByRole('region', { name: 'Mode pembayaran' }).scrollIntoViewIfNeeded();
    await expect(page.getByRole('button', { name: 'QR Manual /payment' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Pakasir' })).toBeVisible();

    await expect(page.getByRole('region', { name: 'Link toko' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Bagikan', exact: true })).toBeVisible();

    await page.goto(`${baseUrl}/admin?tab=produk`);
    await expect(page.locator('article:visible').filter({ hasText: 'Browser QA Produk A' })).toBeVisible();
    await expectOneColumn(page.locator('article:visible').filter({ hasText: 'Browser QA Produk A' }), page.locator('article:visible').filter({ hasText: 'Browser QA Produk B' }));
    await expectNoDocumentOverflow(page);
    await submitProductFromMiniAppModal(page);

    const productCard = page.locator('article:visible').filter({ hasText: 'Browser QA Produk A' });
    await productCard.getByRole('button', { name: 'Kelola' }).click();

    const manageModal = page.getByRole('dialog', { name: 'Kelola Stok' });
    await expect(manageModal).toBeVisible();
    await expect(manageModal.getByText('browser-stock-a@example.test|pass')).toBeVisible();
    await expect(manageModal.getByRole('textbox')).toBeHidden();
    await manageModal.getByRole('button', { name: 'Ubah akun stok' }).click();
    await expect(manageModal.getByRole('textbox')).toBeVisible();
    await manageModal.getByRole('button', { name: 'Batal' }).click();
    await expect(manageModal.getByRole('textbox')).toBeHidden();
    await manageModal.getByRole('button', { name: /Tambah stok baru/ }).click();

    const modal = page.getByRole('dialog', { name: 'Tambah Stok' });
    await expect(modal).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Tambah Stok' })).toBeVisible();
    await expect(page.getByText('0 item')).toBeVisible();

    await page.getByPlaceholder('email@example.com|password\nKEY-XXXX-1234').fill('akun-1@example.test\nakun-2@example.test');
    await expect(page.getByText('2 item')).toBeVisible();

    await expectElementWithinViewport(page, modal);
    await expectTouchTargetAtLeast44px(page.getByRole('button', { name: 'Batal' }));
    await expectTouchTargetAtLeast44px(page.getByRole('button', { name: /Simpan/ }));
    await expectNoDocumentOverflow(page);

    await page.goto(`${baseUrl}/admin?tab=penjualan`);
    await expect(page.getByText('INV-BROWSER-A')).toBeVisible();
    await expectOneColumn(page.locator('[data-order-status]:visible').filter({ hasText: 'INV-BROWSER-A' }), page.locator('[data-order-status]:visible').filter({ hasText: 'INV-BROWSER-B' }));
    await expectNoDocumentOverflow(page);
    await expectPrimaryActionsAtLeast44px(page);
});

async function submitProductFromMiniAppModal(page) {
    const productName = `Mini App Produk ${Date.now()}`;

    await page.getByRole('button', { name: /Tambah Produk/i }).click();
    const productModal = page.getByRole('dialog', { name: /Tambah produk/i });
    await expect(productModal).toBeVisible();
    await productModal.locator('#product-name').fill(productName);
    await productModal.locator('#product-price').fill('33000');
    await productModal.locator('#product-description').fill('Produk dibuat dari viewport Telegram Mini App.');

    await Promise.all([
        page.waitForURL(`${baseUrl}/admin?tab=produk`),
        productModal.getByRole('button', { name: /Submit produk/i }).click(),
    ]);

    await expect(page.getByRole('dialog', { name: /Tambah produk/i })).toBeHidden();
    await expect(page.locator('article:visible').filter({ hasText: productName })).toBeVisible();
    await expect(page.getByText(`Produk ${productName} berhasil dibuat.`)).toBeVisible();
    await expectNoDocumentOverflow(page);
    await expectPrimaryActionsAtLeast44px(page);
}

async function login(page) {
    await page.goto(`${baseUrl}/admin/login`);
    await page.locator('input[type="email"]').fill('test@example.com');
    await page.locator('input[type="password"]').fill('password');
    await page.getByRole('button', { name: /sign in|login|masuk/i }).click();
    await page.waitForURL(`${baseUrl}/admin`);
}

async function installClipboardProbe(page) {
    await page.addInitScript(() => {
        window.__copiedText = null;

        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                async writeText(text) {
                    window.__copiedText = text;
                },
            },
        });

        Object.defineProperty(navigator, 'share', {
            configurable: true,
            value: undefined,
        });
    });
}

async function expectStoreLinkCopyAndShare(page) {
    const storeLink = page.getByRole('region', { name: 'Link toko' });
    const visibleStoreUrl = (await storeLink.locator('p').nth(1).innerText()).trim();

    await storeLink.getByRole('button', { name: 'Salin' }).click();
    await expect(storeLink.getByRole('button', { name: 'Tersalin' })).toBeVisible();
    await expect.poll(() => page.evaluate(() => window.__copiedText)).toBe(visibleStoreUrl);

    await page.getByRole('button', { name: 'Bagikan Toko' }).click();
    await expect(page.locator('header').getByRole('button', { name: 'Tersalin' })).toBeVisible();
    await expect.poll(() => page.evaluate(() => window.__copiedText)).toBe(visibleStoreUrl);
}

async function exerciseProductManagementModals(page) {
    const productCard = page.locator('article:visible').filter({ hasText: 'Browser QA Produk A' });
    await expect(productCard).toBeVisible();

    await page.getByRole('button', { name: /Tambah Produk/i }).click();
    const productModal = page.getByRole('dialog', { name: /Tambah produk/i });
    await expect(productModal).toBeVisible();
    await expectElementWithinViewport(page, productModal);
    await productModal.getByRole('button', { name: 'Tutup modal' }).click();
    await expect(productModal).toBeHidden();

    await productCard.getByRole('button', { name: /Stok/i }).click();
    const stockModal = page.getByRole('dialog', { name: 'Tambah Stok' });
    await expect(stockModal).toBeVisible();
    await expect(stockModal.getByPlaceholder('email@example.com|password\nKEY-XXXX-1234')).toBeVisible();
    await stockModal.getByRole('button', { name: 'Batal' }).click();
    await expect(stockModal).toBeHidden();

    await productCard.getByRole('button', { name: 'Kelola' }).click();
    const manageModal = page.getByRole('dialog', { name: 'Kelola Stok' });
    await expect(manageModal).toBeVisible();
    await expect(manageModal.getByText('#1')).toBeVisible();
    await expect(manageModal.getByText('browser-stock-a@example.test|pass')).toBeVisible();
    await expect(manageModal.getByRole('textbox')).toBeHidden();
    await manageModal.getByRole('button', { name: 'Ubah akun stok' }).click();
    await expect(manageModal.getByRole('textbox')).toBeVisible();
    await manageModal.getByRole('button', { name: 'Batal' }).click();
    await expect(manageModal.getByRole('textbox')).toBeHidden();
    await manageModal.getByRole('button', { name: /Tambah stok baru/ }).click();

    const reopenedStockModal = page.getByRole('dialog', { name: 'Tambah Stok' });
    await expect(reopenedStockModal).toBeVisible();
    await reopenedStockModal.getByRole('button', { name: 'Batal' }).click();
    await expect(reopenedStockModal).toBeHidden();
}

async function expectNoDocumentOverflow(page) {
    const metrics = await page.evaluate(() => ({
        bodyScrollWidth: document.body.scrollWidth,
        documentScrollWidth: document.documentElement.scrollWidth,
        viewportWidth: window.innerWidth,
    }));

    expect(metrics.documentScrollWidth, 'document should not overflow horizontally').toBeLessThanOrEqual(metrics.viewportWidth + 1);
    expect(metrics.bodyScrollWidth, 'body should not overflow horizontally').toBeLessThanOrEqual(metrics.viewportWidth + 1);
}

async function expectBottomNavUsable(page) {
    const nav = page.getByRole('navigation', { name: 'Navigasi dashboard' });
    await expect(nav).toBeVisible();
    await expect(nav).toHaveCSS('position', 'fixed');
    await expect(nav.getByText('Ringkasan')).toBeVisible();
    await expect(nav.getByText('Produk')).toBeVisible();
    await expect(nav.getByText('Pesanan')).toBeVisible();
    await expect(nav.getByText('Tambah')).toBeVisible();

    const metrics = await nav.evaluate((element) => {
        const rect = element.getBoundingClientRect();

        return {
            bottomGap: Math.abs(window.innerHeight - rect.bottom),
            left: rect.left,
            right: rect.right,
            width: rect.width,
            viewportWidth: window.innerWidth,
        };
    });

    expect(metrics.bottomGap).toBeLessThanOrEqual(2);
    expect(metrics.left).toBeGreaterThanOrEqual(0);
    expect(metrics.right).toBeLessThanOrEqual(metrics.viewportWidth + 1);
    expect(metrics.width).toBeGreaterThan(300);
}

async function expectOneColumn(firstLocator, secondLocator) {
    const first = await firstLocator.boundingBox();
    const second = await secondLocator.boundingBox();

    expect(first).not.toBeNull();
    expect(second).not.toBeNull();
    expect(Math.abs(first.x - second.x)).toBeLessThanOrEqual(2);
    expect(second.y).toBeGreaterThan(first.y + first.height - 2);
}

async function expectPrimaryActionsAtLeast44px(page) {
    const tooSmall = await page.locator('button, a[href]').evaluateAll((elements) => elements
        .filter((element) => {
            const style = window.getComputedStyle(element);
            const rect = element.getBoundingClientRect();

            return style.display !== 'none'
                && style.visibility !== 'hidden'
                && rect.width > 0
                && rect.height > 0
                && (element.matches('.admin-dashboard-action') || element.matches('.filter-pill'));
        })
        .map((element) => ({
            label: (element.textContent || element.getAttribute('aria-label') || element.href || element.tagName).trim(),
            height: element.getBoundingClientRect().height,
        }))
        .filter((element) => element.height < 44));

    expect(tooSmall, `brutalist actions below 44px: ${JSON.stringify(tooSmall)}`).toEqual([]);
}

async function expectTouchTargetAtLeast44px(locator) {
    const box = await locator.boundingBox();

    expect(box).not.toBeNull();
    expect(box.height).toBeGreaterThanOrEqual(44);
}

async function expectElementWithinViewport(page, locator) {
    const box = await locator.boundingBox();
    const viewport = page.viewportSize();

    expect(box).not.toBeNull();
    expect(viewport).not.toBeNull();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);
}

function runArtisan(args) {
    const result = spawnSync('php', ['artisan', ...args], {
        cwd: appDir,
        env: appEnv(),
        encoding: 'utf8',
    });

    if (result.status !== 0) {
        throw new Error(`php artisan ${args.join(' ')} failed\n${result.stdout}\n${result.stderr}`);
    }
}

function appEnv() {
    return {
        ...process.env,
        APP_ENV: 'testing',
        APP_DEBUG: 'true',
        APP_URL: baseUrl ?? 'http://127.0.0.1',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: dbPath,
        SESSION_DRIVER: 'file',
        CACHE_STORE: 'array',
        QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'log',
    };
}

function findAvailablePort() {
    return new Promise((resolve, reject) => {
        const server = net.createServer();

        server.once('error', reject);
        server.listen(0, '127.0.0.1', () => {
            const address = server.address();

            server.close(() => resolve(address.port));
        });
    });
}

async function waitForServer(url) {
    const deadline = Date.now() + 15000;
    let lastError;

    while (Date.now() < deadline) {
        try {
            const response = await fetch(url);

            if (response.ok) {
                return;
            }
        } catch (error) {
            lastError = error;
        }

        await new Promise((resolve) => setTimeout(resolve, 250));
    }

    throw new Error(`Timed out waiting for ${url}: ${lastError?.message ?? 'no response'}`);
}

// Test data setup: create one owner plus enough product and order data to measure
// mobile card layout as a real one-column stack rather than inferred from CSS.
const responsiveSeedScript = String.raw`
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

User::query()->updateOrCreate(
    ['email' => 'test@example.com'],
    [
        'name' => 'Test User',
        'email_verified_at' => now(),
        'password' => Hash::make('password'),
        'role' => UserRole::Owner,
    ],
);

$category = Category::query()->firstOrCreate(
    ['slug' => 'browser-responsive-qa'],
    [
        'name' => 'Browser Responsive QA',
        'description' => 'Seed data for admin dashboard responsive browser smoke tests.',
        'sort_order' => 99,
        'is_active' => true,
    ],
);

$productA = Product::factory()->create([
    'category_id' => $category->id,
    'name' => 'Browser QA Produk A',
    'price_amount' => 45000,
    'stock' => 9,
    'reserved_stock' => 2,
    'is_active' => true,
]);
ProductStockItem::create(['product_id' => $productA->id, 'secret' => 'browser-stock-a@example.test|pass']);

Product::factory()->create([
    'category_id' => $category->id,
    'name' => 'Browser QA Produk B',
    'price_amount' => 75000,
    'stock' => 6,
    'reserved_stock' => 1,
    'is_active' => true,
]);

$customer = Customer::factory()->create([
    'first_name' => 'Browser',
    'username' => 'browserbuyer',
]);

$firstOrder = Order::factory()->create([
    'customer_id' => $customer->id,
    'invoice_number' => 'INV-BROWSER-A',
    'order_status' => OrderStatus::Processing,
    'payment_status' => PaymentStatus::Paid,
    'total_amount' => 45000,
    'created_at' => now()->subHour(),
]);

OrderItem::factory()->create([
    'order_id' => $firstOrder->id,
    'product_id' => null,
    'product_name' => 'Browser QA Produk A',
    'quantity' => 1,
    'unit_price' => 45000,
    'subtotal' => 45000,
]);

Payment::factory()->create([
    'order_id' => $firstOrder->id,
    'status' => PaymentStatus::Paid,
    'amount' => 45000,
    'paid_at' => now()->subMinutes(45),
]);

$secondOrder = Order::factory()->create([
    'customer_id' => $customer->id,
    'invoice_number' => 'INV-BROWSER-B',
    'order_status' => OrderStatus::Completed,
    'payment_status' => PaymentStatus::Paid,
    'total_amount' => 75000,
    'created_at' => now()->subHours(2),
]);

OrderItem::factory()->create([
    'order_id' => $secondOrder->id,
    'product_id' => null,
    'product_name' => 'Browser QA Produk B',
    'quantity' => 1,
    'unit_price' => 75000,
    'subtotal' => 75000,
]);

Payment::factory()->create([
    'order_id' => $secondOrder->id,
    'status' => PaymentStatus::Paid,
    'amount' => 75000,
    'paid_at' => now()->subHours(2),
]);
`;
