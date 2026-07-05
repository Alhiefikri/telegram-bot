import { expect, test } from '@playwright/test';

const baseUrl = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:18080';
const adminEmail = process.env.PLAYWRIGHT_ADMIN_EMAIL;
const adminPassword = process.env.PLAYWRIGHT_ADMIN_PASSWORD;
const telegramWebhookSecret = process.env.PLAYWRIGHT_TELEGRAM_WEBHOOK_SECRET;

test.describe('Docker production-like smoke', () => {
    test('serves healthcheck and storefront through the configured base URL', async ({ page }) => {
        await page.goto(`${baseUrl}/up`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('body')).not.toHaveText('');

        await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('body')).toContainText('ChatGPT Plus');
    });

    test('keeps admin login and dashboard usable through Docker/Nginx', async ({ page }) => {
        test.skip(!adminEmail || !adminPassword, 'Set PLAYWRIGHT_ADMIN_EMAIL and PLAYWRIGHT_ADMIN_PASSWORD for Docker admin smoke.');

        await page.setViewportSize({ width: 1280, height: 900 });
        await login(page);

        await expect(page.getByRole('region', { name: 'Mode pembayaran' })).toBeVisible();

        await page.goto(`${baseUrl}/admin?tab=produk`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('button', { name: /Tambah Produk/i })).toBeVisible();

        await page.setViewportSize({ width: 375, height: 900 });
        await page.goto(`${baseUrl}/admin?tab=produk`, { waitUntil: 'domcontentloaded' });
        await expect(page.getByRole('button', { name: /Tambah Produk/i })).toBeVisible();
        await expectNoHorizontalOverflow(page);
    });

    test('fails closed for unauthenticated admin and malformed Telegram webhook traffic', async ({ request }) => {
        const adminResponse = await request.get(`${baseUrl}/admin`, { maxRedirects: 0 });
        expect(adminResponse.status()).toBe(302);
        expect(adminResponse.headers().location).toContain('/admin/login');

        const wrongSecretResponse = await request.post(`${baseUrl}/api/webhook/telegram`, {
            headers: {
                'content-type': 'application/json',
                'X-Telegram-Bot-Api-Secret-Token': 'wrong-secret',
            },
            data: telegramMessageUpdate(950001, '/start'),
        });
        expect(wrongSecretResponse.status()).toBe(403);

        test.skip(!telegramWebhookSecret, 'Set PLAYWRIGHT_TELEGRAM_WEBHOOK_SECRET to verify valid-secret payload validation.');

        const invalidPayloadResponse = await request.post(`${baseUrl}/api/webhook/telegram`, {
            headers: {
                'content-type': 'application/json',
                'X-Telegram-Bot-Api-Secret-Token': telegramWebhookSecret,
            },
            data: { update_id: 950002, edited_message: {} },
        });
        expect(invalidPayloadResponse.status()).toBe(422);
    });
});

async function login(page) {
    await page.goto(`${baseUrl}/admin/login`, { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Username / email admin').fill(adminEmail);
    await page.locator('input[type="password"]').fill(adminPassword);
    await page.getByRole('button', { name: /sign in|login|masuk/i }).click();
    await page.waitForURL(`${baseUrl}/admin`, { timeout: 10_000 });
}

async function expectNoHorizontalOverflow(page) {
    const hasOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(hasOverflow).toBe(false);
}

function telegramMessageUpdate(updateId, text) {
    return {
        update_id: updateId,
        message: {
            message_id: updateId,
            chat: { id: updateId },
            from: { id: updateId, first_name: 'Docker Smoke' },
            text,
        },
    };
}
