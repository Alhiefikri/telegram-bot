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
let invoiceFixtures;
let serverProcess;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async () => {
    const tempDir = mkdtempSync(path.join(tmpdir(), 'telegram-bot-admin-responsive-'));
    dbPath = path.join(tempDir, 'database.sqlite');
    closeSync(openSync(dbPath, 'w'));

    baseUrl = `http://127.0.0.1:${await findAvailablePort()}`;

    runArtisan(['optimize:clear']);
    runArtisan(['migrate:fresh', '--force']);
    invoiceFixtures = JSON.parse(runArtisan(['tinker', '--execute', responsiveSeedScript]));

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

test('keeps customer checkout modal keyboard accessible and restores failed state', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });

    const productCard = page.locator('a[data-product]').filter({ hasText: 'Browser QA Produk A' }).first();
    await productCard.scrollIntoViewIfNeeded();
    await productCard.focus();
    await page.keyboard.press('Enter');

    let dialog = page.getByRole('dialog', { name: 'Browser QA Produk A' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[type="radio"][name="payment_method"]').first()).toBeAttached();
    await expectTouchTargetAtLeast44px(dialog.getByLabel('Tutup detail produk'));
    await expectTouchTargetAtLeast44px(dialog.getByLabel('Tambah jumlah'));

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(productCard).toBeFocused();

    await page.keyboard.press('Enter');
    dialog = page.getByRole('dialog', { name: 'Browser QA Produk A' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#storefront-buyer-handle')).toBeFocused();
    await dialog.getByLabel('Tambah jumlah').click();
    await expect(dialog.locator('[aria-live="polite"]')).toHaveText('Jumlah 2. Total produk Rp90.000.');
    await dialog.locator('#storefront-buyer-handle').fill('@browser_buyer');
    await dialog.getByRole('button', { name: /Bayar/i }).click();
    await page.waitForLoadState('domcontentloaded');

    dialog = page.getByRole('dialog', { name: 'Browser QA Produk A' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#storefront-buyer-handle')).toHaveValue('@browser_buyer');
    await expect(dialog.locator('#storefront-buyer-handle')).toBeFocused();
    await expect(dialog.locator('#storefront-checkout-error')).toBeVisible();
    await expectNoDocumentOverflow(page);
});

test('opens the selected product checkout from the landing catalog', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });

    const productCard = page.locator('a[data-product]').filter({ hasText: 'Browser QA Produk A' }).first();
    await productCard.scrollIntoViewIfNeeded();
    await productCard.click();

    const dialog = page.getByRole('dialog', { name: 'Browser QA Produk A' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[name="product_id"]')).toHaveValue(/^\d+$/);
    await expect(dialog.locator('#storefront-buyer-handle')).toBeFocused();
    await expectNoDocumentOverflow(page);
});

test('opens the selected product checkout from a direct product URL', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });

    const productHref = await page.locator('a[data-product]').filter({ hasText: 'Browser QA Produk A' }).first().getAttribute('href');
    const productUrl = new URL(productHref, baseUrl);
    productUrl.searchParams.set('checkout', '1');

    await page.goto(productUrl.toString(), { waitUntil: 'domcontentloaded' });

    const dialog = page.getByRole('dialog', { name: 'Browser QA Produk A' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[name="product_id"]')).toHaveValue(/^\d+$/);
    await expect(page.getByRole('link', { name: 'Kembali ke katalog' })).toHaveAttribute('href', /#product-\d+$/);
    await expectNoDocumentOverflow(page);
});

test('keeps the direct product fallback usable without the checkout modal', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 900 } });
    const page = await context.newPage();

    try {
        await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });
        const productHref = await page.locator('a[data-product]').filter({ hasText: 'Browser QA Produk A' }).first().getAttribute('href');
        const productUrl = new URL(productHref, baseUrl);
        productUrl.searchParams.set('checkout', '1');

        await page.goto(productUrl.toString(), { waitUntil: 'domcontentloaded' });

        await expect(page.getByRole('heading', { name: 'Browser QA Produk A' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Bayar QRIS di Website' })).toBeVisible();
        await expect(page.getByLabel('Jumlah')).toHaveValue('1');
        await expect(page.getByLabel('Nama pembeli')).toBeVisible();
        await expect(page.getByRole('dialog', { name: 'Browser QA Produk A' })).toHaveCount(0);
        await expectNoDocumentOverflow(page);
    } finally {
        await context.close();
    }
});

test('announces terminal QRIS status and exposes buyer next steps', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 900 });
    await page.goto(`${baseUrl}${invoiceFixtures.pendingStatusRefresh.showUrl}`, { waitUntil: 'domcontentloaded' });

    const statusCard = page.locator('[data-shop-payment-status]');
    await expect(statusCard.getByText('Cek status pembayaran', { exact: true }).first()).toBeVisible();

    runArtisan(['tinker', '--execute', `
        $order = App\\Models\\Order::findOrFail('${invoiceFixtures.pendingStatusRefresh.orderId}');
        $order->update([
            'order_status' => App\\Enums\\OrderStatus::Cancelled,
            'payment_status' => App\\Enums\\PaymentStatus::Failed,
        ]);
        App\\Models\\Payment::where('order_id', $order->id)->update(['status' => App\\Enums\\PaymentStatus::Failed]);
    `]);

    await statusCard.getByRole('button', { name: 'Cek status pembayaran' }).click();
    await expect(statusCard.locator('[data-payment-status-title]')).toHaveText('Status pembayaran final');
    await expect(statusCard.locator('[data-payment-status-message]')).toHaveText('Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.');
    await expect(statusCard.locator('[data-payment-status-announcement]')).toHaveText('Status pembayaran final. Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.');
    await expect(statusCard.getByRole('button', { name: 'Cek status pembayaran' })).toBeHidden();
    await expect(statusCard.getByRole('link', { name: 'Belanja lagi' })).toBeVisible();
    await expect(statusCard.getByRole('link', { name: 'Butuh bantuan?' })).toBeVisible();
    await expectNoDocumentOverflow(page);
});

test('prioritizes invoice content by state without mobile overflow', async ({ page }) => {
    for (const width of [320, 390, 768, 1280]) {
        await page.setViewportSize({ width, height: 1000 });

        await page.goto(`${baseUrl}${invoiceFixtures.pendingWithNativeQris.showUrl}`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: 'QRIS invoice' })).toBeVisible();
        await expect(page.getByText('Total bayar QRIS')).toBeVisible();
        await expect(page.getByText('Batas bayar')).toBeVisible();
        await expect(page.getByAltText(/QRIS invoice/)).toBeVisible();
        await expect(page.getByRole('button', { name: 'Cek status pembayaran' })).toBeVisible();
        await expectBefore(page.getByRole('heading', { name: 'QRIS invoice' }), page.locator('section[aria-label="Ringkasan invoice"]'));
        await expectNoDocumentOverflow(page);

        await page.goto(`${baseUrl}${invoiceFixtures.paid.showUrl}`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: 'Data akun' })).toBeVisible();
        await expect(page.getByText('browser-paid@example.test|BrowserPaidSecret')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'QRIS invoice' })).toHaveCount(0);
        await expectBefore(page.getByRole('heading', { name: 'Data akun' }), page.locator('section[aria-label="Ringkasan invoice"]'));
        await expectNoDocumentOverflow(page);

        await page.goto(`${baseUrl}${invoiceFixtures.expired.showUrl}`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: 'Invoice ini tidak bisa dibayar lagi' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Buat order baru' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'QRIS invoice' })).toHaveCount(0);
        await expectBefore(page.getByRole('heading', { name: 'Invoice ini tidak bisa dibayar lagi' }), page.locator('section[aria-label="Ringkasan invoice"]'));
        await expectNoDocumentOverflow(page);

        await page.goto(`${baseUrl}${invoiceFixtures.cancelled.showUrl}`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('heading', { name: 'Invoice ini tidak bisa dibayar lagi' })).toBeVisible();
        await expect(page.getByText('Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Cek status pembayaran' })).toHaveCount(0);
        await expect(page.getByRole('heading', { name: 'QRIS invoice' })).toHaveCount(0);
        await expectNoDocumentOverflow(page);
    }
});

test('stacks shop cards and keeps focus on the active filter at mobile width', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 900 });
    await page.goto(`${baseUrl}/shop`, { waitUntil: 'domcontentloaded' });

    const catalogGrid = page.locator('#storefront-product-grid');
    const productA = catalogGrid.locator('a[data-product]').filter({ hasText: 'Browser QA Produk A' }).first();
    const productB = catalogGrid.locator('a[data-product]').filter({ hasText: 'Browser QA Produk B' }).first();
    await page.waitForTimeout(700);
    await expectOneColumn(productA, productB);

    await productB.focus();
    await expect(productB).toBeFocused();

    const firstCategory = page.getByRole('button', { name: 'Browser Responsive QA', exact: true });
    await firstCategory.evaluate((button) => button.click());
    await expect(firstCategory).toBeFocused();
    await expect(productB).toBeHidden();
    await expectNoDocumentOverflow(page);
});

test('keeps the mobile navigation focus contained and restores its trigger', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto(`${baseUrl}/shop`, { waitUntil: 'domcontentloaded' });

    const trigger = page.getByRole('button', { name: 'Buka menu navigasi' });
    await trigger.focus();
    await page.keyboard.press('Enter');

    const menu = page.getByLabel(/Menu navigasi Lhie Store/);
    const closeButton = menu.getByRole('button', { name: 'Tutup menu' });
    await expect(menu).toBeVisible();
    await expect(closeButton).toBeFocused();

    await page.keyboard.press('Shift+Tab');
    await expect(menu.getByRole('link', { name: /Beranda Lhie Store/ })).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(menu.getByRole('link', { name: 'Akun pelanggan' })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(menu.getByRole('link', { name: /Beranda Lhie Store/ })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(closeButton).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('lets admins reveal and hide the password field from an accessible control', async ({ page }) => {
    await page.goto(`${baseUrl}/admin/login`);

    const password = page.locator('#password');
    await expect(password).toHaveAttribute('type', 'password');

    const showPassword = page.getByRole('button', { name: 'Lihat password' });
    await expect(showPassword).toBeVisible();
    await expect(showPassword).toHaveAttribute('aria-controls', 'password');

    await showPassword.click();
    await expect(password).toHaveAttribute('type', 'text');

    const hidePassword = page.getByRole('button', { name: 'Sembunyikan password' });
    await expect(hidePassword).toBeVisible();
    await expect(hidePassword).toHaveAttribute('aria-controls', 'password');

    await hidePassword.click();
    await expect(password).toHaveAttribute('type', 'password');
    await expect(page.getByRole('button', { name: 'Lihat password' })).toBeVisible();
});

test('serializes the visible admin password field before submitting bogus credentials', async ({ page }) => {
    const bogusEmail = 'missing-admin@example.test';
    const filledPassword = 'definitely-wrong-password';

    await page.goto(`${baseUrl}/admin/login`);
    await page.getByLabel('Username / email admin').fill(bogusEmail);
    await page.locator('input[type="password"]:visible').fill(filledPassword);

    const serializedPayload = await page.locator('form').evaluate((form) => (
        new URLSearchParams(new FormData(form)).toString()
    ));

    expect(serializedPayload).toContain(`email=${encodeURIComponent(bogusEmail)}`);
    expect(serializedPayload).toContain(`password=${encodeURIComponent(filledPassword)}`);

    await page.getByRole('button', { name: /sign in|login|masuk/i }).click();

    await expect(page.getByText('Email atau password admin tidak cocok.')).toBeVisible();
    await expect(page.getByText('Password admin wajib diisi.')).toHaveCount(0);
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
    await expect(page.getByRole('button', { name: 'QR Manual' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Hosted Pakasir' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'QR Native Pakasir' })).toBeVisible();
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
    await expect(page.getByRole('button', { name: 'QR Manual' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Hosted Pakasir' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'QR Native Pakasir' })).toBeVisible();

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
    await manageModal.getByRole('button', { name: 'Ubah akun stok' }).first().click();
    await expect(manageModal.getByRole('textbox')).toBeVisible();
    await manageModal.getByRole('button', { name: 'Batal' }).click();
    await expect(manageModal.getByRole('textbox')).toBeHidden();
    await manageModal.getByRole('button', { name: /Tambah stok baru/ }).click();

    const modal = page.getByRole('dialog', { name: 'Tambah Stok' });
    await expect(modal).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Tambah Stok' })).toBeVisible();
    await expect(modal.getByText('0 item')).toBeVisible();

    await modal.getByPlaceholder('email@example.com|password\nKEY-XXXX-1234').fill('akun-1@example.test\nakun-2@example.test');
    await expect(modal.getByText('2 item')).toBeVisible();

    await expectElementWithinViewport(page, modal);
    await expectTouchTargetAtLeast44px(modal.getByRole('button', { name: 'Batal' }));
    await expectTouchTargetAtLeast44px(modal.getByRole('button', { name: /Simpan/ }));
    await expectNoDocumentOverflow(page);

    await page.goto(`${baseUrl}/admin?tab=penjualan`);
    await expect(page.getByText('INV-BROWSER-A')).toBeVisible();
    await expectOneColumn(page.locator('[data-order-status]:visible').filter({ hasText: 'INV-BROWSER-A' }), page.locator('[data-order-status]:visible').filter({ hasText: 'INV-BROWSER-B' }));
    await expectNoDocumentOverflow(page);
    await expectPrimaryActionsAtLeast44px(page);
});

for (const navClickCase of [
    {
        name: 'desktop/tablet navigation',
        viewport: { width: 1024, height: 900 },
        navigationName: 'Navigasi desktop',
    },
    {
        name: 'Telegram mini app navigation',
        viewport: { width: 375, height: 900 },
        navigationName: 'Navigasi dashboard',
    },
]) {
    test(`switches admin dashboard tabs and opens product creation from ${navClickCase.name} clicks`, async ({ page }) => {
        await page.setViewportSize(navClickCase.viewport);
        await login(page);

        await expectClickedDashboardControlsToWork(page, navClickCase.navigationName);
    });
}

async function expectClickedDashboardControlsToWork(page, navigationName) {
    const nav = page.getByRole('navigation', { name: navigationName });
    await expect(nav).toBeVisible();

    await nav.getByRole('button', { name: 'Produk', exact: true }).click();
    await expect(page.getByRole('heading', { name: /Produk Saya/ })).toBeVisible();
    await expect(page.locator('article:visible').filter({ hasText: 'Browser QA Produk A' })).toBeVisible();

    await nav.getByRole('button', { name: 'Pesanan', exact: true }).click();
    await expect(page.getByRole('heading', { name: /Penjualan Saya/ })).toBeVisible();
    await expect(page.getByText('INV-BROWSER-A')).toBeVisible();

    await nav.getByRole('button', { name: 'Channel', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Telegram Channel' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Setup Store Channel' })).toBeVisible();

    await nav.getByRole('button', { name: 'Tambah', exact: true }).click();
    const productModal = page.getByRole('dialog', { name: /Tambah produk/i });
    await expect(productModal).toBeVisible();
    await expect(productModal.locator('#product-name')).toBeVisible();
    await expect(page.getByRole('heading', { name: /Produk Saya/ })).toBeVisible();
}

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
    await page.getByLabel('Username / email admin').fill('test@example.com');
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
    await manageModal.getByRole('button', { name: 'Ubah akun stok' }).first().click();
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

    const [top, bottom] = first.y <= second.y ? [first, second] : [second, first];
    expect(bottom.y).toBeGreaterThanOrEqual(top.y + top.height - 2);
}

async function expectBefore(firstLocator, secondLocator) {
    const first = await firstLocator.first().boundingBox();
    const second = await secondLocator.first().boundingBox();

    expect(first).not.toBeNull();
    expect(second).not.toBeNull();
    expect(first.y + first.height).toBeLessThanOrEqual(second.y + 2);
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
    expect(Math.ceil(box.height)).toBeGreaterThanOrEqual(44);
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

    return result.stdout.trim();
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
        DOGFOOD_FAKE_TELEGRAM_OUTBOUND: 'true',
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
use Illuminate\Support\Facades\URL;

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

$otherCategory = Category::query()->firstOrCreate(
    ['slug' => 'browser-responsive-other'],
    [
        'name' => 'Browser Responsive Other',
        'description' => 'Second category for storefront filter focus checks.',
        'sort_order' => 100,
        'is_active' => true,
    ],
);

$productA = Product::factory()->create([
    'category_id' => $category->id,
    'name' => 'Browser QA Produk A',
    'price_amount' => 45000,
    'stock' => 9,
    'reserved_stock' => 1,
    'is_active' => true,
]);
ProductStockItem::create(['product_id' => $productA->id, 'secret' => 'browser-stock-a@example.test|pass']);
ProductStockItem::create(['product_id' => $productA->id, 'secret' => 'browser-stock-b@example.test|pass']);

Product::factory()->create([
    'category_id' => $otherCategory->id,
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

$qrisOrder = Order::factory()->create([
    'customer_id' => $customer->id,
    'invoice_number' => 'INV-BROWSER-QRIS',
    'order_status' => OrderStatus::PendingPayment,
    'payment_status' => PaymentStatus::Pending,
    'total_amount' => 45000,
    'checkout_key' => bcrypt('browser-recovery-key'),
    'expires_at' => now()->addMinutes(15),
]);

Payment::factory()->create([
    'order_id' => $qrisOrder->id,
    'provider' => 'pakasir',
    'method' => 'api_qris',
    'provider_payment_method' => 'qris',
    'provider_payment_number' => '00020101021226610016ID.CO.QRIS.BROWSERPENDING99',
    'provider_payment_number_hash' => hash('sha256', '00020101021226610016ID.CO.QRIS.BROWSERPENDING99'),
    'provider_fee' => 350,
    'provider_total_payment' => 45350,
    'amount' => 45000,
    'status' => PaymentStatus::Pending,
    'expired_at' => now()->addMinutes(15),
]);

$statusPendingOrder = Order::factory()->create([
    'customer_id' => $customer->id,
    'invoice_number' => 'INV-BROWSER-STATUS-PENDING',
    'order_status' => OrderStatus::PendingPayment,
    'payment_status' => PaymentStatus::Pending,
    'total_amount' => 45000,
]);
Payment::factory()->create([
    'order_id' => $statusPendingOrder->id,
    'provider' => 'pakasir',
    'method' => 'qris',
    'provider_payment_method' => 'qris',
    'amount' => 45000,
    'status' => PaymentStatus::Pending,
]);

$paidInvoiceOrder = Order::factory()->create([
    'customer_id' => $customer->id,
    'invoice_number' => 'INV-BROWSER-PAID-INVOICE',
    'order_status' => OrderStatus::Processing,
    'payment_status' => PaymentStatus::Paid,
    'total_amount' => 65000,
]);
$paidInvoiceProduct = Product::factory()->create([
    'category_id' => $category->id,
    'name' => 'Browser Paid Invoice Product',
    'price_amount' => 65000,
    'stock' => 1,
    'reserved_stock' => 1,
    'is_active' => true,
]);
$paidInvoiceItem = OrderItem::factory()->create([
    'order_id' => $paidInvoiceOrder->id,
    'product_id' => $paidInvoiceProduct->id,
    'product_name' => $paidInvoiceProduct->name,
    'quantity' => 1,
    'unit_price' => 65000,
    'subtotal' => 65000,
]);
ProductStockItem::create([
    'product_id' => $paidInvoiceProduct->id,
    'order_item_id' => $paidInvoiceItem->id,
    'secret' => 'browser-paid@example.test|BrowserPaidSecret',
]);
Payment::factory()->create([
    'order_id' => $paidInvoiceOrder->id,
    'provider' => 'pakasir',
    'method' => 'api_qris',
    'provider_payment_method' => 'qris',
    'amount' => 65000,
    'status' => PaymentStatus::Paid,
    'paid_at' => now()->subMinutes(10),
]);

$expiredInvoiceOrder = Order::factory()->create([
    'customer_id' => $customer->id,
    'invoice_number' => 'INV-BROWSER-EXPIRED-INVOICE',
    'order_status' => OrderStatus::Expired,
    'payment_status' => PaymentStatus::Expired,
    'total_amount' => 55000,
    'expires_at' => now()->subMinute(),
]);
OrderItem::factory()->create([
    'order_id' => $expiredInvoiceOrder->id,
    'product_name' => 'Browser Expired Product',
    'quantity' => 1,
    'unit_price' => 55000,
    'subtotal' => 55000,
]);
Payment::factory()->create([
    'order_id' => $expiredInvoiceOrder->id,
    'provider' => 'pakasir',
    'method' => 'api_qris',
    'provider_payment_method' => 'qris',
    'amount' => 55000,
    'status' => PaymentStatus::Expired,
    'expired_at' => now()->subMinute(),
]);

$cancelledInvoiceOrder = Order::factory()->create([
    'customer_id' => $customer->id,
    'invoice_number' => 'INV-BROWSER-CANCELLED-INVOICE',
    'order_status' => OrderStatus::Cancelled,
    'payment_status' => PaymentStatus::Failed,
    'total_amount' => 56000,
]);
OrderItem::factory()->create([
    'order_id' => $cancelledInvoiceOrder->id,
    'product_name' => 'Browser Cancelled Product',
    'quantity' => 1,
    'unit_price' => 56000,
    'subtotal' => 56000,
]);
Payment::factory()->create([
    'order_id' => $cancelledInvoiceOrder->id,
    'provider' => 'pakasir',
    'method' => 'api_qris',
    'provider_payment_method' => 'qris',
    'amount' => 56000,
    'status' => PaymentStatus::Failed,
]);

echo json_encode([
    'pendingWithNativeQris' => [
        'orderId' => $qrisOrder->id,
        'showUrl' => URL::signedRoute('storefront.shop.orders.show', ['order' => $qrisOrder, 'key' => 'browser-recovery-key'], null, false),
    ],
    'pendingStatusRefresh' => [
        'orderId' => $statusPendingOrder->id,
        'showUrl' => URL::signedRoute('storefront.shop.orders.show', $statusPendingOrder, null, false),
    ],
    'paid' => [
        'orderId' => $paidInvoiceOrder->id,
        'showUrl' => URL::signedRoute('storefront.shop.orders.show', $paidInvoiceOrder, null, false),
    ],
    'expired' => [
        'orderId' => $expiredInvoiceOrder->id,
        'showUrl' => URL::signedRoute('storefront.shop.orders.show', $expiredInvoiceOrder, null, false),
    ],
    'cancelled' => [
        'orderId' => $cancelledInvoiceOrder->id,
        'showUrl' => URL::signedRoute('storefront.shop.orders.show', $cancelledInvoiceOrder, null, false),
    ],
]);
`;
