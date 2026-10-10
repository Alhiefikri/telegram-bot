import { expect, test } from "@playwright/test";
import { spawn, spawnSync } from "node:child_process";
import {
  mkdtempSync,
  mkdirSync,
  openSync,
  closeSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import net from "node:net";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const appDir = path.resolve(__dirname, "../..");

let baseUrl;
let dbPath;
let invoiceFixtures;
let serverProcess;

test.describe.configure({ mode: "serial" });

const customerCheckoutViewports = [
  { width: 320, height: 568 },
  { width: 390, height: 844 },
  { width: 768, height: 900 },
  { width: 1280, height: 900 },
  { width: 1440, height: 900 },
  { width: 1920, height: 1080 },
];
const customerCheckoutTestPrefix = "customer checkout:";

const customerBalanceLoginToken = "a".repeat(64);

test.beforeAll(async () => {
  const tempDir = mkdtempSync(
    path.join(tmpdir(), "telegram-bot-admin-responsive-")
  );
  dbPath = path.join(tempDir, "database.sqlite");
  closeSync(openSync(dbPath, "w"));

  baseUrl = `http://127.0.0.1:${await findAvailablePort()}`;

  runArtisan(["optimize:clear"]);
  resetResponsiveFixtures();

  await startBrowserServer();
});

// Playwright isolates cookies and storage in each test context, but the PHP
// server intentionally shares one SQLite database across this serial file.
// Rebuild the database and restart the server before every customer test so
// reservations, checked-out Carts, Orders, payment settings, and in-memory
// response caches cannot leak between flows.
test.beforeEach(async ({}, testInfo) => {
  if (!testInfo.title.startsWith(customerCheckoutTestPrefix)) {
    return;
  }

  await stopBrowserServer();
  resetResponsiveFixtures();
  await startBrowserServer();
});

test.afterAll(async () => {
  await stopBrowserServer();

  if (dbPath) {
    rmSync(path.dirname(dbPath), { recursive: true, force: true });
  }
});

test("storefront navigation: header remains reachable after scrolling", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  await page.evaluate(() => window.scrollTo({ top: 600, behavior: "instant" }));
  expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(100);
  await expect
    .poll(async () =>
      page
        .locator("header")
        .evaluate((header) => header.getBoundingClientRect().top)
    )
    .toBeGreaterThanOrEqual(0);
});

test("theme defaults to light on a dark system and preserves explicit choices", async ({
  page,
}) => {
  await page.emulateMedia({ colorScheme: "dark" });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  await page.evaluate(() => window.localStorage.removeItem("lhie-store-theme"));
  await page.reload({ waitUntil: "domcontentloaded" });

  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");

  const themeToggle = page.locator("[data-theme-toggle]").first();
  await expect(themeToggle).toBeVisible();

  const initialTheme = await page.locator("html").getAttribute("data-theme");
  expect(["light", "dark"]).toContain(initialTheme);
  const initialBackground = await page
    .locator("body")
    .evaluate((body) => getComputedStyle(body).backgroundColor);

  await themeToggle.click();

  const selectedTheme = await page.locator("html").getAttribute("data-theme");
  expect(selectedTheme).not.toBe(initialTheme);
  const selectedBackground = await page
    .locator("body")
    .evaluate((body) => getComputedStyle(body).backgroundColor);
  expect(selectedBackground).not.toBe(initialBackground);
  await expect(themeToggle).toHaveAttribute(
    "aria-pressed",
    selectedTheme === "dark" ? "true" : "false"
  );
  await expect
    .poll(() => page.evaluate(() => localStorage.getItem("lhie-store-theme")))
    .toBe(selectedTheme);

  await page.reload({ waitUntil: "domcontentloaded" });
  await expect(page.locator("html")).toHaveAttribute(
    "data-theme",
    selectedTheme
  );
});

test("admin dashboard exposes the shared theme preference control", async ({
  page,
}) => {
  await login(page);
  const balanceReportsLink = page.getByRole("link", {
    name: "Buka laporan saldo",
  });
  await expect(balanceReportsLink).toBeVisible();
  await expect(balanceReportsLink).toHaveAttribute(
    "href",
    /\/admin\/store-balance\/reports/
  );

  await expect(
    page.locator('script[type="module"][src*="/assets/app-"]')
  ).toHaveCount(1);
  await expect(
    page.locator('script[type="module"][src*="/assets/storefront-entry-"]')
  ).toHaveCount(0);

  const themeToggle = page.locator("[data-theme-toggle]").first();
  await expect(themeToggle).toBeVisible();

  const initialTheme = await page.locator("html").getAttribute("data-theme");
  const initialBackground = await page
    .locator("body")
    .evaluate((body) => getComputedStyle(body).backgroundColor);
  await themeToggle.click();
  await expect(page.locator("html")).toHaveAttribute(
    "data-theme",
    initialTheme === "dark" ? "light" : "dark"
  );
  await expect
    .poll(() =>
      page
        .locator("body")
        .evaluate((body) => getComputedStyle(body).backgroundColor)
    )
    .not.toBe(initialBackground);
});

test("admin balance adjustment previews the exact Customer and disables duplicate confirmation", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await page.getByRole("link", { name: "Kelola saldo" }).click();

  await expect(page).toHaveURL(/\/admin\/store-balance\/adjustments/);
  await page
    .getByLabel("Telegram ID", { exact: true })
    .fill("9007199254740993");
  await page.getByRole("button", { name: "Cari Customer" }).click();
  await expect(
    page.getByRole("region", { name: "Customer ditemukan" })
  ).toBeVisible();
  await expect(
    page.locator('[data-customer-telegram-id="9007199254740993"]')
  ).toHaveText("9007199254740993");

  await page.getByLabel("Jumlah tambahan Rupiah").fill("12345");
  await page
    .getByLabel("Alasan penambahan")
    .fill("Browser QA Owner-attested adjustment.");
  await page.getByRole("button", { name: "Tinjau penambahan" }).click();

  const preview = page.getByRole("region", {
    name: "Preview penambahan saldo",
  });
  await expect(preview).toContainText("@balance_adjustment_qa");
  await expect(preview).toContainText("Rp 12.345");
  await expect(
    page.getByRole("form", {
      name: "Konfirmasi penambahan dengan password Owner",
    })
  ).toBeVisible();

  await page.getByLabel("Password Owner untuk tindakan ini").fill("password");

  const confirmationForm = page.getByRole("form", {
    name: "Konfirmasi penambahan dengan password Owner",
  });
  const confirmButton = confirmationForm.locator('button[type="submit"]');
  await confirmationForm.evaluate((form) => {
    form.submit = () => {
      window.__balanceAdjustmentSubmitDisabled = form.querySelector(
        'button[type="submit"]'
      ).disabled;
    };
  });
  await confirmButton.click();
  await expect
    .poll(() => page.evaluate(() => window.__balanceAdjustmentSubmitDisabled))
    .toBe(true);
  await expect(confirmButton).toBeDisabled();

  await page.reload();
  await page.getByLabel("Password Owner untuk tindakan ini").fill("password");
  await page.getByRole("button", { name: "Konfirmasi penambahan" }).click();

  await expect(
    page.getByRole("status").filter({ hasText: "Penambahan saldo diterapkan" })
  ).toBeVisible();
  await expectNoDocumentOverflow(page);
});

test("asset isolation: Storefront 404 retains its Storefront entry and theme", async ({
  page,
}) => {
  const response = await page.goto(
    `${baseUrl}/__entrypoint-smoke-not-found__`,
    {
      waitUntil: "domcontentloaded",
    }
  );
  expect(response?.status()).toBe(404);
  await expect(
    page.getByRole("heading", { name: "Halaman ini tidak ditemukan." })
  ).toBeVisible();
  await expect(
    page.locator('script[type="module"][src*="/assets/storefront-entry-"]')
  ).toHaveCount(1);
  await expect(
    page.locator('script[type="module"][src*="/assets/app-"]')
  ).toHaveCount(0);

  const initialTheme = await page.locator("html").getAttribute("data-theme");
  await page.locator("[data-theme-toggle]").first().click();
  await expect(page.locator("html")).toHaveAttribute(
    "data-theme",
    initialTheme === "dark" ? "light" : "dark"
  );
});

test("storefront navigation: desktop resize releases mobile scroll lock", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  await page.locator("#mobile-menu-btn").click();
  await expect(page.locator("#mobile-menu")).toBeVisible();
  await page.setViewportSize({ width: 1280, height: 900 });
  await expect(page.locator("body")).not.toHaveCSS("overflow-y", "hidden");
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.locator("#mobile-menu-btn")).toHaveAttribute(
    "aria-expanded",
    "false"
  );
  await page.locator("#mobile-menu-btn").click();
  await expect(page.locator("#mobile-menu-close")).toBeFocused();
  await page.keyboard.press("Escape");
  await expect(page.locator("#mobile-menu-btn")).toBeFocused();
});

test("storefront navigation: reopening during close keeps the drawer visible", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  await page.locator("#mobile-menu-btn").click();
  await page.evaluate(() => {
    document.querySelector("#mobile-menu-close").click();
    document.querySelector("#mobile-menu-btn").click();
  });
  await page.waitForTimeout(400);
  await expect(page.locator("#mobile-menu")).toBeVisible();
  await expect(page.locator("#mobile-menu-btn")).toHaveAttribute(
    "aria-expanded",
    "true"
  );
});

test("customer checkout: opens canonical page checkout from the landing catalog", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  await expect(
    page.locator('script[type="module"][src*="/assets/storefront-entry-"]')
  ).toHaveCount(1);
  await expect(
    page.locator('script[type="module"][src*="/assets/app-"]')
  ).toHaveCount(0);

  const checkoutLink = page
    .locator('a[href*="/shop/checkout/products/"]')
    .filter({ hasText: "Beli sekarang" })
    .first();
  await expect(checkoutLink).toBeVisible();
  await checkoutLink.click();

  await expect(page).toHaveURL(/\/shop\/checkout\/products\/\d+/);
  await expect(
    page.locator('script[type="module"][src*="/assets/storefront-entry-"]')
  ).toHaveCount(1);
  await expect(
    page.locator('script[type="module"][src*="/assets/app-"]')
  ).toHaveCount(0);
  await expect(
    page.getByRole("heading", { name: "Review produk sebelum bayar." })
  ).toBeVisible();
  await expect(page.locator("#checkout-form")).toBeVisible();
  await expect(page.locator('input[name="product_id"]')).toBeAttached();
});

test("customer checkout: opens the authenticated balance overview across mobile viewports", async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 568 });
  await page.goto(
    `${baseUrl}/akun/login-link#token=${customerBalanceLoginToken}`,
    { waitUntil: "domcontentloaded" }
  );
  await page.waitForURL(`${baseUrl}/akun/pesanan`);
  await page.getByRole("link", { name: "Saldo saya" }).click();

  await expect(page).toHaveURL(/\/akun\/saldo/);
  await expect(page.getByRole("heading", { name: "Saldo saya" })).toBeVisible();
  await expect(page.getByText("Rp 12.345", { exact: true })).toBeVisible();
  await expect(page.getByText("0 USDT", { exact: true })).toBeVisible();
  await expect(
    page.getByText(
      "Halaman ini hanya-baca. Top-up, pembayaran, dan perubahan saldo tidak tersedia melalui web."
    )
  ).toBeVisible();

  for (const viewport of [
    { width: 320, height: 568 },
    { width: 390, height: 844 },
    { width: 768, height: 900 },
    { width: 1280, height: 900 },
  ]) {
    await page.setViewportSize(viewport);
    await expectNoDocumentOverflow(page);
    await expect(
      page.getByRole("heading", { name: "Saldo saya" })
    ).toBeVisible();
  }

  await page
    .getByRole("navigation", { name: "Pilih mata uang saldo" })
    .getByRole("link", { name: "USDT", exact: true })
    .click();
  await expect(page).toHaveURL(/currency=USDT/);
  await expect(page.getByText("Belum ada aktivitas USDT")).toBeVisible();
  await expectNoDocumentOverflow(page);
});

test("customer checkout: keeps canonical checkout usable without JavaScript", async ({
  browser,
}) => {
  const context = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 390, height: 900 },
  });
  const page = await context.newPage();

  try {
    await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
    const checkoutHref = await page
      .locator('a[href*="/shop/checkout/products/"]')
      .filter({ hasText: "Beli sekarang" })
      .first()
      .getAttribute("href");

    await page.goto(new URL(checkoutHref, baseUrl).toString(), {
      waitUntil: "domcontentloaded",
    });

    await expect(
      page.getByRole("heading", { name: "Review produk sebelum bayar." })
    ).toBeVisible();
    await expect(page.locator("#checkout-form")).toBeVisible();
    await expect(page.locator("[data-checkout-sticky-action]")).toBeHidden();
    await expect(
      page.locator('#checkout-form input[name="product_id"]')
    ).toHaveValue(/^\d+$/);
    await expect(page.locator("#checkout-form")).toHaveAttribute(
      "action",
      /\/shop\/checkout/
    );

    await page
      .getByRole("textbox", { name: "Nama pembeli" })
      .fill("No JavaScript Buyer");
    const terms = page.locator("#checkout-terms-accepted");
    await terms.focus();
    await page.keyboard.press("Space");
    await expect(terms).toBeChecked();
    const submit = page
      .getByRole("region", { name: "Metode pembayaran dipilih" })
      .getByRole("button", { name: /Buat invoice/ });
    await submit.focus();
    await expect(submit).toBeFocused();
    await Promise.all([
      page.waitForURL(/\/shop\/orders\/\d+/, { waitUntil: "domcontentloaded" }),
      page.keyboard.press("Enter"),
    ]);
    await expect(page).toHaveURL(/\/shop\/orders\/\d+/);
    await expect(
      page.getByRole("heading", { name: "Order QRIS dibuat" })
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Invoice QRIS" })
    ).toBeVisible();
  } finally {
    await context.close();
  }
});

test("customer checkout: updates direct tier estimates from bounded safe data", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseUrl}${invoiceFixtures.tierProductCheckoutPath}`, {
    waitUntil: "domcontentloaded",
  });

  const quantity = page.locator("[data-checkout-quantity]");
  const unitPrice = page.locator("[data-checkout-item-unit-price]");
  const itemSubtotal = page.locator("[data-checkout-item-subtotal]");
  const orderSubtotal = page.locator("[data-checkout-order-subtotal]");
  const tierNote = page.locator("[data-checkout-item-tier-note]");

  await expect(quantity).toHaveValue("1");
  await expect(unitPrice).toHaveText(/Rp\s+75\.000/);
  await quantity.fill("2");
  await expect(unitPrice).toHaveText(/Rp\s+70\.000/);
  await expect(itemSubtotal).toHaveText(/Rp\s+140\.000/);
  await expect(orderSubtotal).toHaveText(/Rp\s+140\.000/);
  await expect(tierNote).toHaveText("Harga grosir diterapkan mulai 2 unit.");
  await expect(tierNote).toBeVisible();
});

test("customer checkout: keeps the mobile sticky action aligned with the canonical checkout", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await runArtisan([
    "tinker",
    "--execute",
    `App\\Models\\ManualPaymentSetting::query()->firstOrFail()->update(['binance_pay_enabled' => true]);`,
  ]);

  try {
    await page.goto(`${baseUrl}${invoiceFixtures.longProductCheckoutPath}`, {
      waitUntil: "domcontentloaded",
    });

    const sticky = page.locator("[data-checkout-sticky-action]");
    const stickySubmit = sticky.locator("[data-checkout-sticky-submit]");
    const inFlowSubmit = page.locator(
      "[data-checkout-selected-option] [data-checkout-submit]"
    );

    await expect(sticky).toBeVisible();
    await expect(sticky).toHaveCSS("position", "fixed");
    await expect(sticky).toHaveAttribute(
      "aria-labelledby",
      "checkout-sticky-title"
    );
    await expect(stickySubmit).toHaveAttribute("form", "checkout-form");
    await expect(stickySubmit).toHaveAttribute(
      "aria-controls",
      "checkout-form"
    );
    await expect(sticky).toContainText("QRIS");
    await expect(sticky).toContainText("Subtotal review (IDR)");
    await expect(sticky.locator("[data-checkout-sticky-total]")).toHaveText(
      /Rp\s*88\.000/
    );
    await expect(inFlowSubmit).toBeHidden();
    await expect(stickySubmit).toHaveText(await inFlowSubmit.innerText());

    await page
      .getByRole("textbox", { name: "Nama pembeli" })
      .fill("Sticky Buyer");
    await page.getByRole("checkbox", { name: /Saya menyetujui/ }).check();
    await expect(stickySubmit).toBeEnabled();
    await expect(stickySubmit).toHaveText(await inFlowSubmit.innerText());

    await page.locator("#checkout-payment-disclosure summary").click();
    await page.getByText("Bayar dengan Binance Pay", { exact: true }).click();
    await expect(sticky).toContainText("Binance Pay");
    await expect(sticky).toContainText("Total dasar USDT / estimasi");
    await expect(sticky.locator("[data-checkout-sticky-total]")).toHaveText(
      "2.5 USDT"
    );
    await expect(stickySubmit).toHaveText(/Buat invoice Binance Pay/);
    await expect(stickySubmit).toBeEnabled();

    const metrics = await page.evaluate(() => {
      const sticky = document.querySelector("[data-checkout-sticky-action]");
      const main = document.querySelector("main");
      const stickyRect = sticky?.getBoundingClientRect();
      const mainStyle = main ? getComputedStyle(main) : null;

      return {
        position: sticky ? getComputedStyle(sticky).position : "",
        bottom: stickyRect?.bottom ?? 0,
        viewportHeight: window.innerHeight,
        height: stickyRect?.height ?? 0,
        paddingBottom: mainStyle
          ? Number.parseFloat(mainStyle.paddingBottom)
          : 0,
        documentWidth: Math.max(
          document.documentElement.scrollWidth,
          document.body.scrollWidth
        ),
      };
    });

    expect(metrics.position).toBe("fixed");
    expect(
      Math.abs(metrics.bottom - metrics.viewportHeight)
    ).toBeLessThanOrEqual(2);
    expect(metrics.paddingBottom).toBeGreaterThanOrEqual(metrics.height - 1);
    expect(metrics.documentWidth).toBeLessThanOrEqual(390);

    await scrollCheckoutToSettledBottom(page);
    const bottomMetrics = await page.evaluate(() => {
      const sticky = document.querySelector("[data-checkout-sticky-action]");
      const form = document.querySelector("#checkout-form");
      const stickyTop = sticky?.getBoundingClientRect().top ?? 0;
      const formBottom = form?.getBoundingClientRect().bottom ?? 0;

      return { stickyTop, formBottom };
    });
    expect(bottomMetrics.formBottom).toBeLessThanOrEqual(
      bottomMetrics.stickyTop + 1
    );

    await page.setViewportSize({ width: 1280, height: 900 });
    await expect(sticky).toBeHidden();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(sticky).toBeVisible();

    let checkoutRequests = 0;
    page.on("request", (request) => {
      if (
        request.method() === "POST" &&
        new URL(request.url()).pathname.endsWith("/shop/checkout")
      ) {
        checkoutRequests += 1;
      }
    });
    await Promise.all([
      page.waitForURL(/\/shop\/orders\/\d+/, {
        waitUntil: "domcontentloaded",
      }),
      stickySubmit.click(),
    ]);
    expect(checkoutRequests).toBe(1);
  } finally {
    await runArtisan([
      "tinker",
      "--execute",
      `App\\Models\\ManualPaymentSetting::query()->firstOrFail()->update(['binance_pay_enabled' => false]);`,
    ]);
  }
});

test("customer checkout: switches payment copy and CTA without JavaScript", async ({
  browser,
}) => {
  await runArtisan([
    "tinker",
    "--execute",
    `App\\Models\\ManualPaymentSetting::query()->firstOrFail()->update(['binance_pay_enabled' => true]);`,
  ]);

  const context = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 390, height: 900 },
  });
  const page = await context.newPage();

  try {
    await page.goto(`${baseUrl}${invoiceFixtures.longProductCheckoutPath}`, {
      waitUntil: "domcontentloaded",
    });
    await expect(page.locator("#checkout-form")).toBeVisible();

    const paymentDisclosure = page.locator("#checkout-payment-disclosure");
    await paymentDisclosure.locator("summary").click();
    const binanceRadio = page.getByRole("radio", { name: /Binance Pay/ });
    await page.getByText("Bayar dengan Binance Pay", { exact: true }).click();
    await expect(binanceRadio).toBeChecked();
    await expect(
      page.locator(
        '[data-checkout-payment-option][data-payment-value="binance_pay_id"] [data-checkout-payment-copy]'
      )
    ).toContainText("Total dasar USDT / estimasi");
    await expect(
      page
        .getByRole("region", { name: "Metode pembayaran dipilih" })
        .getByRole("button", { name: /Buat invoice Binance Pay/ })
    ).toBeVisible();
    await expect(
      page
        .getByRole("region", { name: "Metode pembayaran dipilih" })
        .getByRole("button", { name: /Buat invoice QRIS/ })
    ).toBeHidden();
    await page
      .locator('label[for="checkout-payment-option-qris"]')
      .evaluate((label) => label.click());
    await expect(page.locator("#checkout-payment-option-qris")).toBeChecked();
    await expect(
      page
        .getByRole("region", { name: "Metode pembayaran dipilih" })
        .getByRole("button", { name: /Buat invoice QRIS/ })
    ).toBeVisible();
    await expect(
      page
        .getByRole("region", { name: "Metode pembayaran dipilih" })
        .getByRole("button", { name: /Buat invoice Binance Pay/ })
    ).toBeHidden();
  } finally {
    await context.close();
    await runArtisan([
      "tinker",
      "--execute",
      `App\\Models\\ManualPaymentSetting::query()->firstOrFail()->update(['binance_pay_enabled' => false]);`,
    ]);
  }
});

test("customer checkout: keeps storefront card actions separate and keyboard reachable", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const card = page.locator("article.product-card").filter({
    hasText: "Browser QA Produk A",
  });
  await card.evaluate((element) =>
    element.scrollIntoView({
      block: "center",
      inline: "nearest",
      behavior: "instant",
    })
  );
  await expect(card).toBeVisible();
  await expect(card).toHaveCSS("transform", "none");
  await expect(card).toHaveCSS("opacity", "1");
  await expect(card.locator('a[href*="/produk/"]').first()).toBeVisible();
  await expect(card.locator('a[href*="/shop/checkout/products/"]')).toHaveCount(
    1
  );
  await expect(card.locator('form[action*="/shop/cart/items"]')).toHaveCount(1);

  const nestedInteractiveElements = await card.evaluate(
    (element) =>
      Array.from(element.querySelectorAll("a[href], button")).filter(
        (child) => child.parentElement?.closest("a[href], button") !== null
      ).length
  );
  expect(nestedInteractiveElements).toBe(0);

  const actionSizes = await card
    .locator("a[href], button")
    .evaluateAll((elements) =>
      elements.map((element) => {
        const rect = element.getBoundingClientRect();
        return { width: rect.width, height: rect.height };
      })
    );
  expect(
    actionSizes.filter(
      ({ width, height }) => Math.round(width) < 44 || Math.round(height) < 44
    )
  ).toEqual([]);
});

test("customer checkout: keeps Product detail purchase actions reachable on mobile", async ({
  page,
  browser,
}) => {
  await page.setViewportSize({ width: 320, height: 568 });
  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });

  const productCard = page.locator("article.product-card").filter({
    hasText: "Browser QA Produk A",
  });
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    productCard.locator('a[href*="/produk/"]').first().click(),
  ]);

  const sticky = page.locator("[data-product-sticky-action]");
  await expect(sticky).toBeVisible();
  await expect(sticky.locator("[data-product-sticky-submit]")).toHaveAttribute(
    "href",
    /\/shop\/checkout\/products\/\d+/
  );
  await expect(
    page.locator('img[alt="Browser QA Produk A"]').first()
  ).toHaveAttribute("loading", "eager");
  await expect
    .poll(
      () =>
        sticky.evaluate((element) => {
          const rect = element.getBoundingClientRect();
          return Math.abs(rect.bottom - window.innerHeight);
        }),
      { timeout: 1_000 }
    )
    .toBeLessThanOrEqual(1);

  const metrics = await sticky.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    const content = document.querySelector(".storefront-product-content");
    const contentStyle = content ? getComputedStyle(content) : null;

    return {
      bottom: rect.bottom,
      viewportHeight: window.innerHeight,
      height: rect.height,
      position: getComputedStyle(element).position,
      paddingBottom: contentStyle
        ? Number.parseFloat(contentStyle.paddingBottom)
        : 0,
      documentWidth: Math.max(
        document.documentElement.scrollWidth,
        document.body.scrollWidth
      ),
      actionSizes: Array.from(element.querySelectorAll("a[href], button")).map(
        (action) => {
          const actionRect = action.getBoundingClientRect();
          return { width: actionRect.width, height: actionRect.height };
        }
      ),
    };
  });

  expect(metrics.position).toBe("fixed");
  expect(Math.abs(metrics.bottom - metrics.viewportHeight)).toBeLessThanOrEqual(
    1
  );
  expect(metrics.height).toBeGreaterThanOrEqual(44);
  expect(metrics.paddingBottom).toBeGreaterThanOrEqual(metrics.height - 1);
  expect(metrics.documentWidth).toBeLessThanOrEqual(320);
  expect(
    metrics.actionSizes.every(
      ({ width, height }) => Math.round(width) >= 44 && Math.round(height) >= 44
    )
  ).toBe(true);

  const noJsContext = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 320, height: 568 },
  });
  const noJsPage = await noJsContext.newPage();

  try {
    await noJsPage.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    const detailHref = await noJsPage
      .locator("article.product-card")
      .filter({ hasText: "Browser QA Produk A" })
      .locator('a[href*="/produk/"]')
      .first()
      .getAttribute("href");

    await noJsPage.goto(new URL(detailHref, baseUrl).toString(), {
      waitUntil: "domcontentloaded",
    });
    await expect(noJsPage.locator("#product-checkout-cta")).toBeVisible();
    await expect(noJsPage.locator("[data-product-sticky-action]")).toBeHidden();
  } finally {
    await noJsContext.close();
  }
});

test("customer checkout: asserts deterministic catalog checkout invoice navigation without duplicates", async ({
  page,
}) => {
  test.setTimeout(120_000);
  const retiredRequests = observeRetiredCheckoutRequests(page);
  const flow = observeCustomerNavigation(page);

  try {
    await page.setViewportSize({ width: 390, height: 844 });

    let flowStart = flow.requests.length;
    await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
    await navigateToCheckoutFromProductCard(page, "Browser QA Produk A");
    await completeManualCheckout(page, "Navigation Home Buyer");
    expectCustomerNavigationSequence(
      flow,
      flowStart,
      [
        /^GET \/$/,
        /^GET \/shop\/checkout\/products\/\d+$/,
        /^GET \/shop\/orders\/(?:\d+|[0-9a-f-]{36})$/i,
      ],
      "home direct checkout"
    );
    expectSingleCustomerCheckoutSubmission(
      flow,
      flowStart,
      "home direct checkout"
    );

    await page.context().clearCookies();
    flowStart = flow.requests.length;
    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    const productCard = page
      .locator("article.product-card")
      .filter({ hasText: "Browser QA Produk B" })
      .first();
    await productCard.locator('a[href*="/produk/"]').first().click();
    await expect(page).toHaveURL(/\/produk\/\d+/);
    await page.locator("#product-checkout-cta").click();
    await expectStorefrontPageCheckout(page);
    await completeManualCheckout(page, "Navigation Product Buyer");
    expectCustomerNavigationSequence(
      flow,
      flowStart,
      [
        /^GET \/shop$/,
        /^GET \/produk\/\d+$/,
        /^GET \/shop\/checkout\/products\/\d+$/,
        /^GET \/shop\/orders\/(?:\d+|[0-9a-f-]{36})$/i,
      ],
      "Product detail checkout"
    );
    expectSingleCustomerCheckoutSubmission(
      flow,
      flowStart,
      "Product detail checkout"
    );

    await page.context().clearCookies();
    flowStart = flow.requests.length;
    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    const familyCard = page
      .locator("a.family-card")
      .filter({ hasText: "Browser QA Product Family" });
    await expect(familyCard).toBeVisible();
    await familyCard.click();
    await expect(page).toHaveURL(/\/pilihan\/browser-responsive-family/);
    const familyOption = page
      .locator("#family-options article")
      .filter({ hasText: "QA option" });
    const familyCheckoutLink = familyOption.locator(
      'a[href*="/shop/checkout/products/"]'
    );
    await expect(familyCheckoutLink).toHaveCount(1);
    await familyCheckoutLink.click();
    await expectStorefrontPageCheckout(page);
    await completeManualCheckout(page, "Navigation Family Buyer");
    expectCustomerNavigationSequence(
      flow,
      flowStart,
      [
        /^GET \/shop$/,
        /^GET \/pilihan\/browser-responsive-family$/,
        /^GET \/shop\/checkout\/products\/\d+$/,
        /^GET \/shop\/orders\/(?:\d+|[0-9a-f-]{36})$/i,
      ],
      "Family checkout"
    );
    expectSingleCustomerCheckoutSubmission(flow, flowStart, "Family checkout");

    await page.context().clearCookies();
    flowStart = flow.requests.length;
    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    await addProductCardToCart(page, "Browser QA Produk A");
    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    await addProductCardToCart(page, "Browser QA Produk B");
    await page
      .getByRole("link", { name: "Lanjut checkout", exact: true })
      .click();
    await expectStorefrontPageCheckout(page);
    await completeManualCheckout(page, "Navigation Cart Buyer");
    expectCustomerNavigationSequence(
      flow,
      flowStart,
      [
        /^GET \/shop$/,
        /^GET \/shop\/cart$/,
        /^GET \/shop$/,
        /^GET \/shop\/cart$/,
        /^GET \/shop\/checkout$/,
        /^GET \/shop\/orders\/(?:\d+|[0-9a-f-]{36})$/i,
      ],
      "Cart checkout"
    );
    expectSingleCustomerCheckoutSubmission(flow, flowStart, "Cart checkout");
  } finally {
    flow.stop();
  }

  expect(retiredRequests).toEqual([]);
});

test("customer checkout: completes direct purchases from home, shop, Family, and Product detail", async ({
  page,
}) => {
  test.setTimeout(120_000);
  const retiredRequests = observeRetiredCheckoutRequests(page);
  await page.setViewportSize({ width: 390, height: 844 });

  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  await navigateToCheckoutFromProductCard(page, "Browser QA Produk A");
  await completeManualCheckout(page, "Home Direct Buyer");

  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  await navigateToCheckoutFromProductCard(page, "Browser QA Produk B");
  await completeManualCheckout(page, "Shop Direct Buyer");

  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  const familyCard = page
    .locator("a.family-card")
    .filter({ hasText: "Browser QA Product Family" });
  await expect(familyCard).toBeVisible();
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    familyCard.click(),
  ]);
  await expect(page).toHaveURL(/\/pilihan\/browser-responsive-family/);

  const familyOption = page
    .locator("#family-options article")
    .filter({ hasText: "QA option" });
  await expect(familyOption).toBeVisible();
  const familyCheckoutLink = familyOption.locator(
    'a[href*="/shop/checkout/products/"]'
  );
  await expect(familyCheckoutLink).toHaveCount(1);
  await Promise.all([
    page.waitForURL(/\/shop\/checkout\/products\/\d+/, {
      waitUntil: "domcontentloaded",
    }),
    familyCheckoutLink.click(),
  ]);

  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  const productCard = page
    .locator("article.product-card")
    .filter({ hasText: "Browser QA Produk B" })
    .first();
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    productCard.locator('a[href*="/produk/"]').first().click(),
  ]);
  await expect(page).toHaveURL(/\/produk\/\d+/);
  await expect(
    page.getByRole("heading", { name: "Browser QA Produk B" })
  ).toBeVisible();
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    page.locator("#product-checkout-cta").click(),
  ]);
  await expectStorefrontPageCheckout(page);
  await completeManualCheckout(page, "Product Detail Buyer");

  expect(retiredRequests).toEqual([]);
});

test("customer checkout: completes a multi-item Cart purchase through shared review", async ({
  page,
}) => {
  const retiredRequests = observeRetiredCheckoutRequests(page);
  await page.setViewportSize({ width: 390, height: 844 });

  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  await addProductCardToCart(page, "Browser QA Produk A");
  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  await addProductCardToCart(page, "Browser QA Produk B");

  await expect(
    page.locator("[data-cart-item]").filter({ hasText: "Browser QA Produk A" })
  ).toBeVisible();
  await expect(
    page.locator("[data-cart-item]").filter({ hasText: "Browser QA Produk B" })
  ).toBeVisible();
  await expect(
    page.getByRole("link", { name: "Lanjut checkout", exact: true })
  ).toBeVisible();
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    page.getByRole("link", { name: "Lanjut checkout", exact: true }).click(),
  ]);
  await expect(page).toHaveURL(/\/shop\/checkout(?:\?|$)/);
  await expect(
    page.getByRole("heading", { name: "Review pesanan sebelum bayar." })
  ).toBeVisible();
  await expectStorefrontPageCheckout(page);
  await completeManualCheckout(page, "Cart Direct Buyer");

  expect(retiredRequests).toEqual([]);
});

test("customer checkout: restores direct and Cart validation failures on canonical pages", async ({
  page,
}) => {
  const retiredRequests = observeRetiredCheckoutRequests(page);
  await page.setViewportSize({ width: 390, height: 844 });

  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  await navigateToCheckoutFromProductCard(page, "Browser QA Produk A");
  const directCheckoutUrl = page.url();
  await page.getByRole("textbox", { name: "Nama pembeli" }).fill("x");
  await page.getByRole("checkbox", { name: /Saya menyetujui/ }).check();
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    page.locator("[data-checkout-sticky-submit]:visible").click(),
  ]);
  await expect(page).toHaveURL(directCheckoutUrl);
  await expect(page.locator("#checkout-error-summary")).toBeFocused();
  await expect(page.getByRole("textbox", { name: "Nama pembeli" })).toHaveValue(
    "x"
  );
  await expect(
    page.getByRole("checkbox", { name: /Saya menyetujui/ })
  ).toBeChecked();
  await expectStorefrontPageCheckout(page);

  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  await addProductCardToCart(page, "Browser QA Produk A");
  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  await addProductCardToCart(page, "Browser QA Produk B");
  await page
    .getByRole("link", { name: "Lanjut checkout", exact: true })
    .click();
  await expect(page).toHaveURL(/\/shop\/checkout(?:\?|$)/);
  const cartCheckoutUrl = page.url();
  await page.getByRole("textbox", { name: "Nama pembeli" }).fill("x");
  await page.getByRole("checkbox", { name: /Saya menyetujui/ }).check();
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    page.locator("[data-checkout-sticky-submit]:visible").click(),
  ]);
  await expect(page).toHaveURL(cartCheckoutUrl);
  await expect(page.locator("#checkout-error-summary")).toBeFocused();
  await expect(page.getByRole("textbox", { name: "Nama pembeli" })).toHaveValue(
    "x"
  );
  await expect(page.locator('input[name="cart_snapshot"]')).toHaveValue(/.+/);
  await expect(
    page
      .locator("[data-checkout-item]")
      .filter({ hasText: "Browser QA Produk A" })
  ).toBeVisible();
  await expectStorefrontPageCheckout(page);

  expect(retiredRequests).toEqual([]);
});

test("customer checkout: restores provider and stock failures on canonical pages", async ({
  page,
}) => {
  const retiredRequests = observeRetiredCheckoutRequests(page);
  await page.setViewportSize({ width: 390, height: 844 });

  await page.goto(`${baseUrl}${invoiceFixtures.longProductCheckoutPath}`, {
    waitUntil: "domcontentloaded",
  });
  const stockFailureUrl = page.url();
  const longProductId = new URL(stockFailureUrl).pathname.split("/").pop();
  await page
    .getByRole("textbox", { name: "Nama pembeli" })
    .fill("Stock Failure Buyer");
  await page.getByRole("checkbox", { name: /Saya menyetujui/ }).check();
  await runArtisan([
    "tinker",
    "--execute",
    `App\\Models\\Product::query()->findOrFail(${longProductId})->forceFill(['reserved_stock' => 4])->save();`,
  ]);

  try {
    await Promise.all([
      page.waitForNavigation({ waitUntil: "domcontentloaded" }),
      page.locator("[data-checkout-sticky-submit]:visible").click(),
    ]);
    await expect(page).toHaveURL(/\/shop\/checkout\/products\/\d+(?:\?|$)/);
    expect(new URL(page.url()).pathname).toBe(
      new URL(stockFailureUrl).pathname
    );
    await expect(page.locator("#checkout-error-summary")).toBeFocused();
    await expect(page.locator("#checkout-error-summary")).toContainText(
      /stok|berubah|tersedia/i
    );
    await expectStorefrontCheckoutRecovery(page);
  } finally {
    await runArtisan([
      "tinker",
      "--execute",
      `App\\Models\\Product::query()->findOrFail(${longProductId})->forceFill(['stock' => 4, 'reserved_stock' => 0])->save();`,
    ]);
  }

  await page.goto(`${baseUrl}${invoiceFixtures.longProductCheckoutPath}`, {
    waitUntil: "domcontentloaded",
  });
  const providerFailureUrl = page.url();
  await page
    .getByRole("textbox", { name: "Nama pembeli" })
    .fill("Provider Failure Buyer");
  await page.getByRole("checkbox", { name: /Saya menyetujui/ }).check();
  await runArtisan([
    "tinker",
    "--execute",
    `App\\Models\\ManualPaymentSetting::query()->firstOrFail()->update(['is_active' => false]);`,
  ]);

  try {
    await Promise.all([
      page.waitForNavigation({ waitUntil: "domcontentloaded" }),
      page.locator("[data-checkout-sticky-submit]:visible").click(),
    ]);
    expect(new URL(page.url()).pathname).toBe(
      new URL(providerFailureUrl).pathname
    );
    await expect(page.locator("#checkout-error-summary")).toBeFocused();
    await expect(page.locator("#checkout-error-summary")).toContainText(
      /provider|aktif|hubungi admin|checkout/i
    );
    await expectStorefrontCheckoutRecovery(page);
  } finally {
    await runArtisan([
      "tinker",
      "--execute",
      `App\\Models\\ManualPaymentSetting::query()->firstOrFail()->update(['is_active' => true, 'payment_mode' => 'manual', 'binance_pay_enabled' => false]);`,
    ]);
  }

  expect(retiredRequests).toEqual([]);
});

test("customer checkout: restores Product and Family context through browser back", async ({
  page,
}) => {
  const retiredRequests = observeRetiredCheckoutRequests(page);
  await page.setViewportSize({ width: 390, height: 844 });

  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  const productCard = page
    .locator("article.product-card")
    .filter({ hasText: "Browser QA Produk B" })
    .first();
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    productCard.locator('a[href*="/produk/"]').first().click(),
  ]);
  const productUrl = page.url();
  await page.locator("#product-checkout-cta").click();
  await expectStorefrontPageCheckout(page);
  await page.goBack({ waitUntil: "domcontentloaded" });
  await expect(page).toHaveURL(productUrl);
  await expect(
    page.getByRole("heading", { name: "Browser QA Produk B" })
  ).toBeVisible();
  await expect(page.locator("#product-checkout-cta")).toBeVisible();

  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  const familyCard = page
    .locator("a.family-card")
    .filter({ hasText: "Browser QA Product Family" });
  await familyCard.click();
  await expect(page).toHaveURL(/\/pilihan\/browser-responsive-family/);
  const familyUrl = page.url();
  const familyCheckoutLink = page
    .locator("#family-options article")
    .filter({ hasText: "QA option" })
    .locator('a[href*="/shop/checkout/products/"]');
  await expect(familyCheckoutLink).toHaveCount(1);
  await Promise.all([
    page.waitForURL(/\/shop\/checkout\/products\/\d+/, {
      waitUntil: "domcontentloaded",
    }),
    familyCheckoutLink.click(),
  ]);
  await expectStorefrontPageCheckout(page);
  await page.goBack({ waitUntil: "domcontentloaded" });
  await expect(page).toHaveURL(familyUrl);
  await expect(page.getByText("Pilih Plan", { exact: true })).toBeVisible();

  expect(retiredRequests).toEqual([]);
});

test("customer checkout: proves direct and Family review geometry across required viewports", async ({
  page,
}) => {
  test.setTimeout(180_000);
  const retiredRequests = observeRetiredCheckoutRequests(page);

  for (const viewport of customerCheckoutViewports) {
    await page.setViewportSize(viewport);
    await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
    await navigateToCheckoutFromProductCard(page, "Browser QA Produk A");
    await expectStorefrontCheckoutResponsiveContract(page, viewport);

    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    const familyCard = page
      .locator("a.family-card")
      .filter({ hasText: "Browser QA Product Family" });
    await expect(familyCard).toHaveCount(1);
    await expect(familyCard).toBeVisible();
    await familyCard.click({ force: true, noWaitAfter: true });
    await page.waitForLoadState("domcontentloaded");
    await expect(page).toHaveURL(/\/pilihan\/browser-responsive-family/);
    const familyOption = page
      .locator("#family-options article")
      .filter({ hasText: "QA option" });
    const familyCheckoutLink = page
      .locator("#family-options [data-family-direct-checkout]:visible")
      .first();
    await expect(familyCheckoutLink).toHaveCount(1);
    await familyOption
      .locator("[data-family-option-button]")
      .scrollIntoViewIfNeeded();
    await expect(familyOption.locator("[data-family-option-button]")).toHaveCSS(
      "opacity",
      "1"
    );
    await familyCheckoutLink.evaluate((element) => element.click());
    await expectStorefrontPageCheckout(page);
    await expectStorefrontCheckoutResponsiveContract(page, viewport);
  }

  await page.setViewportSize({ width: 320, height: 568 });
  await page.goto(`${baseUrl}${invoiceFixtures.longProductCheckoutPath}`, {
    waitUntil: "domcontentloaded",
  });
  const longProductMetrics = await page
    .locator("[data-checkout-item] h3")
    .evaluate((heading) => ({
      clientWidth: heading.clientWidth,
      scrollWidth: heading.scrollWidth,
      text: heading.textContent,
    }));
  expect(longProductMetrics.text).toContain("Browser QA Produk");
  expect(longProductMetrics.scrollWidth).toBeLessThanOrEqual(
    longProductMetrics.clientWidth + 1
  );
  await expectStorefrontCheckoutResponsiveContract(page, {
    width: 320,
    height: 568,
  });

  expect(retiredRequests).toEqual([]);
});

test("customer checkout: proves Cart review geometry across required viewports", async ({
  page,
}) => {
  test.setTimeout(180_000);
  const retiredRequests = observeRetiredCheckoutRequests(page);

  for (const viewport of customerCheckoutViewports) {
    await page.context().clearCookies();
    await page.setViewportSize(viewport);
    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    await addProductCardToCart(page, "Browser QA Produk A");
    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    await addProductCardToCart(page, "Browser QA Produk B");
    await page
      .getByRole("link", { name: "Lanjut checkout", exact: true })
      .click();
    await expect(page).toHaveURL(/\/shop\/checkout(?:\?|$)/);
    await expectStorefrontPageCheckout(page);
    await expectStorefrontCheckoutResponsiveContract(page, viewport);
  }

  expect(retiredRequests).toEqual([]);
});

test("customer checkout: keeps checkout back links source-aware at mobile widths", async ({
  page,
}) => {
  test.setTimeout(120_000);

  for (const viewport of [
    { width: 320, height: 568 },
    { width: 390, height: 844 },
  ]) {
    await page.context().clearCookies();
    await page.setViewportSize(viewport);

    await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
    const homeCard = page
      .locator("article.product-card")
      .filter({ hasText: "Browser QA Produk B" })
      .first();
    await expect(homeCard).toBeVisible();
    const homeCardId = (await homeCard.getAttribute("id")).replace(
      "product-",
      ""
    );
    const homeCheckoutLink = homeCard.locator(
      'a[href*="/shop/checkout/products/"]'
    );
    await expect(homeCheckoutLink).toHaveCount(1);
    const homeFrom = `${baseUrl}/#product-${homeCardId}`;
    expect(
      new URL(await homeCheckoutLink.getAttribute("href")).searchParams.get(
        "from"
      )
    ).toBe(homeFrom);
    await homeCheckoutLink.click({ noWaitAfter: true });
    await expectStorefrontPageCheckout(page);
    await expect(
      page.getByRole("link", { name: "Kembali ke katalog", exact: true })
    ).toHaveAttribute("href", homeFrom);

    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    const productCard = page
      .locator("article.product-card")
      .filter({ hasText: "Browser QA Produk B" })
      .first();
    await productCard.locator('a[href*="/produk/"]').first().click();
    await expect(page).toHaveURL(/\/produk\/\d+/);
    const productDetailUrl = page.url();
    await page.locator("#product-checkout-cta").click();
    await expectStorefrontPageCheckout(page);
    await expect(
      page.getByRole("link", { name: "Kembali ke produk", exact: true })
    ).toHaveAttribute("href", productDetailUrl);

    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    const familyCard = page
      .locator("a.family-card")
      .filter({ hasText: "Browser QA Product Family" });
    await familyCard.click();
    await expect(page).toHaveURL(/\/pilihan\/browser-responsive-family/);
    const canonicalFamilyUrl = new URL(page.url());
    canonicalFamilyUrl.search = "";
    const familyUrl = canonicalFamilyUrl.toString();
    const familyCheckoutLink = page
      .locator("#family-options article")
      .filter({ hasText: "QA option" })
      .locator('a[href*="/shop/checkout/products/"]')
      .filter({ hasText: "Beli" });
    await expect(familyCheckoutLink).toHaveCount(1);
    expect(
      new URL(await familyCheckoutLink.getAttribute("href")).searchParams.get(
        "from"
      )
    ).toBe(familyUrl);
    await familyCheckoutLink.click({ noWaitAfter: true });
    await expectStorefrontPageCheckout(page);
    await expect(
      page.getByRole("link", { name: "Kembali ke pilihan", exact: true })
    ).toHaveAttribute("href", familyUrl);

    await page.context().clearCookies();
    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    await addProductCardToCart(page, "Browser QA Produk B");
    await page
      .getByRole("link", { name: "Lanjut checkout", exact: true })
      .click();
    await expect(page).toHaveURL(/\/shop\/checkout(?:\?|$)/);
    await expectStorefrontPageCheckout(page);
    await expect(
      page.getByRole("link", { name: "Kembali ke keranjang", exact: true })
    ).toHaveAttribute("href", `${baseUrl}/shop/cart`);
  }
});

test("customer checkout: keeps Family options and sticky purchase reachable at mobile widths", async ({
  page,
}) => {
  test.setTimeout(90_000);

  for (const viewport of [
    { width: 320, height: 568 },
    { width: 390, height: 844 },
  ]) {
    await page.context().clearCookies();
    await page.setViewportSize(viewport);
    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    const familyCard = page
      .locator("a.family-card")
      .filter({ hasText: "Browser QA Product Family" });
    await expect(familyCard).toHaveCount(1);
    await familyCard.click();
    await expect(page).toHaveURL(/\/pilihan\/browser-responsive-family/);

    const optionCard = page
      .locator("#family-options article")
      .filter({ hasText: "QA option" });
    const optionButton = optionCard.locator("[data-family-option-button]");
    const optionDetail = optionCard.locator("[data-family-option-detail]");
    const availabilityBadge = optionDetail
      .locator("span")
      .filter({ hasText: /^Stok:\s*\d+$/ })
      .first();
    const priceChip = optionDetail
      .locator("strong")
      .filter({ hasText: "Rp45.000" })
      .first();
    const buyLink = optionDetail.locator('a[href*="/shop/checkout/products/"]');
    const description = optionDetail.getByText(
      /Paket QA dengan deskripsi panjang/
    );
    const warrantyNote = optionDetail.getByText("Garansi login 7 hari.", {
      exact: true,
    });

    await expect(optionCard).toBeVisible();
    await expect(optionButton).toHaveAttribute("aria-checked", "true");
    await expect(optionDetail).toBeVisible();
    await expect(description).toBeVisible();
    await expect(buyLink).toHaveCount(1);
    await expect(warrantyNote).toBeVisible();
    await expect(optionCard.locator("details")).toHaveCount(0);
    await expect(page.getByText("Butuh bantuan?", { exact: true })).toHaveCount(
      0
    );
    const secondaryCard = page
      .locator("#family-options article")
      .filter({ hasText: "QA secondary" });
    const secondaryButton = secondaryCard.locator(
      "[data-family-option-button]"
    );
    const secondaryDetail = secondaryCard.locator(
      "[data-family-option-detail]"
    );
    await expect(secondaryButton).toHaveAttribute("aria-checked", "false");
    await expect(secondaryDetail).toBeHidden();
    await secondaryButton.click();
    await expect(secondaryButton).toHaveAttribute("aria-checked", "true");
    await expect(optionButton).toHaveAttribute("aria-checked", "false");
    await expect(secondaryDetail).toBeVisible();
    await expect(optionDetail).toBeHidden();
    await expect(
      secondaryDetail.getByText(
        "Deskripsi varian sekunder untuk browser selector coverage."
      )
    ).toBeVisible();
    await optionButton.click();
    await expect(optionButton).toHaveAttribute("aria-checked", "true");
    await expect(secondaryButton).toHaveAttribute("aria-checked", "false");
    await expect(optionDetail).toBeVisible();
    const quickQuantity = page.locator("[data-family-quantity]");
    await quickQuantity.fill("0");
    await quickQuantity.blur();
    await expect(quickQuantity).toHaveValue("1");
    await expect(page.locator("[data-family-checkout]")).toHaveAttribute(
      "href",
      /quantity=1(?:&|$)/
    );

    await settleSmoothScroll(page);

    const documentOverflow = await page.evaluate(
      () =>
        document.documentElement.scrollWidth -
        document.documentElement.clientWidth
    );
    expect(documentOverflow).toBeLessThanOrEqual(1);

    const availabilityBox = await availabilityBadge.boundingBox();
    const priceBox = await priceChip.boundingBox();
    const warrantyBox = await warrantyNote.boundingBox();
    const buyBox = await buyLink.boundingBox();
    const optionButtonBox = await optionButton.boundingBox();
    expect(availabilityBox).not.toBeNull();
    expect(priceBox).not.toBeNull();
    expect(warrantyBox).not.toBeNull();
    expect(buyBox).not.toBeNull();
    expect(optionButtonBox).not.toBeNull();
    expect(optionButtonBox.height).toBeLessThanOrEqual(64);
    expect(availabilityBox.y).toBeGreaterThanOrEqual(optionButtonBox.y);
    expect(priceBox.y).toBeGreaterThanOrEqual(optionButtonBox.y);
    expect(warrantyBox.y).toBeGreaterThanOrEqual(optionButtonBox.y);
    expect(warrantyBox.y).toBeGreaterThanOrEqual(buyBox.y);
    expect(buyBox.y).toBeGreaterThan(optionButtonBox.y);
    expect(Math.round(buyBox.width)).toBeGreaterThanOrEqual(44);
    expect(Math.round(buyBox.height)).toBeGreaterThanOrEqual(44);

    await page.locator("#family-options").evaluate((element) =>
      element.scrollIntoView({
        block: "start",
        inline: "nearest",
        behavior: "instant",
      })
    );
    const stickyCheckout = page.locator("[data-family-checkout]");
    await expect(stickyCheckout).toBeVisible();
    const stickyBox = await stickyCheckout.boundingBox();
    expect(stickyBox).not.toBeNull();
    expect(stickyBox.y).toBeGreaterThanOrEqual(0);
    expect(stickyBox.y + stickyBox.height).toBeLessThanOrEqual(
      viewport.height + 1
    );
  }
});

test("customer checkout: supports keyboard activation, disclosures, reduced motion, and mobile navigation", async ({
  browser,
  page,
}) => {
  test.setTimeout(180_000);
  const retiredRequests = observeRetiredCheckoutRequests(page);
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const keyboardCheckoutLink = page
    .locator('a[href*="/shop/checkout/products/"]')
    .filter({ hasText: "Beli sekarang" })
    .first();
  await keyboardCheckoutLink.focus();
  await expect(keyboardCheckoutLink).toBeFocused();
  await page.keyboard.press("Enter");
  await expectStorefrontPageCheckout(page);

  const buyerName = page.getByRole("textbox", { name: "Nama pembeli" });
  const terms = page.getByRole("checkbox", { name: /Saya menyetujui/ });
  const submit = page.locator("[data-checkout-sticky-submit]:visible");
  await buyerName.focus();
  await page.keyboard.type("Keyboard Buyer");
  await terms.focus();
  await page.keyboard.press("Space");
  await expect(terms).toBeChecked();
  await submit.focus();
  await page.keyboard.press("Enter");
  await expect(page).toHaveURL(/\/shop\/orders\/\d+/);
  await expect(
    page.getByRole("heading", { name: "Invoice QRIS" })
  ).toBeVisible();

  await page.goto(`${baseUrl}${invoiceFixtures.longProductCheckoutPath}`, {
    waitUntil: "domcontentloaded",
  });
  await expectStorefrontPageCheckout(page);
  await runArtisan([
    "tinker",
    "--execute",
    `App\\Models\\ManualPaymentSetting::query()->firstOrFail()->update(['binance_pay_enabled' => true]);`,
  ]);

  try {
    await page.reload({ waitUntil: "domcontentloaded" });
    const paymentDisclosure = page.locator("#checkout-payment-disclosure");
    const contactDisclosure = page.locator("#checkout-contact-details");
    await expect(paymentDisclosure).not.toHaveAttribute("open", "");
    await expect(contactDisclosure).not.toHaveAttribute("open", "");
    await page.getByText("Ubah metode pembayaran", { exact: true }).click();
    await expect(paymentDisclosure).toHaveAttribute("open", "");
    await expect(
      page.getByRole("radio", { name: /Binance Pay/ })
    ).toBeVisible();
    await page
      .locator('label[for="checkout-payment-option-binance_pay_id"]')
      .click();
    await expect(
      page
        .getByRole("region", { name: "Metode pembayaran dipilih" })
        .getByRole("button", { name: /Buat invoice Binance Pay/ })
    ).toBeVisible();
    await page
      .getByText("Tambahkan email atau kontak bantuan", { exact: true })
      .click();
    await expect(contactDisclosure).toHaveJSProperty("open", true);
    await expect(page.getByRole("textbox", { name: "Email" })).toBeVisible();
  } finally {
    await runArtisan([
      "tinker",
      "--execute",
      `App\\Models\\ManualPaymentSetting::query()->firstOrFail()->update(['binance_pay_enabled' => false]);`,
    ]);
  }

  await page.goto(`${baseUrl}${invoiceFixtures.longProductCheckoutPath}`, {
    waitUntil: "domcontentloaded",
  });
  const menuButton = page.getByRole("button", { name: "Buka menu navigasi" });
  await menuButton.click();
  const menu = page.getByLabel("Menu navigasi Lhie Store");
  await expect(menu).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(menu).toBeHidden();
  await expect(menuButton).toBeFocused();
  await expectStorefrontCheckoutResponsiveContract(page, {
    width: 390,
    height: 844,
  });

  const reducedContext = await browser.newContext({
    reducedMotion: "reduce",
    viewport: { width: 390, height: 844 },
  });
  const reducedPage = await reducedContext.newPage();
  try {
    await reducedPage.goto(
      `${baseUrl}${invoiceFixtures.longProductCheckoutPath}`,
      {
        waitUntil: "domcontentloaded",
      }
    );
    const reducedMotion = await reducedPage.evaluate(() => ({
      matches: window.matchMedia("(prefers-reduced-motion: reduce)").matches,
      scrollBehavior: getComputedStyle(document.documentElement).scrollBehavior,
    }));
    expect(reducedMotion.matches).toBe(true);
    expect(reducedMotion.scrollBehavior).toBe("auto");
  } finally {
    await reducedContext.close();
  }

  const zoomContext = await browser.newContext({
    viewport: { width: 390, height: 844 },
  });
  const zoomPage = await zoomContext.newPage();
  let zoomScale;
  try {
    await zoomPage.goto(
      `${baseUrl}${invoiceFixtures.longProductCheckoutPath}`,
      {
        waitUntil: "domcontentloaded",
      }
    );
    zoomScale = await emulate200PercentCheckoutScale(zoomContext, zoomPage, {
      width: 390,
      height: 844,
    });
    await expectStorefrontPageCheckout(zoomPage);

    if (zoomScale.mode === "cdp-page-scale") {
      const pageScaleMetrics = await zoomPage.evaluate(() => ({
        scale: window.visualViewport?.scale ?? 1,
        width: window.visualViewport?.width ?? window.innerWidth,
        height: window.visualViewport?.height ?? window.innerHeight,
      }));
      expect(pageScaleMetrics.scale).toBeCloseTo(2, 2);
      expect(pageScaleMetrics.width).toBeLessThan(390);
      expect(pageScaleMetrics.height).toBeLessThan(844);

      await zoomScale.reset();
      await zoomPage.setViewportSize(zoomScale.fallbackViewport);
    }

    await expectStorefrontPageCheckout(zoomPage);
    await expectStorefrontCheckoutResponsiveContract(
      zoomPage,
      zoomScale.fallbackViewport
    );
  } finally {
    await zoomScale?.reset();
    await zoomContext.close();
  }

  expect(retiredRequests).toEqual([]);
});

test("customer checkout: proves final public URL, SEO, and fallback asset contracts", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const familyCard = page
    .locator("a.family-card")
    .filter({ hasText: "Browser QA Product Family" })
    .first();
  const familyHref = await familyCard.getAttribute("href");
  expect(familyHref).not.toBeNull();

  const canonicalFamilyUrl = new URL(familyHref ?? "", baseUrl);
  canonicalFamilyUrl.search = "";
  canonicalFamilyUrl.hash = "";
  const canonicalFamily = canonicalFamilyUrl.toString();
  const currentSlug = canonicalFamilyUrl.pathname.split("/").at(-1);
  expect(currentSlug).toBe("browser-responsive-family");

  const safeFrom = "/shop?q=release";
  const legacyResponse = await page.request.get(
    `${baseUrl}/produk-keluarga/${currentSlug}?from=${encodeURIComponent(
      safeFrom
    )}&utm_source=legacy`,
    { failOnStatusCode: false, maxRedirects: 0 }
  );
  expect(legacyResponse.status()).toBe(301);
  const legacyLocation = new URL(
    legacyResponse.headers().location ?? "",
    baseUrl
  );
  expect(legacyLocation.pathname).toBe(canonicalFamilyUrl.pathname);
  expect(legacyLocation.searchParams.get("from")).toBe(safeFrom);
  expect(legacyLocation.searchParams.has("utm_source")).toBe(false);

  const unsafeLegacyResponse = await page.request.get(
    `${baseUrl}/produk-keluarga/${currentSlug}?from=${encodeURIComponent(
      "https://evil.example/phish"
    )}`,
    { failOnStatusCode: false, maxRedirects: 0 }
  );
  expect(unsafeLegacyResponse.status()).toBe(301);
  expect(
    new URL(unsafeLegacyResponse.headers().location ?? "", baseUrl).toString()
  ).toBe(canonicalFamily);

  const aliasResponse = await page.request.get(
    `${baseUrl}/produk-keluarga/browser-responsive-legacy`,
    { failOnStatusCode: false, maxRedirects: 0 }
  );
  expect(aliasResponse.status()).toBe(301);
  expect(
    new URL(aliasResponse.headers().location ?? "", baseUrl).toString()
  ).toBe(canonicalFamily);

  const invalidLegacyResponse = await page.request.get(
    `${baseUrl}/produk-keluarga/browser-responsive-missing`,
    { failOnStatusCode: false, maxRedirects: 0 }
  );
  expect(invalidLegacyResponse.status()).toBe(404);
  const invalidCanonicalResponse = await page.request.get(
    `${baseUrl}/pilihan/browser-responsive-missing`,
    { failOnStatusCode: false, maxRedirects: 0 }
  );
  expect(invalidCanonicalResponse.status()).toBe(404);

  const canonicalResponse = await page.request.get(canonicalFamily, {
    failOnStatusCode: false,
    maxRedirects: 0,
  });
  expect(canonicalResponse.status()).toBe(200);

  await page.goto(canonicalFamily, { waitUntil: "domcontentloaded" });
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute(
    "href",
    canonicalFamily
  );
  await expect(page.locator('meta[property="og:url"]')).toHaveAttribute(
    "content",
    canonicalFamily
  );
  const familyJsonLd = await page
    .locator('script[type="application/ld+json"]')
    .evaluateAll((scripts) =>
      scripts.map((script) => JSON.parse(script.textContent || "{}"))
    );
  const collectionPage = familyJsonLd.find(
    (document) => document["@type"] === "CollectionPage"
  );
  const breadcrumbList = familyJsonLd.find(
    (document) => document["@type"] === "BreadcrumbList"
  );
  expect(collectionPage).toEqual(
    expect.objectContaining({ url: canonicalFamily })
  );
  expect(breadcrumbList?.itemListElement?.at(-1)?.item).toBe(canonicalFamily);

  const familyHtml = await page.content();
  const familyHtmlWithoutFixtureName = familyHtml.replaceAll(
    "Browser QA Product Family",
    ""
  );
  for (const prohibitedLabel of [
    "Produk Keluarga",
    "Product Family",
    "Pilih SKU",
    "SKU #",
  ]) {
    expect(familyHtmlWithoutFixtureName).not.toContain(prohibitedLabel);
  }
  expect(familyHtml).not.toContain("/produk-keluarga/");

  const sitemapResponse = await page.request.get(`${baseUrl}/sitemap.xml`, {
    failOnStatusCode: false,
    maxRedirects: 0,
  });
  expect(sitemapResponse.status()).toBe(200);
  expect(sitemapResponse.headers()["content-type"]).toMatch(/application\/xml/);
  const sitemapXml = await sitemapResponse.text();
  expect(sitemapXml).toContain(canonicalFamily);
  expect(sitemapXml).not.toContain("/produk-keluarga/");

  const placeholderResponse = await page.request.get(
    `${baseUrl}/assets/product-placeholder.svg`,
    { failOnStatusCode: false, maxRedirects: 0 }
  );
  expect(placeholderResponse.status()).toBe(200);
  expect(placeholderResponse.headers()["content-type"]).toMatch(
    /^image\/svg\+xml/
  );
  expect((await placeholderResponse.body()).byteLength).toBeGreaterThan(0);

  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  const noImageCard = page
    .locator("article.product-card")
    .filter({ hasText: "Browser QA No Image Product" })
    .first();
  const noImageHref = await noImageCard
    .locator('a[href*="/produk/"]')
    .first()
    .getAttribute("href");
  expect(noImageHref).not.toBeNull();
  await page.goto(new URL(noImageHref ?? "", baseUrl).toString(), {
    waitUntil: "domcontentloaded",
  });
  const noImage = page
    .locator('img[alt="Browser QA No Image Product"]')
    .first();
  await expect(noImage).toBeVisible();
  const fallbackUrl = new URL(
    (await noImage.getAttribute("src")) ?? "",
    baseUrl
  );
  expect(fallbackUrl.pathname).toBe("/assets/product-placeholder.svg");
  await expect(noImage).toHaveAttribute("loading", "eager");
});

test("customer checkout: proves the Storefront release matrix at required viewports", async ({
  page,
}) => {
  test.setTimeout(180_000);

  let productHref;
  let familyHref;
  for (const viewport of customerCheckoutViewports) {
    await page.setViewportSize(viewport);
    await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
    await expect(page.locator("#header-search")).toBeVisible();
    await expect(
      page.locator('header a[href*="/shop/cart"]:visible')
    ).toHaveCount(1);
    await expectNoDocumentOverflow(page);
    await expect(
      page.locator('[data-storefront-carousel][aria-label="Banner toko"]')
    ).toBeVisible();
    await expect(page.locator("#storefront-search")).toBeVisible();

    const menuButton = page.getByRole("button", {
      name: "Buka menu navigasi",
    });
    if (viewport.width < 1024) {
      await expect(menuButton).toBeVisible();
    } else {
      await expect(menuButton).toBeHidden();
    }

    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
    await expectNoDocumentOverflow(page);
    const catalogHeading = page.locator("#products").getByRole("heading", {
      name: "Temukan produk pilihan Anda.",
    });
    await expect(catalogHeading).toBeVisible();
    if (viewport.width === 390) {
      const headingTop = await catalogHeading.evaluate(
        (element) => element.getBoundingClientRect().top + window.scrollY
      );
      expect(headingTop).toBeLessThanOrEqual(720);
    }

    const productCard = page
      .locator("article.product-card")
      .filter({ hasText: "Browser QA Produk A" })
      .first();
    productHref ??= await productCard
      .locator('a[href*="/produk/"]')
      .first()
      .getAttribute("href");
    familyHref ??= await page
      .locator("a.family-card")
      .filter({ hasText: "Browser QA Product Family" })
      .first()
      .getAttribute("href");

    const familyUrl = new URL(familyHref ?? "", baseUrl);
    familyUrl.search = "";
    familyUrl.hash = "";
    await page.goto(familyUrl.toString(), { waitUntil: "domcontentloaded" });
    await expect(page.locator("#family-options")).toBeVisible();
    await expect(
      page.locator("#family-options article").filter({ hasText: "QA option" })
    ).toBeVisible();
    await expect(
      page.locator("#family-options a", { hasText: "Beli sekarang" }).first()
    ).toBeVisible();
    await expectNoDocumentOverflow(page);

    const productUrl = new URL(productHref ?? "", baseUrl);
    productUrl.search = "";
    productUrl.hash = "";
    await page.goto(productUrl.toString(), { waitUntil: "domcontentloaded" });
    await expect(page.locator("#product-title")).toBeVisible();
    await expect(page.locator("#product-checkout-cta")).toBeVisible();
    await expectNoDocumentOverflow(page);

    const sticky = page.locator("[data-product-sticky-action]");
    if (viewport.width < 768) {
      await expect(sticky).toBeVisible();
      await expect
        .poll(
          () =>
            sticky.evaluate((element) =>
              Math.abs(
                element.getBoundingClientRect().bottom - window.innerHeight
              )
            ),
          { timeout: 1_000 }
        )
        .toBeLessThanOrEqual(1);
      const stickyMetrics = await sticky.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        const content = document.querySelector(".storefront-product-content");
        const contentStyle = content ? getComputedStyle(content) : null;
        const actions = Array.from(
          element.querySelectorAll("a[href], button")
        ).map((action) => {
          const actionRect = action.getBoundingClientRect();
          return {
            width: actionRect.width,
            height: actionRect.height,
          };
        });

        return {
          bottom: rect.bottom,
          height: rect.height,
          paddingBottom: contentStyle
            ? Number.parseFloat(contentStyle.paddingBottom)
            : 0,
          actions,
        };
      });
      expect(
        Math.abs(stickyMetrics.bottom - viewport.height)
      ).toBeLessThanOrEqual(1);
      expect(stickyMetrics.height).toBeGreaterThanOrEqual(44);
      expect(stickyMetrics.paddingBottom).toBeGreaterThanOrEqual(
        stickyMetrics.height - 1
      );
      expect(
        stickyMetrics.actions.every(
          ({ width, height }) =>
            Math.round(width) >= 44 && Math.round(height) >= 44
        )
      ).toBe(true);
    } else {
      await expect(sticky).toBeHidden();
    }
  }

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  const menuButton = page.getByRole("button", {
    name: "Buka menu navigasi",
  });
  await menuButton.focus();
  await page.keyboard.press("Enter");
  const menu = page.getByLabel("Menu navigasi Lhie Store");
  await expect(menu).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(menu).toBeHidden();
  await expect(menuButton).toBeFocused();

  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });
  await navigateToCheckoutFromProductCard(page, "Browser QA Produk A");
  await expectStorefrontPageCheckout(page);
  await expect(page.locator('input[name="product_id"]')).toHaveValue(/^\d+$/);
});

test("customer checkout: proves unavailable waitlist and no-JavaScript form fallback", async ({
  browser,
  page,
}) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 320, height: 568 });
  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });

  const unavailableCard = page
    .locator("article.product-card")
    .filter({ hasText: "Browser QA Sold Out Product" })
    .first();
  await expect(unavailableCard).toBeVisible();
  await expect(unavailableCard.getByText("Stok habis")).toBeVisible();
  await expect(
    unavailableCard.locator('a[href*="/shop/checkout/products/"]')
  ).toHaveCount(0);
  const unavailableDetailHref = await unavailableCard
    .locator('a[href*="/produk/"]')
    .first()
    .getAttribute("href");
  expect(unavailableDetailHref).not.toBeNull();

  const noJsContext = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 320, height: 568 },
  });
  const noJsPage = await noJsContext.newPage();
  try {
    await noJsPage.goto(
      new URL(unavailableDetailHref ?? "", baseUrl).toString(),
      {
        waitUntil: "domcontentloaded",
      }
    );
    await expect(noJsPage.locator("#product-checkout-cta")).toHaveCount(0);
    await expect(noJsPage.locator("#waitlist")).toBeVisible();
    await expect(
      noJsPage.getByRole("button", { name: "Kirim permintaan" })
    ).toBeVisible();
    await noJsPage
      .getByLabel("Alamat email")
      .fill("release-waitlist@example.test");
    await Promise.all([
      noJsPage.waitForNavigation({ waitUntil: "domcontentloaded" }),
      noJsPage.getByRole("button", { name: "Kirim permintaan" }).click(),
    ]);
    await expect(noJsPage).toHaveURL(/\/produk\/\d+#waitlist$/);
    await expect(noJsPage.locator("body")).toContainText(
      "Jika alamat email valid"
    );
  } finally {
    await noJsContext.close();
  }
});

test("announces terminal QRIS status and exposes buyer next steps", async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 900 });
  await page.goto(`${baseUrl}${invoiceFixtures.pendingStatusRefresh.showUrl}`, {
    waitUntil: "domcontentloaded",
  });

  const statusCard = page.locator("[data-shop-payment-status]");
  await expect(
    statusCard.getByText("Sedang membuat QRIS", { exact: true }).first()
  ).toBeVisible();
  await expect(
    statusCard.locator("[data-payment-status-announcement]")
  ).toHaveText(
    "Sedang membuat QRIS. Provider sedang menyiapkan QRIS dan nominal final. Jangan bayar sebelum QRIS tampil."
  );

  runArtisan([
    "tinker",
    "--execute",
    `
        $order = App\\Models\\Order::findOrFail('${invoiceFixtures.pendingStatusRefresh.orderId}');
        $order->update([
            'order_status' => App\\Enums\\OrderStatus::Cancelled,
            'payment_status' => App\\Enums\\PaymentStatus::Failed,
        ]);
        App\\Models\\Payment::where('order_id', $order->id)->update(['status' => App\\Enums\\PaymentStatus::Failed]);
    `,
  ]);

  await statusCard.getByRole("button", { name: "Coba cek QRIS" }).click();
  await expect(statusCard.locator("[data-payment-status-title]")).toHaveText(
    "Status pembayaran final"
  );
  await expect(statusCard.locator("[data-payment-status-message]")).toHaveText(
    "Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli."
  );
  await expect(
    statusCard.locator("[data-payment-status-announcement]")
  ).toHaveText(
    "Status pembayaran final. Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli."
  );
  await expect(
    statusCard.getByRole("button", { name: "Coba cek QRIS" })
  ).toBeHidden();
  await expect(
    statusCard.getByRole("link", { name: "Belanja lagi" })
  ).toBeVisible();
  await expect(
    statusCard.getByRole("link", { name: "Butuh bantuan?" })
  ).toBeVisible();
  await expectNoDocumentOverflow(page);
});

test("keeps QRIS retry states clear for incomplete provider payloads", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.route(
    `**${invoiceFixtures.pendingMissingProviderTotal.statusPath}**`,
    async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          ok: false,
          title: "Gagal membuat QRIS",
          message:
            "QRIS belum siap dipakai. Coba buat ulang dari invoice ini atau hubungi bantuan jika tetap gagal.",
        }),
      });
    }
  );

  await page.goto(
    `${baseUrl}${invoiceFixtures.pendingMissingProviderTotal.showUrl}`,
    { waitUntil: "domcontentloaded" }
  );
  const statusCard = page.locator("[data-shop-payment-status]");
  await expect(statusCard.locator("[data-payment-status-title]")).toHaveText(
    "Provider belum memberi nominal"
  );

  await statusCard
    .getByRole("button", { name: "Coba ambil total QRIS" })
    .click();
  await expect(statusCard.locator("[data-payment-status-title]")).toHaveText(
    "Gagal membuat QRIS"
  );
  await expect(statusCard.locator("[data-payment-status-message]")).toHaveText(
    "QRIS belum siap dipakai. Coba buat ulang dari invoice ini atau hubungi bantuan jika tetap gagal."
  );
  await expect(
    statusCard.getByRole("link", { name: "Butuh bantuan?" })
  ).toBeVisible();
});

test("reloads invoice when QRIS retry obtains final provider total", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.route(
    `**${invoiceFixtures.pendingMissingProviderTotal.statusPath}**`,
    async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          ok: true,
          payment_status: "pending",
          order_status: "pending_payment",
          is_paid: false,
          is_terminal: false,
          qris_ready: true,
          should_reload: true,
          redirect_url: `${baseUrl}/shop/orders/reloaded-qris`,
          message:
            "QRIS dan total bayar final sudah siap. Invoice akan dimuat ulang.",
        }),
      });
    }
  );

  await page.goto(
    `${baseUrl}${invoiceFixtures.pendingMissingProviderTotal.showUrl}`,
    { waitUntil: "domcontentloaded" }
  );
  await page
    .locator("[data-shop-payment-status]")
    .getByRole("button", { name: "Coba ambil total QRIS" })
    .click();
  await expect(page).toHaveURL(`${baseUrl}/shop/orders/reloaded-qris`);
});

test("polls active invoices within the budget and stops on hidden or terminal state", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.clock.install({ time: new Date() });

  let calls = 0;
  let releaseSecondRequest;
  const secondRequestReleased = new Promise((resolve) => {
    releaseSecondRequest = resolve;
  });

  await page.route(
    `**${invoiceFixtures.pendingWithNativeQris.statusPath}**`,
    async (route) => {
      calls += 1;
      if (calls === 1) {
        await route.fulfill({
          status: 429,
          headers: { "Retry-After": "30" },
          contentType: "application/json",
          body: JSON.stringify({
            ok: false,
            retry_after: 30,
            message: "Terlalu sering cek status. Coba lagi dalam 30 detik.",
          }),
        });
        return;
      }
      if (calls === 2) {
        await secondRequestReleased;
        await route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({
            ok: true,
            status: "refreshed",
            payment_status: "pending",
            order_status: "pending_payment",
            is_paid: false,
            is_terminal: false,
            qris_ready: true,
            should_reload: false,
            message: "Pembayaran belum masuk.",
          }),
        });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          ok: true,
          status: "terminal",
          payment_status: "failed",
          order_status: "cancelled",
          is_paid: false,
          is_terminal: true,
          qris_ready: false,
          should_reload: false,
          message:
            "Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.",
        }),
      });
    }
  );

  await page.goto(
    `${baseUrl}${invoiceFixtures.pendingWithNativeQris.showUrl}`,
    { waitUntil: "domcontentloaded" }
  );
  const statusCard = page.locator("[data-shop-payment-status]");
  const countdown = page.locator("[data-payment-countdown]");
  await expect(countdown).toHaveAttribute("data-payment-deadline", /\d/);
  await expect(countdown.locator("[data-payment-countdown-value]")).toHaveText(
    /^\d{2}:\d{2}:\d{2}$/
  );

  await page.clock.runFor(18_000);
  expect(calls).toBe(0);
  await page.clock.runFor(3_000);
  await expect.poll(() => calls).toBe(1);
  await expect(statusCard.locator("[data-payment-status-title]")).toHaveText(
    "Terlalu sering cek status"
  );

  await page.evaluate(() => {
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      value: "hidden",
    });
    document.dispatchEvent(new Event("visibilitychange"));
  });
  await page.clock.runFor(10_000);
  await page.evaluate(() => {
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      value: "visible",
    });
    document.dispatchEvent(new Event("visibilitychange"));
  });
  await page.clock.runFor(19_000);
  expect(calls).toBe(1);
  await page.clock.runFor(2_000);
  await expect.poll(() => calls).toBe(2);

  await statusCard.evaluate((element) => {
    element
      .querySelector("[data-payment-status-check]")
      ?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
  });
  expect(calls).toBe(2);
  releaseSecondRequest();
  await expect(statusCard.locator("[data-payment-status-message]")).toHaveText(
    "Pembayaran belum masuk."
  );

  await page.evaluate(() => {
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      value: "hidden",
    });
    document.dispatchEvent(new Event("visibilitychange"));
  });
  await page.clock.runFor(20_000);
  expect(calls).toBe(2);

  await page.evaluate(() => {
    Object.defineProperty(document, "visibilityState", {
      configurable: true,
      value: "visible",
    });
    document.dispatchEvent(new Event("visibilitychange"));
  });
  await page.clock.runFor(20_000);
  await expect.poll(() => calls).toBe(3);
  await expect(statusCard.locator("[data-payment-status-title]")).toHaveText(
    "Status pembayaran final"
  );
  await expect(
    statusCard.getByRole("button", { name: "Cek status pembayaran" })
  ).toBeHidden();
});

test("prioritizes invoice content by state without mobile overflow", async ({
  page,
}) => {
  for (const width of [320, 390, 768, 1280]) {
    await page.setViewportSize({ width, height: 1000 });

    await page.goto(
      `${baseUrl}${invoiceFixtures.pendingWithNativeQris.showUrl}`,
      { waitUntil: "domcontentloaded" }
    );
    await expect(
      page.getByRole("heading", { name: "Invoice QRIS" })
    ).toBeVisible();
    await expect(page.getByText("Total bayar QRIS")).toBeVisible();
    await expect(page.getByText("Batas bayar")).toBeVisible();
    await expect(page.getByText("Sisa waktu pembayaran")).toBeVisible();
    await expect(page.getByAltText(/QRIS invoice/)).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Cek status pembayaran" })
    ).toBeVisible();
    await expectBefore(
      page.getByRole("heading", { name: "Invoice QRIS" }),
      page.locator('section[aria-label="Ringkasan invoice"]')
    );
    await expectNoDocumentOverflow(page);

    await page.goto(
      `${baseUrl}${invoiceFixtures.pendingMissingProviderTotal.showUrl}`,
      { waitUntil: "domcontentloaded" }
    );
    await expect(
      page.getByRole("heading", { name: "Provider belum memberi nominal" })
    ).toBeVisible();
    await expect(page.getByText("Total produk", { exact: true })).toBeVisible();
    await expect(page.getByText("Rp45.000")).toBeVisible();
    await expect(page.getByText("Menunggu provider")).toBeVisible();
    await expect(
      page
        .locator("[data-shop-payment-status]")
        .locator("[data-payment-status-message]")
    ).toContainText("Jangan bayar total produk");
    await expect(page.getByAltText(/QRIS invoice/)).toHaveCount(0);
    await expect(
      page.getByRole("button", { name: "Coba ambil total QRIS" })
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Butuh bantuan?" }).first()
    ).toBeVisible();
    await expectNoDocumentOverflow(page);

    await page.goto(`${baseUrl}${invoiceFixtures.paid.showUrl}`, {
      waitUntil: "domcontentloaded",
    });
    await expect(
      page.getByRole("heading", { name: "Data akun" })
    ).toBeVisible();
    await expect(
      page.getByText("browser-paid@example.test", { exact: true })
    ).toBeVisible();
    await expect(
      page.getByText("BrowserPaidSecret", { exact: true })
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Invoice QRIS" })
    ).toHaveCount(0);
    await expectBefore(
      page.getByRole("heading", { name: "Data akun" }),
      page.locator('section[aria-label="Ringkasan invoice"]')
    );
    await expectNoDocumentOverflow(page);

    await page.goto(`${baseUrl}${invoiceFixtures.expired.showUrl}`, {
      waitUntil: "domcontentloaded",
    });
    await expect(
      page.getByRole("heading", { name: "Invoice ini tidak bisa dibayar lagi" })
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Buat order baru" })
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Invoice QRIS" })
    ).toHaveCount(0);
    await expectBefore(
      page.getByRole("heading", {
        name: "Invoice ini tidak bisa dibayar lagi",
      }),
      page.locator('section[aria-label="Ringkasan invoice"]')
    );
    await expectNoDocumentOverflow(page);

    await page.goto(`${baseUrl}${invoiceFixtures.cancelled.showUrl}`, {
      waitUntil: "domcontentloaded",
    });
    await expect(
      page.getByRole("heading", { name: "Invoice ini tidak bisa dibayar lagi" })
    ).toBeVisible();
    await expect(
      page.getByText(
        "Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli."
      )
    ).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Cek status pembayaran" })
    ).toHaveCount(0);
    await expect(
      page.getByRole("heading", { name: "Invoice QRIS" })
    ).toHaveCount(0);
    await expectNoDocumentOverflow(page);
  }
});

test("uses a compact single-column shop grid at narrow mobile width and keeps filter focus", async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 900 });
  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });

  const catalogGrid = page.locator("#storefront-product-grid");
  const productA = catalogGrid
    .locator('a[href*="/produk/"]')
    .filter({ hasText: "Browser QA Produk A" })
    .first();
  const productB = catalogGrid
    .locator('a[href*="/produk/"]')
    .filter({ hasText: "Browser QA Produk B" })
    .first();
  await page.waitForTimeout(700);
  await expect(productA).toBeVisible();
  await expect(productB).toBeVisible();
  const narrowColumns = await catalogGrid.evaluate(
    (grid) =>
      getComputedStyle(grid).gridTemplateColumns.trim().split(/\s+/).length
  );
  expect(narrowColumns).toBe(1);

  await productB.focus();
  await expect(productB).toBeFocused();

  const firstCategory = page.getByRole("link", {
    name: "Browser Responsive QA",
    exact: true,
  });
  await firstCategory.evaluate((link) => link.click());
  await expect(firstCategory).toBeFocused();
  await expect(productB).toBeHidden();
  await expectNoDocumentOverflow(page);
});

test("balances product columns across storefront breakpoints", async ({
  page,
}) => {
  for (const [width, expectedColumns] of [
    [320, 1],
    [390, 2],
    [768, 3],
    [1024, 4],
    [1280, 5],
  ]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });

    const metrics = await page
      .locator("#storefront-product-grid")
      .evaluate((grid) => ({
        columns: getComputedStyle(grid)
          .gridTemplateColumns.trim()
          .split(/\s+/)
          .filter(Boolean).length,
        overflow:
          document.documentElement.scrollWidth >
          document.documentElement.clientWidth,
      }));

    expect(metrics.columns).toBe(expectedColumns);
    expect(metrics.overflow).toBe(false);
  }
});

test("shows the catalog placeholder controls in the admin product tab", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`, {
    waitUntil: "domcontentloaded",
  });

  const placeholder = page.locator("#admin-product-placeholder");
  await expect(placeholder).toBeVisible();
  await expect(placeholder.getByText("Gambar default katalog")).toBeVisible();
  await expect(placeholder.getByText("Default bawaan aktif")).toBeVisible();
  await expect(
    placeholder.locator("#product-placeholder-image")
  ).toBeAttached();
  await expect(
    placeholder.getByRole("button", { name: "Simpan default" })
  ).toBeVisible();
  await expectNoDocumentOverflow(page);
});

test("admin accent controls meet AA text contrast in both themes", async ({
  page,
}, testInfo) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`, {
    waitUntil: "domcontentloaded",
  });

  const productSection = page.locator("#admin-section-products");
  const samples = [
    {
      name: "produk primary action",
      target: productSection
        .getByRole("button", { name: /Tambah Produk/ })
        .first(),
    },
    {
      name: "produk secondary action",
      target: productSection
        .getByRole("button", { name: /Kelola Product Family/ })
        .first(),
    },
    {
      name: "active sidebar item",
      target: page.locator(
        'aside.admin-dashboard-sidebar a[aria-current="page"]'
      ),
    },
  ];
  const report = { theme: { light: [], dark: [] }, mobileDrawer: {} };

  for (const theme of ["light", "dark"]) {
    await setAdminTheme(page, theme);

    for (const sample of samples) {
      await expect(sample.target).toBeVisible();

      const resting = expectAdminContrastAtLeast(
        await readSettledAdminContrastSample(sample.target),
        `${theme} ${sample.name}`
      );

      await sample.target.hover();

      const hover = expectAdminContrastAtLeast(
        await readSettledAdminContrastSample(sample.target),
        `${theme} ${sample.name} hover`
      );

      report.theme[theme].push({ name: sample.name, resting, hover });
    }

    await page.mouse.move(2, 2);
    await page.screenshot({
      path: testInfo.outputPath(`accent-${theme}.png`),
    });
  }

  await page.setViewportSize({ width: 375, height: 900 });
  await page.goto(`${baseUrl}/admin?tab=produk`, {
    waitUntil: "domcontentloaded",
  });
  await page.getByRole("button", { name: "Buka navigasi admin" }).click();

  const drawer = page.locator("#admin-dashboard-drawer");
  await expect(drawer).toBeVisible();
  report.mobileDrawer.activeItem = expectAdminContrastAtLeast(
    await readSettledAdminContrastSample(
      drawer.locator('a[aria-current="page"]').first()
    ),
    "dark mobile drawer active item"
  );
  report.mobileDrawer.createAction = expectAdminContrastAtLeast(
    await readSettledAdminContrastSample(
      drawer.getByRole("link", { name: "Tambah Produk" })
    ),
    "dark mobile drawer create action"
  );
  await page.screenshot({
    path: testInfo.outputPath("accent-dark-drawer.png"),
  });

  report.filterPill = { dark: null, light: null };

  for (const theme of ["dark", "light"]) {
    await page.goto(`${baseUrl}/admin?tab=penjualan&sales_filter=waiting`, {
      waitUntil: "domcontentloaded",
    });
    await setAdminTheme(page, theme);

    const activePill = page.locator("a.filter-pill-active").first();

    await expect(activePill).toBeVisible();
    report.filterPill[theme] = expectAdminContrastAtLeast(
      await readSettledAdminContrastSample(activePill),
      `${theme} active sales filter pill`
    );
  }

  await page.screenshot({
    path: testInfo.outputPath("accent-light-filter-pill.png"),
  });

  writeAdminContrastEvidence("accent-contrast.json", report);
});

test("admin auth surfaces keep AA text contrast in both themes", async ({
  page,
  browser,
}, testInfo) => {
  await login(page);
  await page.goto(`${baseUrl}/admin/confirm-password`, {
    waitUntil: "domcontentloaded",
  });

  const confirmButton = page.getByRole("button", { name: "Konfirmasi" });
  const confirmation = {
    light: expectAdminContrastAtLeast(
      await readSettledAdminContrastSample(confirmButton),
      "light owner password confirmation"
    ),
  };

  await page.locator("[data-theme-toggle]").first().click();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  confirmation.dark = expectAdminContrastAtLeast(
    await readSettledAdminContrastSample(confirmButton),
    "dark owner password confirmation"
  );
  await page.screenshot({
    path: testInfo.outputPath("confirm-password-dark.png"),
  });

  const linkedShellLink = page.getByRole("link", {
    name: "← Kembali ke Pesanan",
  });
  const linkedShell = {};

  for (const theme of ["light", "dark"]) {
    await page.goto(`${baseUrl}/admin/telegram-outbound-effects`, {
      waitUntil: "domcontentloaded",
    });
    await setAdminTheme(page, theme);

    await expect(linkedShellLink).toBeVisible();
    linkedShell[theme] = expectAdminContrastAtLeast(
      await readSettledAdminContrastSample(linkedShellLink),
      `${theme} linked-shell back link`
    );
  }

  const anonymousContext = await browser.newContext();
  const loginPage = await anonymousContext.newPage();

  await loginPage.goto(`${baseUrl}/admin/login`, {
    waitUntil: "domcontentloaded",
  });

  const loginButton = loginPage.getByRole("button", {
    name: "Masuk Dashboard",
  });
  const loginSamples = {
    light: expectAdminContrastAtLeast(
      await readSettledAdminContrastSample(loginButton),
      "light admin login"
    ),
  };

  await loginPage.locator("[data-theme-toggle]").first().click();
  await expect(loginPage.locator("html")).toHaveAttribute("data-theme", "dark");
  loginSamples.dark = expectAdminContrastAtLeast(
    await readSettledAdminContrastSample(loginButton),
    "dark admin login"
  );
  await loginPage.screenshot({
    path: testInfo.outputPath("admin-login-dark.png"),
  });
  await anonymousContext.close();

  writeAdminContrastEvidence("auth-contrast.json", {
    confirmation,
    login: loginSamples,
    linkedShell,
  });
});

test("admin upload controls keep native keyboard pickers, visible focus and 44px triggers", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`, {
    waitUntil: "domcontentloaded",
  });

  await expectUploadControlContract(
    page,
    "product-placeholder-image",
    "#admin-section-products"
  );
  const placeholderFeedback = page.locator(
    'label[for="product-placeholder-image"] + span'
  );

  // Clearing the native selection returns the visible state to "no file chosen".
  await page.locator("#product-placeholder-image").setInputFiles([]);
  await expect(placeholderFeedback).toHaveText("Belum ada file yang dipilih");

  await selectUploadFile(
    page,
    "product-placeholder-image",
    "katalog-pengganti.png"
  );
  await expectNoDocumentOverflow(page);

  await page.getByRole("button", { name: /Kelola Product Family/ }).click();
  const familyModal = page.getByRole("dialog", {
    name: "Kelola Product Family",
  });
  await expect(familyModal).toBeVisible();
  await expectUploadControlContract(
    page,
    "product-family-image",
    "#admin-product-family-management"
  );
  await expectUploadControlContract(
    page,
    "telegram-product-family-image",
    "#admin-product-family-management"
  );
  await expectNoDocumentOverflow(page);

  const familyFeedback = page.locator(
    'label[for="product-family-image"] + span'
  );

  await expect(familyFeedback).toHaveText("product-family-image-terpilih.png");
  await page.keyboard.press("Escape");
  await expect(familyModal).toBeHidden();

  await page.getByRole("button", { name: /Kelola Product Family/ }).click();
  await expect(familyModal).toBeVisible();
  await expect(familyFeedback).toHaveText("Tidak ada file yang dipilih");
  await page.keyboard.press("Escape");
  await expect(familyModal).toBeHidden();

  await page
    .locator("#admin-section-products")
    .getByRole("button", { name: /Tambah Produk/ })
    .first()
    .click();
  const productModal = page.getByRole("dialog", { name: /Tambah produk/i });
  await expect(productModal).toBeVisible();

  for (const inputId of [
    "product-image",
    "telegram-product-image",
    "telegram-channel-product-image",
  ]) {
    await expectUploadControlContract(page, inputId, "#product-modal");
  }

  await expectNoDocumentOverflow(page);
});

test("admin upload rows keep their label inside narrow viewports at 200% text size", async ({
  page,
}, testInfo) => {
  await page.setViewportSize({ width: 320, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`, {
    waitUntil: "domcontentloaded",
  });
  await page.addStyleTag({ content: "html { font-size: 200%; }" });
  await expect
    .poll(() =>
      page.evaluate(() => getComputedStyle(document.documentElement).fontSize)
    )
    .toBe("32px");

  await page.getByRole("button", { name: /Kelola Product Family/ }).click();
  await expect(
    page.getByRole("dialog", { name: "Kelola Product Family" })
  ).toBeVisible();

  for (const inputId of [
    "product-family-image",
    "telegram-product-family-image",
  ]) {
    const trigger = page.locator(`label[for="${inputId}"]`).last();

    await trigger.scrollIntoViewIfNeeded();

    const box = await trigger.boundingBox();

    expect(box, `${inputId} trigger box`).not.toBeNull();
    expect(
      Math.round(box.width),
      `${inputId} trigger width at 200% text`
    ).toBeGreaterThanOrEqual(44);
    expect(
      Math.round(box.height),
      `${inputId} trigger height at 200% text`
    ).toBeGreaterThanOrEqual(44);
    await expectElementWithinViewport(page, trigger);

    const geometry = await trigger.evaluate((element) => {
      const row = element.parentElement;
      const rowStyle = getComputedStyle(row);
      const box = element.getBoundingClientRect();
      const rowBox = row.getBoundingClientRect();

      return {
        fitsRowContent:
          box.right <=
          rowBox.right - Number.parseFloat(rowStyle.paddingRight) + 0.5,
        rowHeight: Math.round(rowBox.height),
      };
    });

    expect(
      geometry.fitsRowContent,
      `${inputId} trigger stays inside its upload row at 200% text`
    ).toBe(true);
  }

  await expectNoDocumentOverflow(page);
  await page.screenshot({
    path: testInfo.outputPath("upload-200-percent-text.png"),
  });
});

test("catalog placeholder upload stores a real image and rejects an invalid file", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`, {
    waitUntil: "domcontentloaded",
  });

  await selectUploadFile(page, "product-placeholder-image", "default-baru.png");

  // Cancelling the native picker fires no change event, so the previously
  // chosen file must survive and still be the one the form submits.
  const cancelledChooser = page.waitForEvent("filechooser");

  await page.locator("#product-placeholder-image").press("Enter");

  expect((await cancelledChooser).isMultiple()).toBe(false);
  await expect(
    page.locator('label[for="product-placeholder-image"] + span')
  ).toHaveText("default-baru.png");

  await Promise.all([
    page.waitForURL(`${baseUrl}/admin?tab=produk#admin-section-products`),
    page.getByRole("button", { name: "Simpan default" }).click(),
  ]);

  await expect(
    page.getByText("Gambar default katalog berhasil diperbarui.")
  ).toBeVisible();
  await expect(page.getByText("Default custom aktif")).toBeVisible();

  const [chooser] = await Promise.all([
    page.waitForEvent("filechooser"),
    page.locator("#product-placeholder-image").press("Enter"),
  ]);

  await chooser.setFiles({
    name: "bukan-gambar.png",
    mimeType: "image/png",
    buffer: Buffer.from("this is not an image"),
  });

  await Promise.all([
    page.waitForURL(`${baseUrl}/admin?tab=produk#admin-section-products`),
    page.getByRole("button", { name: "Simpan default" }).click(),
  ]);

  await expect(
    page.getByText("File harus berupa gambar yang valid.")
  ).toBeVisible();
  await expect(page.getByText("Default custom aktif")).toBeVisible();
  await expectNoDocumentOverflow(page);
});

test("keeps grouped Product Family controls usable at mobile width", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`, {
    waitUntil: "domcontentloaded",
  });

  const group = page
    .locator('[id^="admin-product-family-group-"]')
    .filter({
      has: page.locator("h4").filter({ hasText: "Browser QA Product Family" }),
    })
    .first();
  const memberList = group
    .locator('[id^="admin-product-family-members-"]')
    .first();
  await expect(group).toBeVisible();
  await expect(memberList).toBeHidden();
  await expect(
    page.getByRole("heading", { name: "Product standalone" })
  ).toHaveCount(0);
  await expect(
    group.getByRole("button", { name: "Lihat product" })
  ).toBeVisible();

  await group.getByRole("button", { name: "Lihat product" }).click();
  await expect(memberList).toBeVisible();
  await expect(memberList.getByText("QA option")).toBeVisible();
  await expect(
    group.getByRole("button", { name: "Tutup daftar product" })
  ).toBeVisible();

  await group.getByRole("button", { name: "Tutup daftar product" }).click();
  await expect(memberList).toBeHidden();
  await group.getByRole("button", { name: "Lihat product" }).click();
  await expect(memberList.getByText("QA option")).toBeVisible();
  const familyId = (await group.getAttribute("id")).replace(
    "admin-product-family-group-",
    ""
  );
  await group.getByRole("button", { name: "+ Tambah Product" }).click();
  await expect(
    page.locator("[data-family-assignment-select]:focus")
  ).toHaveValue(familyId);
  const editButton = group.getByRole("button", { name: /Edit family/i });
  await editButton.click();
  const modal = page.locator("#admin-product-family-management");
  await expect(modal).toBeVisible();
  await expect(
    modal.getByRole("heading", { name: "Edit Product Family" })
  ).toBeVisible();
  await expect(modal.locator("#product-family-name")).toBeFocused();
  await expectElementWithinViewport(page, modal);
  await expectNoDocumentOverflow(page);

  await modal.getByRole("button", { name: "Tutup modal" }).click();
  await expect(modal).toBeHidden();
  await expect(editButton).toBeFocused();
});

test("keeps the mobile navigation focus contained and restores its trigger", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });

  const trigger = page.getByRole("button", { name: "Buka menu navigasi" });
  await trigger.focus();
  await page.keyboard.press("Enter");

  const menu = page.getByLabel(/Menu navigasi Lhie Store/);
  const closeButton = menu.getByRole("button", { name: "Tutup menu" });
  await expect(menu).toBeVisible();
  await expect(closeButton).toBeFocused();

  await page.keyboard.press("Shift+Tab");
  await expect(
    menu.getByRole("link", { name: /Beranda Lhie Store/ })
  ).toBeFocused();
  await page.keyboard.press("Shift+Tab");
  await expect(
    menu.getByRole("button", { name: /Aktifkan mode (gelap|terang)/ })
  ).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(
    menu.getByRole("link", { name: /Beranda Lhie Store/ })
  ).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(closeButton).toBeFocused();

  await page.keyboard.press("Escape");
  await expect(menu).toBeHidden();
  await expect(trigger).toBeFocused();
});

test("lets admins reveal and hide the password field from an accessible control", async ({
  page,
}) => {
  await page.goto(`${baseUrl}/admin/login`);

  const password = page.locator("#password");
  await expect(password).toHaveAttribute("type", "password");

  const showPassword = page.getByRole("button", { name: "Lihat password" });
  await expect(showPassword).toBeVisible();
  await expect(showPassword).toHaveAttribute("aria-controls", "password");

  await showPassword.click();
  await expect(password).toHaveAttribute("type", "text");

  const hidePassword = page.getByRole("button", {
    name: "Sembunyikan password",
  });
  await expect(hidePassword).toBeVisible();
  await expect(hidePassword).toHaveAttribute("aria-controls", "password");

  await hidePassword.click();
  await expect(password).toHaveAttribute("type", "password");
  await expect(
    page.getByRole("button", { name: "Lihat password" })
  ).toBeVisible();
});

test("serializes the visible admin password field before submitting bogus credentials", async ({
  page,
}) => {
  const bogusEmail = "missing-admin@example.test";
  const filledPassword = "definitely-wrong-password";

  await page.goto(`${baseUrl}/admin/login`);
  await page.getByLabel("Username / email admin").fill(bogusEmail);
  await page.locator('input[type="password"]:visible').fill(filledPassword);

  const serializedPayload = await page
    .locator("form")
    .evaluate((form) => new URLSearchParams(new FormData(form)).toString());

  expect(serializedPayload).toContain(
    `email=${encodeURIComponent(bogusEmail)}`
  );
  expect(serializedPayload).toContain(
    `password=${encodeURIComponent(filledPassword)}`
  );

  await page.getByRole("button", { name: /sign in|login|masuk/i }).click();

  await expect(
    page.getByText("Email atau password admin tidak cocok.")
  ).toBeVisible();
  await expect(page.getByText("Password admin wajib diisi.")).toHaveCount(0);
});

test("renders the compact admin dashboard at desktop width", async ({
  page,
}, testInfo) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await installClipboardProbe(page);
  await login(page);

  await expect(
    page.getByRole("banner", { name: "Konteks halaman" })
  ).toContainText("Test User");
  await expect(
    page.getByRole("navigation", { name: "Navigasi admin", exact: true })
  ).toBeVisible();
  await expect(
    page.getByRole("navigation", { name: "Navigasi dashboard" })
  ).toBeHidden();
  await expect(
    page.getByRole("button", { name: "Bagikan", exact: true })
  ).toBeVisible();
  await expect(
    page.getByRole("region", { name: "Mode pembayaran" })
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "QR Manual" })).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Hosted Pakasir" })
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "QR Native Pakasir" })
  ).toHaveCount(0);
  await expectStoreLinkCopyAndShare(page);

  await page.screenshot({
    path: testInfo.outputPath("desktop-stats-light.png"),
  });
  await page.locator("[data-theme-toggle]").click();
  await page.screenshot({
    path: testInfo.outputPath("desktop-stats-dark.png"),
  });
  await page.locator("[data-theme-toggle]").click();
  await expectNoDocumentOverflow(page);
  await expectPrimaryActionsAtLeast44px(page);

  await page.goto(`${baseUrl}/admin?tab=produk`);
  await exerciseProductManagementModals(page);
  await expectNoDocumentOverflow(page);
  await page.screenshot({ path: testInfo.outputPath("desktop-products.png") });
});

test("direct desktop tabs select one sidebar destination and preserve content anchors", async ({
  page,
}, testInfo) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);
  const tabs = [
    ["stats", "Ringkasan", "admin-section-stats"],
    ["produk", "Produk", "admin-section-products"],
    ["penjualan", "Pesanan", "admin-section-sales"],
    ["suppliers", "Supplier", "supplier-title"],
    ["broadcast", "Siaran", "admin-section-broadcast"],
    ["telegram-channel", "Store Channel", "admin-section-store-channel"],
    ["riwayat", "Riwayat KlikQRIS", "admin-section-history"],
    ["withdraw", "Withdraw", "admin-section-withdraw"],
    ["funnel", "Funnel", "admin-section-funnel"],
    ["balance-issues", "Kompensasi Top-up", "admin-section-balance-issues"],
  ];
  for (const [tab, label, anchor] of tabs) {
    await page.goto(`${baseUrl}/admin?tab=${tab}`);
    const nav = page.getByRole("navigation", {
      name: "Navigasi admin",
      exact: true,
    });
    await expect(nav.locator('[aria-current="page"]')).toHaveCount(1);
    await expect(
      nav.getByRole("link", { name: label, exact: true })
    ).toHaveAttribute("aria-current", "page");
    await expect(page.locator(`#${anchor}`)).toBeVisible();
    await expect(
      page
        .getByRole("banner", { name: "Konteks halaman" })
        .getByRole("heading", { name: label, exact: true })
    ).toBeVisible();
    await expectNoDocumentOverflow(page);
    if (tab === "penjualan") {
      await page.screenshot({ path: testInfo.outputPath("desktop-sales.png") });
    }
  }
  await page.goto(`${baseUrl}/admin?tab=produk&product_form=create`);
  await expect(
    page.getByRole("dialog", { name: /Tambah produk/i })
  ).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(
    page.getByRole("dialog", { name: /Tambah produk/i })
  ).toBeHidden();
});

test("linked operational pages retain the shared shell and active destination", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);

  for (const [path, title, activeLabel] of [
    [
      "/admin/manual-fulfillment-reviews",
      "Manual Fulfillment Review",
      "Pesanan",
    ],
    ["/admin/telegram-outbound-effects", "Manual Delivery Review", "Pesanan"],
    [invoiceFixtures.adminOrderPath, "Detail order", "Pesanan"],
    [
      "/admin/suppliers/monitor/purchases",
      "Monitor pembelian supplier",
      "Supplier",
    ],
    [
      "/admin/suppliers/warranty",
      "Klaim Gmail · Refund & penggantian",
      "Supplier",
    ],
    ["/admin/store-balance/reports", "Laporan saldo Customer", "Laporan saldo"],
    [
      "/admin/store-balance/adjustments",
      "Kelola saldo Customer",
      "Kelola saldo",
    ],
    [
      "/admin/store-balance/usdt-review",
      "Review deposit USDT dan insiden",
      "Review deposit USDT",
    ],
  ]) {
    await page.goto(`${baseUrl}${path}`);
    await expect(
      page.getByRole("banner", { name: "Konteks halaman" })
    ).toContainText(title);
    await expect(
      page.getByRole("navigation", { name: "Navigasi admin", exact: true })
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: activeLabel, exact: true })
    ).toHaveAttribute("aria-current", "page");
    await expectNoDocumentOverflow(page);
  }
});

test("operational surfaces keep the shared shell and avoid occlusion at every breakpoint in both themes", async ({
  page,
}, testInfo) => {
  test.setTimeout(240_000);
  await login(page);

  const surfaces = [
    ["produk", "/admin?tab=produk"],
    ["pesanan", "/admin?tab=penjualan"],
    ["supplier", "/admin?tab=suppliers"],
    ["supplier-purchases", "/admin/suppliers/monitor/purchases"],
    ["supplier-warranty", "/admin/suppliers/warranty"],
    ["order-detail", invoiceFixtures.adminOrderPath],
    ["manual-fulfillment-review", "/admin/manual-fulfillment-reviews"],
    ["manual-delivery-review", "/admin/telegram-outbound-effects"],
  ];

  for (const width of [375, 768, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${baseUrl}/admin?tab=produk`);

    for (const theme of ["light", "dark"]) {
      if ((await page.locator("html").getAttribute("data-theme")) !== theme) {
        await page.locator("[data-theme-toggle]").first().click();
        await expect(page.locator("html")).toHaveAttribute("data-theme", theme);
      }

      for (const [name, path] of surfaces) {
        const response = await page.goto(`${baseUrl}${path}`, {
          waitUntil: "domcontentloaded",
        });
        expect(response.status(), `${path} should render`).toBe(200);

        const topbar = page.getByRole("banner", { name: "Konteks halaman" });
        await expect(topbar, `${path} keeps the shared topbar`).toBeVisible();
        await expectNoDocumentOverflow(page);

        const heading = page.locator("main h1, main h2").first();
        await expect(heading, `${path} renders a heading`).toBeVisible();
        const [topbarBox, headingBox] = await Promise.all([
          topbar.boundingBox(),
          heading.boundingBox(),
        ]);
        expect(
          headingBox.y,
          `first heading on ${path} stays below the shared topbar`
        ).toBeGreaterThanOrEqual(topbarBox.y + topbarBox.height - 1);

        await page.screenshot({
          path: testInfo.outputPath(`${name}-${width}-${theme}.png`),
          fullPage: false,
        });
      }
    }
  }
});

test("financial and communication surfaces keep the shared shell and avoid occlusion at every breakpoint in both themes", async ({
  page,
}, testInfo) => {
  test.setTimeout(300_000);
  await login(page);

  const surfaces = [
    ["ringkasan", "/admin?tab=stats"],
    ["broadcast", "/admin?tab=broadcast"],
    ["store-channel", "/admin?tab=telegram-channel"],
    ["riwayat", "/admin?tab=riwayat"],
    ["withdraw", "/admin?tab=withdraw"],
    ["funnel", "/admin?tab=funnel"],
    ["balance-issues", "/admin?tab=balance-issues"],
    ["balance-reports", "/admin/store-balance/reports"],
    ["balance-adjustments", "/admin/store-balance/adjustments"],
    ["usdt-review", "/admin/store-balance/usdt-review"],
  ];

  for (const width of [375, 768, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`${baseUrl}/admin?tab=stats`);

    for (const theme of ["light", "dark"]) {
      await setAdminTheme(page, theme);

      for (const [name, path] of surfaces) {
        const response = await page.goto(`${baseUrl}${path}`, {
          waitUntil: "domcontentloaded",
        });
        expect(response.status(), `${path} should render`).toBe(200);

        const topbar = page.getByRole("banner", { name: "Konteks halaman" });
        await expect(topbar, `${path} keeps the shared topbar`).toBeVisible();
        await expectNoDocumentOverflow(page);

        const heading = page.locator("main h1, main h2").first();
        await expect(heading, `${path} renders a heading`).toBeVisible();
        await expectStatusBadgesReadable(page.locator("main"));
        const [topbarBox, headingBox] = await Promise.all([
          topbar.boundingBox(),
          heading.boundingBox(),
        ]);
        expect(
          headingBox.y,
          `first heading on ${path} stays below the shared topbar`
        ).toBeGreaterThanOrEqual(topbarBox.y + topbarBox.height - 1);

        await page.screenshot({
          path: testInfo.outputPath(`${name}-${width}-${theme}.png`),
          fullPage: false,
        });
      }
    }
  }
});

test("Ringkasan keeps real summary metrics, earnings boundaries and IDR/USDT separation", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=stats`);

  const ringkasan = page.locator("#admin-section-stats");
  const metrics = ringkasan.locator('section[aria-label="Metrik ringkasan"]');
  await expect(metrics).toBeVisible();
  await expect(metrics).toContainText("GMV");
  await expect(metrics).toContainText("Saldo");
  await expect(metrics).toContainText("Produk aktif");

  const activity = ringkasan.locator(
    'section[aria-label="Aktivitas ringkasan"]'
  );
  await expect(activity).toContainText("Order");

  const earnings = ringkasan.locator('section[aria-label="Pendapatan 7 hari"]');
  await expect(earnings).toBeVisible();
  await expect(
    earnings.getByRole("heading", { name: "Pendapatan 7 hari" })
  ).toBeVisible();
  await expect(earnings.getByText("Pembayaran lunas")).toBeVisible();
  await expect(earnings.locator("div[style]")).toHaveCount(7);

  await expect(
    ringkasan.getByRole("heading", { name: "Kebijakan Top-up Saldo" })
  ).toBeVisible();
  await expect(
    ringkasan.getByRole("heading", { name: "Kebijakan Top-up USDT" })
  ).toBeVisible();
  await expect(
    ringkasan.getByRole("heading", { name: "BSC Receiving Address" })
  ).toBeVisible();
  await expect(
    ringkasan.getByRole("heading", { name: "Kontrol Operasional" })
  ).toBeVisible();

  await expectNoDocumentOverflow(page);
});

test("finance tables keep their overflow local and their labels readable at 200% text", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);

  for (const [tab, section] of [
    ["withdraw", "#admin-section-withdraw"],
    ["riwayat", "#admin-section-history"],
    ["balance-issues", "#admin-section-balance-issues"],
  ]) {
    await page.goto(`${baseUrl}/admin?tab=${tab}`);
    await page.evaluate(() => {
      document.documentElement.style.fontSize = "200%";
    });

    const region = page.locator(section);
    const table = region.locator("table").first();
    await expect(
      table,
      `${tab} table stays rendered at 200% text`
    ).toBeVisible();
    await expectStatusBadgesReadable(region);

    // A table wider than its wrapper must scroll inside that wrapper instead of
    // widening the document.
    const widths = await table.evaluate((element) => {
      const wrapper = element.parentElement;

      return {
        table: Math.round(element.getBoundingClientRect().width),
        wrapper: Math.round(wrapper.getBoundingClientRect().width),
        wrapperOverflowX: window.getComputedStyle(wrapper).overflowX,
      };
    });
    expect(widths.wrapperOverflowX, `${tab} table wrapper scrolls`).toBe(
      "auto"
    );
    expect(
      widths.wrapper,
      `${tab} table reaches past its own wrapper instead of the document`
    ).toBeLessThanOrEqual(widths.table + 1);

    await expectNoDocumentOverflow(page);

    await page.evaluate(() => {
      document.documentElement.style.removeProperty("font-size");
    });
  }
});

test("desktop sidebar links navigate without JavaScript and retain filters", async ({
  browser,
}) => {
  const context = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();
  try {
    await login(page);
    await page.goto(`${baseUrl}/admin?tab=penjualan&sales_filter=waiting`);
    const nav = page.getByRole("navigation", {
      name: "Navigasi admin",
      exact: true,
    });
    await nav.getByRole("link", { name: "Produk", exact: true }).click();
    await expect(page).toHaveURL(/tab=produk&sales_filter=waiting/);
    await expect(
      nav.getByRole("link", { name: "Produk", exact: true })
    ).toHaveAttribute("aria-current", "page");
  } finally {
    await context.close();
  }
});

for (const role of ["owner", "admin"]) {
  for (const width of [320, 375, 1280]) {
    test(`no-JavaScript ${role} tabs expose server content and validation at ${width}px`, async ({
      browser,
    }) => {
      const context = await browser.newContext({
        javaScriptEnabled: false,
        viewport: { width, height: 900 },
      });
      const page = await context.newPage();
      try {
        await login(
          page,
          role === "owner" ? "test@example.com" : "admin-browser@example.test"
        );
        const tabs = [
          ["stats", "#admin-section-stats"],
          ["produk", "#admin-section-products"],
          ["penjualan", "#admin-section-sales"],
          ["suppliers", "[data-supplier-workspace]"],
          ["broadcast", "#admin-section-broadcast"],
          ["telegram-channel", "#admin-section-store-channel"],
          ["riwayat", "#admin-section-history"],
          ["withdraw", "#admin-section-withdraw"],
          ["funnel", "#admin-section-funnel"],
          ...(role === "owner"
            ? [["balance-issues", "#admin-section-balance-issues"]]
            : []),
        ];
        for (const [tab, selector] of tabs) {
          const response = await page.goto(`${baseUrl}/admin?tab=${tab}`);
          expect(response.status()).toBe(200);
          const content = page.locator(selector);
          await expect(content).toBeVisible();
          await expect(content.getByRole("heading").first()).toBeVisible();
          await expectNoDocumentOverflow(page);
        }
        await page.goto(`${baseUrl}/admin?tab=stats`);
        await page.evaluate(() => {
          document.documentElement.style.fontSize = "200%";
        });
        const settings = page.locator("#admin-payment-settings");
        await settings.evaluate((element) =>
          element.scrollIntoView({ block: "start", behavior: "instant" })
        );
        const topbarBox = await page
          .getByRole("banner", { name: "Konteks halaman" })
          .boundingBox();
        const settingsBox = await settings.boundingBox();
        expect(settingsBox.y).toBeGreaterThanOrEqual(
          topbarBox.y + topbarBox.height
        );
        expect(settingsBox.y).toBeLessThan(page.viewportSize().height);
        await expectNoDocumentOverflow(page);
        await page.evaluate(() =>
          document.documentElement.style.removeProperty("font-size")
        );
        await page.goto(`${baseUrl}/admin?tab=produk`);
        await expect(
          page.getByRole("heading", {
            name: "Browser QA Product Family",
            exact: true,
          })
        ).toBeVisible();
        await expect(
          page.getByRole("button", { name: "Simpan default", exact: true })
        ).toBeVisible();
        await page.goto(`${baseUrl}/admin?tab=broadcast`);
        const rejectedMessage = "x".repeat(3501);
        await page.getByLabel("Pesan", { exact: true }).fill(rejectedMessage);
        await page.getByRole("button", { name: /Kirim Siaran/ }).click();
        await expect(page).toHaveURL(`${baseUrl}/admin?tab=broadcast`);
        await expect(
          page.getByText("Pesan broadcast maksimal 3500 karakter.", {
            exact: true,
          })
        ).toBeVisible();
        await expect(page.getByLabel("Pesan", { exact: true })).toHaveValue(
          rejectedMessage
        );
        await page.goto(`${baseUrl}/admin?tab=funnel`);
        await page.locator('input[name="start_date"]').fill("2026-10-01");
        await page.locator('input[name="end_date"]').fill("2026-10-09");
        await page
          .locator('form[method="get"]')
          .getByRole("button", { name: /Terapkan/ })
          .click();
        expect(new URL(page.url()).searchParams.get("start_date")).toBe(
          "2026-10-01"
        );
        expect(new URL(page.url()).searchParams.get("end_date")).toBe(
          "2026-10-09"
        );
      } finally {
        await context.close();
      }
    });
  }
}

test("ordinary sidebar and linked-page anchors preserve fragments and explicit settings targets", async ({
  page,
  context,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);
  await page.goto(
    `${baseUrl}/admin?tab=produk&sales_filter=waiting&product_form=create#admin-section-sales`
  );
  await page.keyboard.press("Escape");
  const nav = page.getByRole("navigation", {
    name: "Navigasi admin",
    exact: true,
  });
  const popupPromise = context.waitForEvent("page");
  await nav
    .getByRole("link", { name: "Pesanan", exact: true })
    .click({ modifiers: ["Control"] });
  const popup = await popupPromise;
  await expect(popup).toHaveURL(
    `${baseUrl}/admin?tab=penjualan&sales_filter=waiting#admin-section-sales`
  );
  await expect(popup.locator("#admin-section-sales")).toBeVisible();
  await popup.close();
  await nav
    .getByRole("link", { name: "Pengaturan pembayaran", exact: true })
    .click();
  await expect(page).toHaveURL(
    `${baseUrl}/admin?tab=stats&sales_filter=waiting#admin-payment-settings`
  );
  await page.goBack();
  await expect(page).toHaveURL(
    /tab=produk.*product_form=create#admin-section-sales/
  );
  await page.goForward();
  await expect(page).toHaveURL(/tab=stats.*#admin-payment-settings/);
  await page.goto(
    `${baseUrl}/admin/manual-fulfillment-reviews?sales_filter=waiting#admin-section-sales`
  );
  await nav.getByRole("link", { name: "Produk", exact: true }).click();
  await expect(page).toHaveURL(
    `${baseUrl}/admin?sales_filter=waiting&tab=produk#admin-section-sales`
  );
});

test("shared topbars stay reachable and leave hash, skip and focused targets uncovered", async ({
  page,
}, testInfo) => {
  await login(page);
  for (const width of [375, 768, 1280]) {
    await page.setViewportSize({ width, height: 600 });
    for (const destination of [
      "/admin?tab=stats",
      invoiceFixtures.unknownEffectPath,
    ]) {
      await page.goto(`${baseUrl}${destination}`);
      const topbar = page.getByRole("banner", { name: "Konteks halaman" });
      await page.evaluate(() =>
        window.scrollTo({
          top: document.body.scrollHeight,
          behavior: "instant",
        })
      );
      expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(60);
      expect((await topbar.boundingBox()).y).toBeGreaterThanOrEqual(0);
      const toggle = topbar.locator("[data-theme-toggle]");
      await toggle.click();
      await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
      await expectNoDocumentOverflow(page);
      await page.screenshot({
        path: testInfo.outputPath(
          `topbar-${width}-${destination.startsWith("/admin?") ? "dashboard" : "review"}-dark.png`
        ),
      });
      await toggle.click();
      await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
      await page.screenshot({
        path: testInfo.outputPath(
          `topbar-${width}-${destination.startsWith("/admin?") ? "dashboard" : "review"}-light.png`
        ),
      });
    }
    await page.goto(`${baseUrl}/admin?tab=stats#admin-payment-settings`);
    await expectUncoveredByTopbar(
      page,
      page.locator("#admin-payment-settings")
    );
    const skip = page.getByRole("link", { name: "Lewati ke konten utama" });
    await skip.focus();
    await expect(skip).toBeInViewport();
    await page.keyboard.press("Enter");
    await expect(page.locator("#admin-dashboard-content")).toBeFocused();
    await expectUncoveredByTopbar(
      page,
      page.locator("#admin-dashboard-content")
    );
    await page.goto(`${baseUrl}/admin?tab=broadcast`);
    await page.getByRole("button", { name: /Kirim Siaran/ }).click();
    const error = page.getByText("Isi pesan broadcast terlebih dahulu.", {
      exact: true,
    });
    await expect(error).toBeVisible();
    await error.evaluate((element) =>
      element.scrollIntoView({ block: "start", behavior: "instant" })
    );
    await expectUncoveredByTopbar(page, error);
    const message = page.getByLabel("Pesan", { exact: true });
    await message.evaluate((element) =>
      element.scrollIntoView({ block: "start", behavior: "instant" })
    );
    await message.focus();
    await expectUncoveredByTopbar(page, message);
  }
});

test("Unknown delivery review renders reduced metadata and existing resolution actions", async ({
  page,
}) => {
  await login(page);
  const response = await page.goto(
    `${baseUrl}${invoiceFixtures.unknownEffectPath}`
  );
  expect(response.status()).toBe(200);
  const content = page.locator("#admin-dashboard-content");
  await expect(
    content.getByRole("heading", { name: /INV-BROWSER-DELIVERY-REVIEW/ })
  ).toBeVisible();
  await expect(content.getByText("unknown", { exact: true })).toBeVisible();
  await expect(
    content.getByText("ConnectionException", { exact: true })
  ).toBeVisible();
  await expect(content).not.toContainText("BROWSER-PRIVATE-OUTCOME");
  await expect(page.getByLabel("Telegram message identifier")).toBeVisible();
  await expect(
    page.getByRole("button", {
      name: "Mark as sent, no Telegram request",
      exact: true,
    })
  ).toBeVisible();
  await expect(
    page.getByRole("checkbox", { name: /duplicate-risk/ })
  ).toBeVisible();
  await expect(
    page.getByRole("button", {
      name: "Create explicit resend version",
      exact: true,
    })
  ).toBeVisible();
});

test("hides retired Pakasir controls from provider switch", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);

  const paymentRegion = page.getByRole("region", { name: "Mode pembayaran" });
  await expect(paymentRegion).toBeVisible();
  await expect(
    paymentRegion.getByRole("button", { name: "QR Manual" })
  ).toBeVisible();
  await expect(
    paymentRegion.getByRole("button", { name: "KlikQRIS" })
  ).toBeVisible();
  await expect(
    paymentRegion.getByRole("button", { name: "Hosted Pakasir" })
  ).toHaveCount(0);
  await expect(
    paymentRegion.getByRole("button", { name: "QR Native Pakasir" })
  ).toHaveCount(0);
});

test("payment switch and maintenance confirmations keep focus, Escape and real submission controls", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=stats#admin-payment-settings`);

  const paymentRegion = page.getByRole("region", { name: "Mode pembayaran" });
  const switchTrigger = paymentRegion.getByRole("button", {
    name: "QR Manual",
    exact: true,
  });
  await switchTrigger.scrollIntoViewIfNeeded();
  await switchTrigger.click();

  const switchDialog = page.getByRole("dialog", {
    name: "Konfirmasi ganti provider pembayaran",
  });
  await expect(switchDialog).toBeVisible();
  await expect(
    switchDialog.getByRole("button", { name: "Konfirmasi switch" })
  ).toBeFocused();
  await expect(switchDialog).toContainText("Hanya checkout baru yang berubah.");
  await page.keyboard.press("Escape");
  await expect(switchDialog).toBeHidden();
  await expect(switchTrigger).toBeFocused();

  const maintenanceCard = page.locator("#admin-checkout-maintenance");
  const maintenanceTrigger = maintenanceCard
    .locator("form[data-checkout-maintenance-target] button[type='submit']")
    .first();
  await maintenanceTrigger.scrollIntoViewIfNeeded();
  await maintenanceTrigger.click();

  const maintenanceDialog = page.getByRole("dialog", {
    name: "Konfirmasi perubahan Checkout Maintenance",
  });
  await expect(maintenanceDialog).toBeVisible();
  await expect(
    maintenanceDialog.getByRole("button", { name: "Konfirmasi", exact: true })
  ).toBeFocused();
  await expect(maintenanceDialog).toContainText(
    "Hanya pembuatan Order baru yang terpengaruh."
  );
  await page.keyboard.press("Escape");
  await expect(maintenanceDialog).toBeHidden();
  await expect(maintenanceTrigger).toBeFocused();

  await expect(
    paymentRegion.getByRole("button", { name: /Binance Pay USDT/ })
  ).toBeVisible();
  await expect(
    paymentRegion.getByRole("textbox", { name: "Nomor WhatsApp" })
  ).toBeVisible();
});
test("keeps shared context and store actions reachable on internal admin tabs", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);

  for (const [tab, label] of [
    ["produk", "Produk"],
    ["penjualan", "Pesanan"],
    ["broadcast", "Siaran"],
    ["telegram-channel", "Store Channel"],
    ["funnel", "Funnel"],
  ]) {
    await page.goto(`${baseUrl}/admin?tab=${tab}`);

    const compactHeader = page.getByRole("banner", {
      name: "Konteks halaman",
    });
    await expect(compactHeader).toBeVisible();
    await expect(
      compactHeader.getByRole("heading", { name: label, exact: true })
    ).toBeVisible();
    await expect(compactHeader).toContainText("Toko aktif");
    await expect(
      compactHeader.getByRole("link", { name: "Buka Toko" })
    ).toBeVisible();

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    const headerBox = await compactHeader.boundingBox();
    expect(headerBox?.y ?? -1).toBeGreaterThanOrEqual(0);
  }
});

test("mobile drawer contains focus and restores its visible trigger", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 900 });
  await login(page);
  const trigger = page.getByRole("button", { name: "Buka navigasi admin" });
  await expect(trigger).toHaveAttribute("aria-expanded", "false");
  await trigger.click();
  const drawer = page.getByRole("dialog", {
    name: "Navigasi admin",
    exact: true,
  });
  await expect(drawer).toBeVisible();
  await expect(trigger).toHaveAttribute("aria-expanded", "true");
  await expect(page.locator("body")).toHaveCSS("overflow", "hidden");
  const close = drawer.getByRole("button", { name: "Tutup navigasi" });
  await expect(close).toBeFocused();
  const last = drawer.getByRole("link", { name: "Tambah Produk", exact: true });
  await last.focus();
  await page.keyboard.press("Tab");
  await expect(close).toBeFocused();
  await page.keyboard.press("Shift+Tab");
  await expect(last).toBeFocused();
  await page.keyboard.press("Escape");
  await expect(drawer).toBeHidden();
  await expect(trigger).toBeFocused();
  await expect(page.locator("body")).not.toHaveCSS("overflow", "hidden");
  await trigger.click();
  await page.mouse.click(365, 120);
  await expect(drawer).toBeHidden();
  await expect(trigger).toBeFocused();
  await trigger.click();
  await page.setViewportSize({ width: 1024, height: 900 });
  await expect(drawer).toBeHidden();
  await expect(page.locator("body")).not.toHaveCSS("overflow", "hidden");
  await page.setViewportSize({ width: 375, height: 900 });
  await expect(trigger).toHaveAttribute("aria-expanded", "false");
  await expectNoDocumentOverflow(page);
});

test("removing an open drawer root releases its scroll lock", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 900 });
  await login(page);
  await page.evaluate(() => {
    document.body.style.setProperty("overflow-x", "clip", "important");
    document.body.style.setProperty("overflow-y", "auto");
  });
  await page.getByRole("button", { name: "Buka navigasi admin" }).click();
  await expect(page.locator("body")).toHaveCSS("overflow", "hidden");
  await page
    .locator(".admin-dashboard-shell")
    .evaluate((root) => root.remove());
  await expect
    .poll(() =>
      page.evaluate(() => ({
        x: document.body.style.getPropertyValue("overflow-x"),
        priority: document.body.style.getPropertyPriority("overflow-x"),
        y: document.body.style.getPropertyValue("overflow-y"),
      }))
    )
    .toEqual({ x: "clip", priority: "important", y: "auto" });
});

test("mobile drawer reaches every tab and preserves filters, hash and product-create", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 900 });
  await login(page);
  await page.goto(
    `${baseUrl}/admin?tab=penjualan&sales_filter=waiting#admin-section-sales`
  );
  for (const [tab, label] of [
    ["stats", "Ringkasan"],
    ["produk", "Produk"],
    ["penjualan", "Pesanan"],
    ["suppliers", "Supplier"],
    ["broadcast", "Siaran"],
    ["telegram-channel", "Store Channel"],
    ["riwayat", "Riwayat KlikQRIS"],
    ["withdraw", "Withdraw"],
    ["funnel", "Funnel"],
    ["balance-issues", "Kompensasi Top-up"],
  ]) {
    await page.getByRole("button", { name: "Buka navigasi admin" }).click();
    await page
      .getByRole("dialog", { name: "Navigasi admin", exact: true })
      .getByRole("link", { name: label, exact: true })
      .click();
    await expect(page).toHaveURL(
      `${baseUrl}/admin?tab=${tab}&sales_filter=waiting#admin-section-sales`
    );
    await expect(
      page.getByRole("dialog", { name: "Navigasi admin", exact: true })
    ).toBeHidden();
    await expect(page.locator("body")).not.toHaveCSS("overflow", "hidden");
  }
  await page.getByRole("button", { name: "Buka navigasi admin" }).click();
  await page
    .getByRole("dialog", { name: "Navigasi admin", exact: true })
    .getByRole("link", { name: "Tambah Produk", exact: true })
    .click();
  await expect(page).toHaveURL(
    /tab=produk&sales_filter=waiting&product_form=create#admin-section-sales/
  );
  await expect(page.locator("#product-name")).toBeFocused();
  await expect(
    page.getByRole("dialog", { name: "Navigasi admin", exact: true })
  ).toBeHidden();
});

test("mobile navigation remains usable without JavaScript", async ({
  browser,
}) => {
  const context = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 375, height: 900 },
  });
  const page = await context.newPage();
  await login(page);
  await page
    .locator("details")
    .filter({ has: page.locator("summary", { hasText: "Navigasi dashboard" }) })
    .locator("summary")
    .click();
  const nav = page.getByRole("navigation", {
    name: "Navigasi dashboard",
    exact: true,
  });
  await nav.getByRole("link", { name: "Produk", exact: true }).click();
  await expect(page).toHaveURL(/tab=produk/);
  await expect(
    page.getByRole("heading", { name: "Produk", exact: true })
  ).toBeVisible();
  await page.locator("summary", { hasText: "Navigasi dashboard" }).click();
  await expect(
    nav.getByRole("link", { name: "Produk", exact: true })
  ).toHaveAttribute("aria-current", "page");
  await nav.getByRole("link", { name: "Pesanan", exact: true }).click();
  await expect(page).toHaveURL(/tab=penjualan/);
  await expect(
    page.getByRole("heading", { name: "Pesanan", exact: true })
  ).toBeVisible();
  await context.close();
});

test("Mini App shell keeps edge controls and modal content inside layout insets", async ({
  page,
}, testInfo) => {
  await page.setViewportSize({ width: 375, height: 900 });
  await login(page);
  await page.evaluate(() => {
    const root = document.documentElement;
    // Device and Telegram content insets overlap; the larger inset wins.
    for (const [edge, device, content] of [
      ["top", 24, 48],
      ["right", 12, 20],
      ["bottom", 20, 34],
      ["left", 16, 28],
    ]) {
      root.style.setProperty(`--tg-safe-area-inset-${edge}`, `${device}px`);
      root.style.setProperty(
        `--tg-content-safe-area-inset-${edge}`,
        `${content}px`
      );
    }
  });
  const trigger = page.getByRole("button", { name: "Buka navigasi admin" });
  const triggerBox = await trigger.boundingBox();
  expect(triggerBox.x).toBeGreaterThanOrEqual(28);
  expect(triggerBox.y).toBeGreaterThanOrEqual(48);
  await trigger.click();
  const drawer = page.getByRole("dialog", {
    name: "Navigasi admin",
    exact: true,
  });
  const close = drawer.getByRole("button", { name: "Tutup navigasi" });
  expect((await close.boundingBox()).y).toBeGreaterThanOrEqual(48);
  for (const height of [420, 600, 900]) {
    await page.setViewportSize({ width: 375, height });
    const last = drawer.getByRole("link", {
      name: "Tambah Produk",
      exact: true,
    });
    await last.scrollIntoViewIfNeeded();
    const box = await last.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(28);
    expect(box.y + box.height).toBeLessThanOrEqual(height - 34);
    await expectNoDocumentOverflow(page);
  }
  await page.screenshot({
    path: testInfo.outputPath("mini-app-drawer-insets.png"),
  });
  await page.keyboard.press("Escape");
  await page.goto(`${baseUrl}/admin?tab=produk&product_form=create`);
  // Reapply layout-only emulation after full-page navigation.
  await page.evaluate(() => {
    document.documentElement.style.setProperty(
      "--tg-content-safe-area-inset-top",
      "48px"
    );
    document.documentElement.style.setProperty(
      "--tg-content-safe-area-inset-bottom",
      "34px"
    );
    document.documentElement.style.setProperty(
      "--tg-content-safe-area-inset-left",
      "28px"
    );
    document.documentElement.style.setProperty(
      "--tg-content-safe-area-inset-right",
      "20px"
    );
  });
  await page.setViewportSize({ width: 375, height: 420 });
  const modal = page.getByRole("dialog", { name: /Tambah produk/i });
  const bounds = await modal.boundingBox();
  expect(bounds.y).toBeGreaterThanOrEqual(48);
  expect(bounds.y + bounds.height).toBeLessThanOrEqual(420 - 34);
  expect(bounds.x).toBeGreaterThanOrEqual(28);
  expect(bounds.x + bounds.width).toBeLessThanOrEqual(375 - 20);
  const save = modal.getByRole("button", { name: /Submit produk/i });
  await save.scrollIntoViewIfNeeded();
  await expect(save).toBeInViewport();
  await page.screenshot({
    path: testInfo.outputPath("mini-app-keyboard-modal.png"),
  });
  await page.keyboard.press("Escape");
  await expect(modal).toBeHidden();
  await expectNoDocumentOverflow(page);
});

for (const width of [768, 1023, 1024, 1280, 1440, 1920]) {
  test(`keeps dashboard navigation reachable without overflow at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    await login(page);

    const sidebar = page.getByRole("complementary", { name: "Navigasi admin" });
    const mobileNav = page.getByRole("dialog", {
      name: "Navigasi admin",
      exact: true,
    });
    if (width >= 1024) {
      await expect(sidebar).toBeVisible();
      await expect(mobileNav).toBeHidden();
      const box = await sidebar.boundingBox();
      expect(box.width).toBeGreaterThanOrEqual(228);
      expect(box.width).toBeLessThanOrEqual(240);
      for (const link of await sidebar.getByRole("link").all()) {
        await link.scrollIntoViewIfNeeded();
        await expect(link).toBeInViewport();
      }
      const content = await page
        .locator("#admin-dashboard-content")
        .boundingBox();
      expect(content.x).toBeGreaterThanOrEqual(box.x + box.width);
    } else {
      await expect(sidebar).toBeHidden();
      await page.getByRole("button", { name: "Buka navigasi admin" }).click();
      await expect(mobileNav).toBeVisible();
      await page.keyboard.press("Escape");
    }
    await expectNoDocumentOverflow(page);
  });
}

for (const width of [320, 390, 768, 1280, 1440, 1920]) {
  test(`renders KlikQRIS reporting cards and tables without overflow at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    await login(page);

    await page.goto(`${baseUrl}/admin?tab=riwayat`, {
      waitUntil: "domcontentloaded",
    });

    const history = page.locator("#admin-section-history");
    await expect(
      history.getByRole("heading", { name: "Riwayat KlikQRIS" })
    ).toBeVisible();
    await expect(history.getByText("KLIK-BROWSER-MATCH")).toHaveCount(2);
    await expect(history.getByText("Mismatch lokal")).toHaveCount(1);
    await expect(
      history.getByText(
        "Tidak ada Order lokal yang cocok dengan provider order ID ini."
      )
    ).toHaveCount(1);

    if (width < 768) {
      await expect(history.locator("article").first()).toBeVisible();
      await expect(history.locator("table")).toBeHidden();
    } else {
      await expect(history.locator("article").first()).toBeHidden();
      await expect(history.locator("article").last()).toBeHidden();
      await expect(history.locator("table")).toBeVisible();
    }
    await expectNoDocumentOverflow(page);

    await page.goto(`${baseUrl}/admin?tab=withdraw`, {
      waitUntil: "domcontentloaded",
    });

    const withdraw = page.locator("#admin-section-withdraw");
    await expect(
      withdraw.getByRole("heading", { name: "Withdraw Order Status" })
    ).toBeVisible();
    await expect(withdraw.getByLabel("Order ID")).toBeVisible();
    await expect(withdraw.getByText("WD-BROWSER-1")).toHaveCount(2);
    await expect(
      withdraw.getByRole("link", { name: "Buka bukti transfer" })
    ).toHaveCount(1);
    await expect(
      withdraw.getByRole("link", { name: "Buka bukti transfer" }).first()
    ).toHaveAttribute("href", "https://klikqris.com/proofs/WD-BROWSER-1.png");

    if (width < 768) {
      await expect(withdraw.locator("article").first()).toBeVisible();
      await expect(withdraw.locator("table")).toBeHidden();
    } else {
      await expect(withdraw.locator("article").first()).toBeHidden();
      await expect(withdraw.locator("article").last()).toBeHidden();
      await expect(withdraw.locator("table")).toBeVisible();
    }
    await expectNoDocumentOverflow(page);
  });
}

for (const width of [320, 390, 768, 1280, 1440, 1920]) {
  test(`renders storefront funnel analytics without overflow at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    await login(page);
    await page.goto(`${baseUrl}/admin?tab=funnel`, {
      waitUntil: "domcontentloaded",
    });

    const funnel = page.locator("#admin-section-funnel");
    await expect(
      funnel.getByRole("heading", { name: "Storefront Funnel" })
    ).toBeVisible();
    await expect(funnel.locator('select[name="product_id"]')).toBeVisible();
    await expect(funnel.locator('select[name="rail"]')).toBeVisible();
    await expect(funnel.getByText("QR/provider outcomes")).toBeVisible();
    await expect(funnel.getByText("Maksimal 31 hari")).toBeVisible();
  });
}

test("mobile content and product modal remain reachable with the drawer closed", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=telegram-channel`);
  const channelLink = page.getByRole("textbox", {
    name: "Link channel publik",
  });
  await channelLink.scrollIntoViewIfNeeded();
  await expect(channelLink).toBeInViewport();
  await page.goto(`${baseUrl}/admin?tab=produk`);
  await page.getByRole("button", { name: /Tambah Produk/i }).click();
  const productModal = page.getByRole("dialog", { name: /Tambah produk/i });
  await expect(productModal).toBeVisible();
  await expectElementWithinViewport(page, productModal);
  await expect(
    page.getByRole("dialog", { name: "Navigasi admin", exact: true })
  ).toBeHidden();
});

test("keeps tabs, cards, and the stock modal usable at Telegram mini app width", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 900 });
  await login(page);

  await expectNoDocumentOverflow(page);
  await expect(
    page.getByRole("button", { name: "Buka navigasi admin" })
  ).toBeVisible();
  await page
    .getByRole("region", { name: "Mode pembayaran" })
    .scrollIntoViewIfNeeded();
  await expect(page.getByRole("button", { name: "QR Manual" })).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Hosted Pakasir" })
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "QR Native Pakasir" })
  ).toHaveCount(0);

  await expect(page.getByRole("region", { name: "Link toko" })).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Bagikan", exact: true })
  ).toBeVisible();

  await page.goto(`${baseUrl}/admin?tab=produk`);
  let productCard = await getAdminProductCard(
    page,
    "Browser QA Produk A",
    "Browser QA Product Family"
  );
  const productBCard = await getAdminProductCard(page, "Browser QA Produk B");
  await expectOneColumn(productCard, productBCard);
  await expectNoDocumentOverflow(page);
  await submitProductFromMiniAppModal(page);
  productCard = await getAdminProductCard(
    page,
    "Browser QA Produk A",
    "Browser QA Product Family"
  );

  const productActions = productCard.locator("[data-product-actions]").first();
  const primaryAction = productActions
    .locator("[data-product-primary-action]")
    .first();
  const secondaryActions = productActions
    .locator("[data-product-secondary-actions]")
    .first();
  const moreTrigger = productActions
    .locator("[data-product-more-trigger]")
    .first();
  const morePanel = productActions.locator("[data-product-more-panel]").first();

  await expect(
    primaryAction.getByRole("button", { name: "Stok", exact: true })
  ).toBeVisible();
  await expect(
    secondaryActions.getByRole("button", { name: "Ubah", exact: true })
  ).toBeVisible();
  await expect(
    secondaryActions.getByRole("button", { name: "Kelola", exact: true })
  ).toBeVisible();
  await expect(
    productActions.getByRole("button", { name: "Bagikan", exact: true })
  ).toBeHidden();
  await expect(
    productActions.getByRole("button", { name: /Pause|Aktifkan/ })
  ).toBeHidden();
  await expect(
    productActions.getByRole("button", { name: "Arsipkan", exact: true })
  ).toBeHidden();
  await expect(moreTrigger).toHaveAttribute("aria-expanded", "false");

  await expectBefore(primaryAction, secondaryActions);
  await expectBefore(secondaryActions, moreTrigger);
  await expectStrictTouchTargetsAtLeast44px(productActions);

  await moreTrigger.click();
  await expect(moreTrigger).toHaveAttribute("aria-expanded", "true");
  await expect(morePanel).toBeVisible();
  await expect(
    morePanel.getByRole("button", { name: "Bagikan", exact: true })
  ).toBeVisible();
  await expect(
    morePanel.getByRole("button", { name: /Pause|Aktifkan/ })
  ).toBeVisible();
  await expect(
    morePanel.getByRole("button", { name: "Arsipkan", exact: true })
  ).toBeVisible();
  await expectStrictTouchTargetsAtLeast44px(productActions);

  await productCard.getByRole("button", { name: "Kelola" }).click();

  const manageModal = page.getByRole("dialog", { name: "Kelola Stok" });
  await expect(manageModal).toBeVisible();
  await expect(
    manageModal.getByText("browser-stock-a@example.test|pass")
  ).toBeVisible();
  await expect(manageModal.getByRole("textbox")).toBeHidden();
  await manageModal
    .getByRole("button", { name: "Ubah akun stok" })
    .first()
    .click();
  await expect(manageModal.getByRole("textbox")).toBeVisible();
  await manageModal.getByRole("button", { name: "Batal" }).click();
  await expect(manageModal.getByRole("textbox")).toBeHidden();
  await manageModal.getByRole("button", { name: /Tambah stok baru/ }).click();

  const modal = page.getByRole("dialog", { name: "Tambah Stok" });
  await expect(modal).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Tambah Stok" })
  ).toBeVisible();
  await expect(modal.getByText("0 item")).toBeVisible();
  await expect(modal.locator('input[name="operation_key"]')).toHaveValue(
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
  );

  await modal
    .getByPlaceholder("email@example.com|password\nKEY-XXXX-1234")
    .fill("akun-1@example.test\nakun-2@example.test");
  await expect(modal.getByText("2 item")).toBeVisible();

  await expectElementWithinViewport(page, modal);
  await expectTouchTargetAtLeast44px(
    modal.getByRole("button", { name: "Batal" })
  );
  await expectTouchTargetAtLeast44px(
    modal.getByRole("button", { name: /Simpan/ })
  );
  await expectNoDocumentOverflow(page);

  await page.goto(`${baseUrl}/admin?tab=penjualan`);
  await expect(page.getByText("INV-BROWSER-A")).toBeVisible();
  await expectOneColumn(
    page
      .locator("[data-order-status]:visible")
      .filter({ hasText: "INV-BROWSER-A" }),
    page
      .locator("[data-order-status]:visible")
      .filter({ hasText: "INV-BROWSER-B" })
  );
  await expectNoDocumentOverflow(page);
  await expectPrimaryActionsAtLeast44px(page);
});

test("keeps category management create, edit, and delete confirmation usable on mobile", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);

  await expectNoDocumentOverflow(page);

  const opener = page.getByRole("button", { name: /Kelola Kategori/i });
  await opener.click();

  const modal = page.locator("#admin-category-management");
  await expect(modal).toBeVisible();
  await expect(
    modal.getByRole("heading", { name: /Kelola kategori/i })
  ).toBeVisible();
  await expect(modal.locator("#category-management-name")).toBeFocused();
  await expect(
    modal.locator("article").filter({ hasText: "Browser Responsive QA" })
  ).toBeVisible();
  await expectElementWithinViewport(page, modal);

  const categoryForm = modal.locator("form").first();
  await expect(categoryForm).toHaveAttribute("action", /\/admin\/categories$/);
  await categoryForm
    .locator("#category-management-name")
    .fill("Draft kategori browser");
  await categoryForm
    .locator("#category-management-description")
    .fill("Deskripsi draft kategori.");
  await expect(categoryForm.locator("#category-management-name")).toHaveValue(
    "Draft kategori browser"
  );

  const categoryArticle = modal
    .locator("article")
    .filter({ hasText: "Browser Responsive QA" })
    .first();
  await categoryArticle.getByRole("button", { name: /Edit/i }).click();
  await expect(
    modal.getByRole("heading", { name: "Edit kategori" })
  ).toBeVisible();
  await expect(categoryForm).toHaveAttribute(
    "action",
    /\/admin\/categories\/\d+$/
  );
  await expect(categoryForm.locator('input[name="_method"]')).toHaveValue(
    "PATCH"
  );
  await expect(categoryForm.locator("#category-management-name")).toHaveValue(
    "Browser Responsive QA"
  );
  await expect(
    categoryForm.locator("#category-management-sort-order")
  ).toHaveValue("99");

  await categoryForm
    .locator("#category-management-visibility")
    .selectOption("false");
  await categoryForm.locator("#category-management-sort-order").fill("101");
  await expect(
    categoryForm.locator('input[type="hidden"][name="is_active"]')
  ).toHaveValue("0");
  await expect(
    categoryForm.locator("#category-management-sort-order")
  ).toHaveValue("101");

  await modal.getByRole("button", { name: "Batal edit" }).click();
  await expect(
    modal.getByRole("heading", { name: /Kelola kategori/i })
  ).toBeVisible();
  await expect(categoryForm.locator('input[name="_method"]')).toHaveCount(0);
  await expect(categoryForm.locator("#category-management-name")).toHaveValue(
    ""
  );

  const deleteForm = categoryArticle.locator(
    "form[data-category-delete-confirmation]"
  );
  const confirmation = await deleteForm.getAttribute(
    "data-category-delete-confirmation"
  );
  expect(confirmation).toContain("Browser Responsive QA");
  expect(confirmation).toContain("Tanpa kategori");
  expect(confirmation).toContain("Status aktif, stok, dan reservasi");

  let confirmationType;
  let confirmationMessage;
  page.once("dialog", async (dialog) => {
    confirmationType = dialog.type();
    confirmationMessage = dialog.message();
    await dialog.dismiss();
  });
  await deleteForm.getByRole("button", { name: /Hapus/i }).click();
  expect(confirmationType).toBe("confirm");
  expect(confirmationMessage).toContain("Tanpa kategori");

  await expect(modal).toBeVisible();
  await expectNoDocumentOverflow(page);
  await modal.getByRole("button", { name: "Tutup modal" }).click();
  await expect(modal).toBeHidden();
  await expect(opener).toBeFocused();
});

test("opens and closes category management from the desktop Products tab", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);

  await expectNoDocumentOverflow(page);

  const opener = page.getByRole("button", { name: /Kelola Kategori/i });
  await opener.click();

  const modal = page.locator("#admin-category-management");
  await expect(modal).toBeVisible();
  await expect(
    modal.getByRole("heading", { name: /Kelola kategori/i })
  ).toBeVisible();
  await expect(modal.locator("#category-management-name")).toBeFocused();
  await expect(
    modal.locator("article").filter({ hasText: "Browser Responsive QA" })
  ).toBeVisible();
  await expectElementWithinViewport(page, modal);

  await modal.getByRole("button", { name: "Tutup modal" }).click();
  await expect(modal).toBeHidden();
  await expect(opener).toBeFocused();
});

test("traps keyboard focus in the product modal and restores its opener", async ({
  page,
}) => {
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);

  const opener = page.getByRole("button", { name: /Tambah Produk/i });
  await opener.click();

  const dialog = page.getByRole("dialog", { name: /Tambah produk/i });
  const closeButton = dialog.getByRole("button", { name: "Tutup modal" });
  const submitButton = dialog.getByRole("button", { name: /Submit produk/i });
  await expect(dialog.locator("#product-name")).toBeFocused();

  await closeButton.focus();
  await page.keyboard.press("Shift+Tab");
  await expect(submitButton).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(closeButton).toBeFocused();

  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();

  await opener.click();
  await dialog.getByRole("button", { name: "Tutup modal" }).click();
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();
});

test("traps keyboard focus in the stock modal and restores its opener", async ({
  page,
}) => {
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);
  const productCard = await getAdminProductCard(
    page,
    "Browser QA Produk A",
    "Browser QA Product Family"
  );
  const opener = productCard.getByRole("button", { name: /Stok/i });
  await opener.click();

  const dialog = page.getByRole("dialog", { name: "Tambah Stok" });
  const closeButton = dialog.getByRole("button", { name: "Tutup modal" });
  const submitButton = dialog.getByRole("button", { name: /Simpan/i });
  await expect(dialog.locator("#stock-items")).toBeFocused();

  await closeButton.focus();
  await page.keyboard.press("Shift+Tab");
  await expect(submitButton).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(closeButton).toBeFocused();

  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();

  const manageStockOpener = productCard.getByRole("button", {
    name: /Kelola/i,
  });
  await manageStockOpener.click();
  const manageStockDialog = page.getByRole("dialog", {
    name: "Kelola stok",
  });
  await expect(manageStockDialog).toBeVisible();
  await expect(
    manageStockDialog.getByRole("button", { name: /Tambah stok baru/i })
  ).toBeVisible();
  await manageStockDialog.getByRole("button", { name: "Tutup modal" }).click();
  await expect(manageStockDialog).toBeHidden();
  await expect(manageStockOpener).toBeFocused();
});

test("keeps structured Account Input reviewed, bounded, and private in page memory", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);
  const productCard = await getAdminProductCard(
    page,
    "Browser QA Produk A",
    "Browser QA Product Family"
  );
  const opener = productCard.getByRole("button", { name: /Stok/i });
  await opener.click();

  const dialog = page.getByRole("dialog", { name: "Tambah Stok" });
  await expect(dialog).toBeVisible();
  const quickTab = dialog.getByRole("tab", { name: "Input Cepat" });
  const accountTab = dialog.getByRole("tab", { name: "Input Akun" });
  await expect(quickTab).toHaveAttribute(
    "aria-controls",
    "stock-input-quick-panel"
  );
  await expect(accountTab).toHaveAttribute(
    "aria-controls",
    "stock-input-account-panel"
  );
  await expect(dialog.locator('[role="tabpanel"]')).toHaveCount(2);
  await accountTab.click();
  await expect(dialog.locator("#account-email-0")).toBeFocused();
  await expect(dialog.getByText("1 / 20 akun")).toBeVisible();

  await accountTab.focus();
  await page.keyboard.press("ArrowLeft");
  await expect(quickTab).toBeFocused();
  await expect(dialog.locator("#stock-input-quick-panel")).toBeVisible();
  await page.keyboard.press("ArrowRight");
  await expect(accountTab).toBeFocused();
  await expect(dialog.locator("#stock-input-account-panel")).toBeVisible();
  await dialog.locator("#account-email-0").focus();

  await dialog.getByRole("button", { name: /Review 1 akun/ }).click();
  await expect(dialog.locator("#account-email-0")).toHaveAttribute(
    "aria-invalid",
    "true"
  );
  await expect(dialog.locator("#account-email-0")).toHaveAttribute(
    "aria-describedby",
    "account-email-error-0"
  );
  await expect(dialog.locator("#account-email-error-0")).toHaveAttribute(
    "role",
    "alert"
  );

  await dialog.locator("#account-email-0").fill("account-one@example.test");
  await dialog.locator("#account-password-0").fill("multi\nline-fake-password");
  await dialog.getByRole("radio", { name: "OTP Mail Link" }).check();
  await dialog
    .locator("#account-access-value-0")
    .fill("https://mail.example.test/otp?fake=browser");
  await expect(
    dialog.getByText("Domain tujuan: mail.example.test")
  ).toBeVisible();

  await dialog.getByRole("radio", { name: "Tidak ada" }).check();
  await expect(dialog.locator("#account-access-value-0")).toBeHidden();

  for (let index = 0; index < 19; index += 1) {
    await dialog.getByRole("button", { name: /Tambah akun/ }).click();
  }
  await expect(dialog.getByText("20 / 20 akun")).toBeVisible();
  await expect(
    dialog.getByRole("button", { name: /Tambah akun/ })
  ).toBeDisabled();

  await dialog.getByRole("button", { name: "Hapus akun #20" }).click();
  await expect(dialog.getByText("19 / 20 akun")).toBeVisible();
  for (let index = 19; index > 1; index -= 1) {
    await dialog.getByRole("button", { name: `Hapus akun #${index}` }).click();
  }
  await expect(dialog.getByText("1 / 20 akun")).toBeVisible();

  await dialog.getByRole("button", { name: /Review 1 akun/ }).click();
  await expect(dialog.getByText("Review wajib sebelum commit")).toBeVisible();
  await expect(
    dialog.getByText("Preview private buyer photo-caption")
  ).toBeVisible();
  await expectNoDocumentOverflow(page);
  await page.setViewportSize({ width: 320, height: 900 });
  await expectNoDocumentOverflow(page);
  await page.setViewportSize({ width: 1280, height: 900 });
  await expectNoDocumentOverflow(page);

  const secret = "multi\nline-fake-password";
  const leakedAttribute = await dialog.evaluate(
    (root, value) =>
      [...root.querySelectorAll("*")].some((element) =>
        [...element.attributes].some((attribute) =>
          attribute.value.includes(value)
        )
      ),
    secret
  );
  expect(leakedAttribute).toBe(false);

  await dialog.getByRole("button", { name: /Kembali edit/ }).click();
  await dialog.locator("#account-email-0").fill("changed@example.test");
  await expect(dialog.getByText("Review wajib sebelum commit")).toBeHidden();

  let switchDialog;
  page.once("dialog", async (browserDialog) => {
    switchDialog = browserDialog;
    await browserDialog.dismiss();
  });
  await dialog.getByRole("tab", { name: "Input Cepat" }).click();
  expect(switchDialog?.type()).toBe("confirm");
  await expect(dialog.locator("#account-email-0")).toBeVisible();
  await expect(dialog.locator("#account-email-0")).toHaveValue(
    "changed@example.test"
  );

  let acceptedSwitchDialog;
  page.once("dialog", async (browserDialog) => {
    acceptedSwitchDialog = browserDialog;
    await browserDialog.accept();
  });
  await dialog.getByRole("tab", { name: "Input Cepat" }).click();
  expect(acceptedSwitchDialog?.type()).toBe("confirm");
  await expect(dialog.locator("#stock-items")).toBeVisible();
  await expect(dialog.locator("#account-email-0")).toBeHidden();

  let closeDialog;
  page.once("dialog", async (browserDialog) => {
    closeDialog = browserDialog;
    await browserDialog.dismiss();
  });
  await dialog.getByRole("button", { name: "Tutup modal" }).click();
  expect(closeDialog?.type()).toBe("confirm");
  await expect(dialog).toBeVisible();

  await dialog.getByRole("tab", { name: "Input Akun" }).click();
  await expect(dialog.locator("#account-email-0")).toHaveValue(
    "changed@example.test"
  );

  let acceptedCloseDialog;
  page.once("dialog", async (browserDialog) => {
    acceptedCloseDialog = browserDialog;
    await browserDialog.accept();
  });
  await dialog.getByRole("button", { name: "Tutup modal" }).click();
  expect(acceptedCloseDialog?.type()).toBe("confirm");
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();
});

test("focuses the relevant product field when server validation reopens the modal", async ({
  page,
}) => {
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);
  await page.getByRole("button", { name: /Tambah Produk/i }).click();

  const dialog = page.getByRole("dialog", { name: /Tambah produk/i });
  await Promise.all([
    page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    dialog.locator("form").evaluate((form) => form.submit()),
  ]);

  await expect(
    page.getByRole("dialog", { name: /Tambah produk/i })
  ).toBeVisible();
  await expect(page.locator("#product-name")).toBeFocused();
});

for (const navClickCase of [
  {
    name: "desktop sidebar navigation",
    viewport: { width: 1024, height: 900 },
    navigationName: "Navigasi admin",
  },
  {
    name: "Telegram mini app navigation",
    viewport: { width: 375, height: 900 },
    navigationName: "Navigasi dashboard",
  },
]) {
  test(`switches admin dashboard tabs and opens product creation from ${navClickCase.name} clicks`, async ({
    page,
  }) => {
    await page.setViewportSize(navClickCase.viewport);
    await login(page);

    await expectClickedDashboardControlsToWork(
      page,
      navClickCase.navigationName
    );
  });
}

test("preserves the sales filter while visiting another dashboard tab", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1024, height: 900 });
  await login(page);
  await page.goto(
    `${baseUrl}/admin?tab=penjualan&sales_filter=waiting#admin-section-sales`
  );

  const nav = page.getByRole("navigation", {
    name: "Navigasi admin",
    exact: true,
  });
  await nav.getByRole("link", { name: "Produk", exact: true }).click();
  await page.waitForURL(
    `${baseUrl}/admin?tab=produk&sales_filter=waiting#admin-section-sales`
  );
  await nav.getByRole("link", { name: "Pesanan", exact: true }).click();

  await expect(page).toHaveURL(
    `${baseUrl}/admin?tab=penjualan&sales_filter=waiting#admin-section-sales`
  );
  await page.goBack();
  await expect(
    nav.getByRole("link", { name: "Produk", exact: true })
  ).toHaveAttribute("aria-current", "page");
  await page.goForward();
  await expect(
    nav.getByRole("link", { name: "Pesanan", exact: true })
  ).toHaveAttribute("aria-current", "page");
});

for (const broadcastDirectCase of [
  {
    name: "desktop",
    viewport: { width: 1024, height: 900 },
    navigationName: "Navigasi admin",
  },
  {
    name: "Telegram mini app",
    viewport: { width: 375, height: 900 },
    navigationName: "Navigasi dashboard",
  },
]) {
  test(`opens Siaran directly with matching active navigation on ${broadcastDirectCase.name}`, async ({
    page,
  }) => {
    await page.setViewportSize(broadcastDirectCase.viewport);
    await login(page);
    await page.goto(`${baseUrl}/admin?tab=broadcast`);

    await expect(
      page.locator("#admin-section-broadcast h2").first()
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Status Siaran" })
    ).toBeVisible();

    const nav = page.getByRole("navigation", {
      name: broadcastDirectCase.navigationName,
    });
    if (broadcastDirectCase.navigationName === "Navigasi dashboard") {
      await page.getByRole("button", { name: "Buka navigasi admin" }).click();
    }
    await expect(
      nav.getByRole("link", { name: "Siaran", exact: true })
    ).toHaveAttribute("aria-current", "page");
  });
}

async function expectClickedDashboardControlsToWork(page, navigationName) {
  const mobile = navigationName === "Navigasi dashboard";
  const navigate = async (label) => {
    if (mobile)
      await page.getByRole("button", { name: "Buka navigasi admin" }).click();
    await page
      .getByRole("navigation", { name: navigationName, exact: true })
      .getByRole("link", { name: label, exact: true })
      .click();
  };
  await navigate("Produk");
  await expect(
    page.getByRole("heading", { name: /Produk Saya/ })
  ).toBeVisible();
  await getAdminProductCard(page, "Browser QA Produk A");
  await navigate("Pesanan");
  await expect(
    page.getByRole("heading", { name: /Penjualan Saya/ })
  ).toBeVisible();
  await expect(page.getByText("INV-BROWSER-A")).toBeVisible();
  await navigate("Store Channel");
  await expect(
    page.getByRole("region", { name: "Telegram Channel" })
  ).toBeVisible();
  await navigate("Siaran");
  await expect(
    page.getByRole("heading", { name: "Status Siaran" })
  ).toBeVisible();
  if (mobile) {
    await page.getByRole("button", { name: "Buka navigasi admin" }).click();
    await page
      .getByRole("dialog", { name: "Navigasi admin", exact: true })
      .getByRole("link", { name: "Tambah Produk", exact: true })
      .click();
  } else {
    await navigate("Produk");
    await page.getByRole("button", { name: /Tambah Produk/ }).click();
  }
  const productModal = page.getByRole("dialog", { name: /Tambah produk/i });
  await expect(productModal).toBeVisible();
  await expect(productModal.locator("#product-name")).toBeVisible();
}

test("saves product name and icon edits from the admin modal", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);
  const productCard = await getAdminProductCard(
    page,
    "Browser QA Produk A",
    "Browser QA Product Family"
  );
  await productCard.getByRole("button", { name: "Ubah", exact: true }).click();

  const productModal = page.getByRole("dialog", { name: "Edit produk" });
  const form = productModal.locator("form");
  await expect(productModal).toBeVisible();
  await expect(form).toHaveAttribute("action", /\/admin\/products\/\d+$/);
  await expect(form.locator('input[name="_method"]')).toHaveValue("PATCH");

  await productModal
    .locator("#product-name")
    .fill("Browser QA Produk A Edited");
  await productModal.locator("#product-icon").fill("🛠️");

  await Promise.all([
    page.waitForURL(`${baseUrl}/admin?tab=produk`),
    productModal.getByRole("button", { name: /Simpan/i }).click(),
  ]);

  await expect(
    page.getByText("Produk Browser QA Produk A Edited berhasil diperbarui.")
  ).toBeVisible();
  await getAdminProductCard(
    page,
    "Browser QA Produk A Edited",
    "Browser QA Product Family"
  );
});

test("manages quantity price tiers from the product modal at narrow mobile width", async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`, {
    waitUntil: "domcontentloaded",
  });

  const productCard = await getAdminProductCard(
    page,
    "Browser QA Produk A",
    "Browser QA Product Family"
  );
  await productCard.getByRole("button", { name: "Ubah", exact: true }).click();

  const dialog = page.getByRole("dialog", { name: "Edit produk" });
  const addTierButton = dialog.getByRole("button", {
    name: "+ Tambah tingkat harga",
  });
  await expect(dialog).toBeVisible();
  await expect(addTierButton).toBeVisible();
  await expectElementWithinViewport(page, dialog);

  await addTierButton.focus();
  await page.keyboard.press("Enter");
  await dialog.locator("#product-quantity-tier-0-minimum").fill("2");
  await dialog.locator("#product-quantity-tier-0-idr").fill("40000");
  await dialog.locator("#product-quantity-tier-0-usdt").fill("1.8");

  for (const [index, minimum, idr, usdt] of [
    [1, "5", "35000", "1.6"],
    [2, "8", "30000", "1.4"],
    [3, "10", "25000", "1.2"],
    [4, "12", "20000", "1.0"],
  ]) {
    await addTierButton.click();
    await dialog
      .locator(`#product-quantity-tier-${index}-minimum`)
      .fill(minimum);
    await dialog.locator(`#product-quantity-tier-${index}-idr`).fill(idr);
    await dialog.locator(`#product-quantity-tier-${index}-usdt`).fill(usdt);
  }
  await expect(addTierButton).toBeDisabled();
  await dialog.getByRole("button", { name: "Hapus tingkat harga #2" }).click();
  await expect(dialog.locator("[data-tier-row]")).toHaveCount(4);
  while (await dialog.locator("[data-tier-row]").count()) {
    await dialog
      .locator("[data-tier-row]")
      .first()
      .getByRole("button", { name: /Hapus tingkat harga/ })
      .click();
  }
  await expect(dialog.locator("[data-tier-row]")).toHaveCount(0);
  await expect(
    dialog.getByText("Tidak ada tingkat harga. Harga dasar tetap digunakan.")
  ).toBeVisible();

  await addTierButton.click();
  await dialog.locator("#product-quantity-tier-0-minimum").fill("2");
  await dialog.locator("#product-quantity-tier-0-idr").fill("40000");
  await dialog.locator("#product-quantity-tier-0-usdt").fill("1.8");

  await Promise.all([
    page.waitForURL(/\/admin\?tab=produk/),
    dialog.getByRole("button", { name: /Simpan/i }).click(),
  ]);
  await expect(
    page.getByText(
      /Produk Browser QA Produk A(?: Edited)? berhasil diperbarui\./
    )
  ).toBeVisible();

  const updatedCard = await getAdminProductCard(
    page,
    "Browser QA Produk A",
    "Browser QA Product Family"
  );
  await updatedCard.getByRole("button", { name: "Ubah", exact: true }).click();
  const reopenedDialog = page.getByRole("dialog", { name: "Edit produk" });
  await expect(
    reopenedDialog.locator("#product-quantity-tier-0-minimum")
  ).toHaveValue("2");
  await expect(
    reopenedDialog.locator("#product-quantity-tier-0-idr")
  ).toHaveValue("40000");
  await expect(
    reopenedDialog.locator("#product-quantity-tier-0-usdt")
  ).toHaveValue("1.8");
  await expectElementWithinViewport(page, reopenedDialog);
  await expectNoDocumentOverflow(page);
});

test("saves locale copy variants from the product modal language tabs", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);

  const productCard = await getAdminProductCard(
    page,
    "Browser QA Produk A",
    "Browser QA Product Family"
  );
  await productCard.getByRole("button", { name: "Ubah", exact: true }).click();

  const dialog = page.getByRole("dialog", { name: "Edit produk" });
  await expect(dialog).toBeVisible();
  await expect(dialog.locator("#product-description-id-panel")).toBeVisible();
  await expect(dialog.locator("#product-description-en-panel")).toBeHidden();

  await dialog
    .getByRole("tab", { name: "English", exact: true })
    .first()
    .click();
  await expect(dialog.locator("#product-description-id-panel")).toBeHidden();
  await expect(dialog.locator("#product-description-en-panel")).toBeVisible();
  await expect(
    dialog.locator("#product-warranty-policy-en-panel")
  ).toBeVisible();
  await dialog
    .locator("#product-description-en")
    .fill("Browser QA English details.");
  await dialog
    .locator("#product-warranty-policy-en")
    .fill("Browser QA English refund policy.");

  await Promise.all([
    page.waitForURL(`${baseUrl}/admin?tab=produk`),
    dialog.getByRole("button", { name: /Simpan/i }).click(),
  ]);
  await expect(
    page.getByText(
      /Produk Browser QA Produk A(?: Edited)? berhasil diperbarui\./
    )
  ).toBeVisible();

  const reopenedCard = await getAdminProductCard(
    page,
    "Browser QA Produk A",
    "Browser QA Product Family"
  );
  await reopenedCard.getByRole("button", { name: "Ubah", exact: true }).click();

  const reopenedDialog = page.getByRole("dialog", { name: "Edit produk" });
  await reopenedDialog
    .getByRole("tab", { name: "English", exact: true })
    .first()
    .click();
  await expect(reopenedDialog.locator("#product-description-en")).toHaveValue(
    "Browser QA English details."
  );
  await expect(
    reopenedDialog.locator("#product-warranty-policy-en")
  ).toHaveValue("Browser QA English refund policy.");

  await reopenedDialog
    .getByRole("tab", { name: "Bahasa Melayu", exact: true })
    .first()
    .click();
  await expect(reopenedDialog.locator("#product-description-ms")).toHaveValue(
    ""
  );
  await expect(
    reopenedDialog.locator("#product-warranty-policy-ms")
  ).toHaveValue("");
  await expect(
    reopenedDialog.locator("#product-description-ms-panel")
  ).toBeVisible();
  await expectElementWithinViewport(page, reopenedDialog);
  await expectNoDocumentOverflow(page);
});

async function submitProductFromMiniAppModal(page) {
  const productName = `Mini App Produk ${Date.now()}`;

  await page.getByRole("button", { name: /Tambah Produk/i }).click();
  const productModal = page.getByRole("dialog", { name: /Tambah produk/i });
  await expect(productModal).toBeVisible();
  await productModal.locator("#product-name").fill(productName);
  await productModal.locator("#product-price").fill("33000");
  await productModal
    .locator("#product-description")
    .fill("Produk dibuat dari viewport Telegram Mini App.");

  await Promise.all([
    page.waitForURL(`${baseUrl}/admin?tab=produk`),
    productModal.getByRole("button", { name: /Submit produk/i }).click(),
  ]);

  await expect(
    page.getByRole("dialog", { name: /Tambah produk/i })
  ).toBeHidden();
  await expect(
    page.locator("article:visible").filter({ hasText: productName })
  ).toBeVisible();
  await expect(
    page.getByText(`Produk ${productName} berhasil dibuat.`)
  ).toBeVisible();
  await expectNoDocumentOverflow(page);
  await expectPrimaryActionsAtLeast44px(page);
}

test("bulk stock deletion keeps mobile modal open and refreshes remaining accounts", async ({
  page,
}) => {
  runArtisan([
    "tinker",
    "--execute",
    `
    $product = \\App\\Models\\Product::factory()->create(['name' => 'Bulk Stock QA', 'stock' => 30, 'reserved_stock' => 0]);
    foreach (range(1, 30) as $index) {
      \\App\\Models\\ProductStockItem::create(['product_id' => $product->id, 'secret' => 'bulk-fixture-'.$index]);
    }
  `,
  ]);
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);
  const card = await getAdminProductCard(page, "Bulk Stock QA");
  await card.getByRole("button", { name: "Kelola", exact: true }).click();
  const modal = page.getByRole("dialog", { name: "Kelola Stok" });
  const selection = modal.getByRole("checkbox", { name: /Pilih akun stok/ });
  await expect(selection).toHaveCount(25);
  await expect(card.locator('span[x-text*="stockCountSnapshots"]')).toHaveText([
    "30",
    "30",
    "0",
  ]);
  await modal
    .getByRole("checkbox", { name: "Pilih semua yang tampil" })
    .check();
  await expect(
    modal.getByRole("button", { name: "Hapus terpilih (25)" })
  ).toBeEnabled();
  page.once("dialog", (dialog) => dialog.dismiss());
  await modal.getByRole("button", { name: "Hapus terpilih (25)" }).click();
  await expect(selection).toHaveCount(25);
  await modal
    .getByRole("checkbox", { name: "Pilih semua yang tampil" })
    .uncheck();
  await selection.first().check();
  let mutationBody;
  page.on("request", (request) => {
    if (request.method() === "DELETE" && request.url().includes("stock-items"))
      mutationBody = request.postDataJSON();
  });
  page.once("dialog", (dialog) => dialog.accept());
  await modal.getByRole("button", { name: "Hapus terpilih (1)" }).click();
  await expect(modal.getByText("1 akun stok berhasil dihapus.")).toBeVisible();
  await expect(selection).toHaveCount(25);
  await expect(selection.first()).not.toBeChecked();
  await expect(modal).toBeVisible();
  expect(Object.keys(mutationBody)).toEqual(["stock_item_ids"]);
  expect(mutationBody.stock_item_ids).toHaveLength(1);
  await modal
    .getByRole("checkbox", { name: "Pilih semua yang tampil" })
    .check();
  page.once("dialog", (dialog) => dialog.accept());
  await modal.getByRole("button", { name: "Hapus terpilih (25)" }).click();
  await expect(modal.getByText("25 akun stok berhasil dihapus.")).toBeVisible();
  await expect(selection).toHaveCount(4);
  await expect(selection.first()).not.toBeChecked();
  await modal
    .getByRole("checkbox", { name: "Pilih semua yang tampil" })
    .check();
  page.once("dialog", (dialog) => dialog.accept());
  await modal.getByRole("button", { name: "Hapus terpilih (4)" }).click();
  await expect(modal.getByText("4 akun stok berhasil dihapus.")).toBeVisible();
  await expect(selection).toHaveCount(0);
  await expect(modal.getByText("Belum ada akun tersedia.")).toBeVisible();
  await expect(card.locator('span[x-text*="stockCountSnapshots"]')).toHaveText([
    "0",
    "0",
    "0",
  ]);
  await expectNoDocumentOverflow(page);
});

async function login(page, email = "test@example.com") {
  await page.goto(`${baseUrl}/admin/login`);
  await page.getByLabel("Username / email admin").fill(email);
  await page.locator('input[type="password"]').fill("password");
  await page.getByRole("button", { name: /sign in|login|masuk/i }).click();
  await page.waitForURL(`${baseUrl}/admin`);
}

async function expectUncoveredByTopbar(page, target) {
  await expect(target).toBeInViewport();
  await settleSmoothScroll(page);
  await expect
    .poll(async () => {
      const topbar = await page
        .getByRole("banner", { name: "Konteks halaman" })
        .boundingBox();
      const box = await target.boundingBox();
      return box.y - (topbar.y + topbar.height);
    })
    .toBeGreaterThanOrEqual(0);
}

async function installClipboardProbe(page) {
  await page.addInitScript(() => {
    window.__copiedText = null;

    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: {
        async writeText(text) {
          window.__copiedText = text;
        },
      },
    });

    Object.defineProperty(navigator, "share", {
      configurable: true,
      value: undefined,
    });
  });
}

async function expectStoreLinkCopyAndShare(page) {
  const storeLink = page.getByRole("region", { name: "Link toko" });
  const visibleStoreUrl = (
    await storeLink.locator("p").nth(1).innerText()
  ).trim();

  await storeLink.getByRole("button", { name: "Salin" }).click();
  await expect(
    storeLink.getByRole("button", { name: "Tersalin" })
  ).toBeVisible();
  await expect
    .poll(() => page.evaluate(() => window.__copiedText))
    .toBe(visibleStoreUrl);

  await storeLink.getByRole("button", { name: "Bagikan", exact: true }).click();
  await expect
    .poll(() => page.evaluate(() => window.__copiedText))
    .toBe(visibleStoreUrl);
}
async function getAdminProductCard(page, productName, groupName = null) {
  const familyGroups = page
    .locator('[id^="admin-product-family-group-"]')
    .filter(groupName ? { hasText: groupName } : {});

  for (let index = 0; index < (await familyGroups.count()); index += 1) {
    const familyGroup = familyGroups.nth(index);
    const productCard = groupName
      ? familyGroup.locator('article[id^="product-"]').first()
      : familyGroup
          .locator('article[id^="product-"]')
          .filter({ hasText: productName })
          .first();

    if (!(await productCard.count())) {
      continue;
    }

    if (!(await productCard.isVisible())) {
      const expandButton = familyGroup.getByRole("button", {
        name: "Lihat product",
        exact: true,
      });
      await expandButton.click();
    }

    await expect(productCard).toBeVisible();
    return productCard;
  }

  const productCard = page
    .locator('article[id^="product-"]')
    .filter({ hasText: productName })
    .first();
  await expect(productCard).toBeVisible();
  return productCard;
}
async function exerciseProductManagementModals(page) {
  const productCard = await getAdminProductCard(
    page,
    "Browser QA Produk A",
    "Browser QA Product Family"
  );
  await page.getByRole("button", { name: /Tambah Produk/i }).click();
  const productModal = page.getByRole("dialog", { name: /Tambah produk/i });
  await expect(productModal).toBeVisible();
  await expect(productModal.locator("#product-description")).toBeVisible();
  await expect(
    productModal.getByText("Detail yang dilihat pelanggan")
  ).toHaveCount(0);
  await expect(productModal.locator("#product-type")).toHaveCount(0);
  await expect(
    productModal.getByText("Catatan pengiriman rahasia")
  ).toBeVisible();
  await expectElementWithinViewport(page, productModal);
  await productModal.getByRole("button", { name: "Tutup modal" }).click();
  await expect(productModal).toBeHidden();

  await productCard.getByRole("button", { name: /Stok/i }).click();
  const stockModal = page.getByRole("dialog", { name: "Tambah Stok" });
  await expect(stockModal).toBeVisible();
  await expect(
    stockModal.getByPlaceholder("email@example.com|password\nKEY-XXXX-1234")
  ).toBeVisible();
  await stockModal.getByRole("button", { name: "Batal" }).click();
  await expect(stockModal).toBeHidden();

  await productCard.getByRole("button", { name: "Kelola" }).click();
  const manageModal = page.getByRole("dialog", { name: "Kelola Stok" });
  await expect(manageModal).toBeVisible();
  await expect(manageModal.getByText("#1")).toBeVisible();
  await expect(
    manageModal.getByText("browser-stock-a@example.test|pass")
  ).toBeVisible();
  await expect(manageModal.getByRole("textbox")).toBeHidden();
  await manageModal
    .getByRole("button", { name: "Ubah akun stok" })
    .first()
    .click();
  await expect(manageModal.getByRole("textbox")).toBeVisible();
  await manageModal.getByRole("button", { name: "Batal" }).click();
  await expect(manageModal.getByRole("textbox")).toBeHidden();
  await manageModal.getByRole("button", { name: /Tambah stok baru/ }).click();

  const reopenedStockModal = page.getByRole("dialog", { name: "Tambah Stok" });
  await expect(reopenedStockModal).toBeVisible();
  await reopenedStockModal.getByRole("button", { name: "Batal" }).click();
  await expect(reopenedStockModal).toBeHidden();
}

// A badge narrower than its own longest word means the label wrapped per
// character, which is unreadable. Wrapping between words stays allowed.
async function expectStatusBadgesReadable(container) {
  const broken = await container
    .locator("span.rounded-full")
    .evaluateAll((elements) =>
      elements
        .filter((element) => {
          const rect = element.getBoundingClientRect();
          const style = window.getComputedStyle(element);

          return (
            style.display !== "none" &&
            style.visibility !== "hidden" &&
            rect.width > 0 &&
            (element.textContent || "").trim().length >= 3
          );
        })
        .map((element) => {
          const style = window.getComputedStyle(element);
          const context = document.createElement("canvas").getContext("2d");
          context.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
          const label = (element.textContent || "").trim();
          const longestWord = Math.max(
            ...label.split(/\s+/).map((word) => context.measureText(word).width)
          );

          return {
            label,
            width: Math.round(element.getBoundingClientRect().width),
            longestWord: Math.round(longestWord),
          };
        })
        .filter((element) => element.width + 1 < element.longestWord)
    );

  expect(
    broken,
    `badge labels must not wrap per character: ${JSON.stringify(broken)}`
  ).toEqual([]);
}

async function expectNoDocumentOverflow(page) {
  const metrics = await page.evaluate(() => {
    const roots = [
      document.documentElement,
      document.body,
      ...document.querySelectorAll(
        ".admin-page, .admin-dashboard-page, .admin-mini-shell"
      ),
    ];

    roots.forEach((root) => {
      root.style.setProperty("overflow-x", "visible", "important");
    });

    return {
      bodyScrollWidth: document.body.scrollWidth,
      documentScrollWidth: document.documentElement.scrollWidth,
      viewportWidth: window.innerWidth,
    };
  });

  expect(
    metrics.documentScrollWidth,
    "document should not overflow horizontally"
  ).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  expect(
    metrics.bodyScrollWidth,
    "body should not overflow horizontally"
  ).toBeLessThanOrEqual(metrics.viewportWidth + 1);
}

async function expectOneColumn(firstLocator, secondLocator) {
  const first = await firstLocator.boundingBox();
  const second = await secondLocator.boundingBox();

  expect(first).not.toBeNull();
  expect(second).not.toBeNull();
  const firstCenterX = first.x + first.width / 2;
  const secondCenterX = second.x + second.width / 2;
  expect(Math.abs(firstCenterX - secondCenterX)).toBeLessThanOrEqual(2);

  const [top, bottom] = first.y <= second.y ? [first, second] : [second, first];
  expect(bottom.y).toBeGreaterThanOrEqual(top.y + top.height - 2);
}

async function expectBefore(firstLocator, secondLocator) {
  const [first, second] = await Promise.all([
    firstLocator.first().boundingBox(),
    secondLocator.first().boundingBox(),
  ]);

  expect(first).not.toBeNull();
  expect(second).not.toBeNull();
  expect(first.y + first.height).toBeLessThanOrEqual(second.y + 2);
}

async function expectPrimaryActionsAtLeast44px(page) {
  const tooSmall = await page
    .locator("button, a[href]")
    .evaluateAll((elements) =>
      elements
        .filter((element) => {
          const style = window.getComputedStyle(element);
          const rect = element.getBoundingClientRect();

          return (
            style.display !== "none" &&
            style.visibility !== "hidden" &&
            rect.width > 0 &&
            rect.height > 0 &&
            (element.matches(".admin-dashboard-action") ||
              element.matches(".filter-pill"))
          );
        })
        .map((element) => ({
          label: (
            element.textContent ||
            element.getAttribute("aria-label") ||
            element.href ||
            element.tagName
          ).trim(),
          height: element.getBoundingClientRect().height,
        }))
        .filter((element) => Math.round(element.height) < 44)
    );

  expect(
    tooSmall,
    `brutalist actions below 44px: ${JSON.stringify(tooSmall)}`
  ).toEqual([]);
}

async function expectStrictTouchTargetsAtLeast44px(locator) {
  const tooSmall = await locator
    .locator("button, a[href]")
    .evaluateAll((elements) =>
      elements
        .filter((element) => {
          const style = window.getComputedStyle(element);
          const rect = element.getBoundingClientRect();

          return (
            style.display !== "none" &&
            style.visibility !== "hidden" &&
            rect.width > 0 &&
            rect.height > 0
          );
        })
        .map((element) => {
          const rect = element.getBoundingClientRect();

          return {
            label: (
              element.textContent ||
              element.getAttribute("aria-label") ||
              element.tagName
            ).trim(),
            width: rect.width,
            height: rect.height,
          };
        })
        .filter(
          ({ width, height }) =>
            Math.round(width) < 44 || Math.round(height) < 44
        )
    );

  expect(
    tooSmall,
    `touch targets below 44x44 CSS px: ${JSON.stringify(tooSmall)}`
  ).toEqual([]);
}

async function expectTouchTargetAtLeast44px(locator) {
  const box = await locator.boundingBox();

  expect(box).not.toBeNull();
  expect(Math.round(box.width)).toBeGreaterThanOrEqual(44);
  expect(Math.round(box.height)).toBeGreaterThanOrEqual(44);
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

// 1x1 transparent PNG: a real decodable image for isolated upload fixtures.
const adminUploadFixturePng = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
  "base64"
);

function readAdminColor(value) {
  const match =
    /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+))?\s*\)/.exec(
      value ?? ""
    );

  if (!match) {
    throw new Error(`Unsupported CSS color value: ${value}`);
  }

  return {
    red: Number(match[1]),
    green: Number(match[2]),
    blue: Number(match[3]),
    alpha: match[4] === undefined ? 1 : Number(match[4]),
  };
}

function adminColorTriplet(value) {
  const color = readAdminColor(value);

  return [color.red, color.green, color.blue];
}

function adminRelativeLuminance([red, green, blue]) {
  const channel = (value) => {
    const normalized = value / 255;

    return normalized <= 0.04045
      ? normalized / 12.92
      : ((normalized + 0.055) / 1.055) ** 2.4;
  };

  return (
    0.2126 * channel(red) + 0.7152 * channel(green) + 0.0722 * channel(blue)
  );
}

function adminContrastRatio(foreground, background) {
  const [lighter, darker] = [
    adminRelativeLuminance(foreground),
    adminRelativeLuminance(background),
  ].sort((left, right) => right - left);

  return (lighter + 0.05) / (darker + 0.05);
}

// Runs in the page: the AA verdict must use rendered pixels, so the text color
// is composited over the first opaque ancestor background.
const readAdminContrastSampleInBrowser = (element) => {
  const parse = (value) => {
    const match =
      /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+))?\s*\)/.exec(
        value ?? ""
      );

    if (!match) {
      throw new Error(`Unsupported CSS color value: ${value}`);
    }

    return {
      red: Number(match[1]),
      green: Number(match[2]),
      blue: Number(match[3]),
      alpha: match[4] === undefined ? 1 : Number(match[4]),
    };
  };
  const composite = (top, bottom) => ({
    red: top.red * top.alpha + bottom.red * (1 - top.alpha),
    green: top.green * top.alpha + bottom.green * (1 - top.alpha),
    blue: top.blue * top.alpha + bottom.blue * (1 - top.alpha),
  });

  let background = null;

  for (let node = element; node instanceof Element; node = node.parentElement) {
    const layer = parse(getComputedStyle(node).backgroundColor);

    if (layer.alpha === 0) {
      continue;
    }

    background = background === null ? layer : composite(layer, background);

    if (layer.alpha === 1) {
      break;
    }
  }

  background ??= { red: 255, green: 255, blue: 255 };

  const style = getComputedStyle(element);
  const foreground = parse(style.color);

  return {
    text: (element.textContent ?? "").trim().slice(0, 48),
    foreground: [foreground.red, foreground.green, foreground.blue].map(
      Math.round
    ),
    background: [background.red, background.green, background.blue].map(
      Math.round
    ),
    fontSize: Number.parseFloat(style.fontSize),
    fontWeight: Number.parseInt(style.fontWeight, 10) || 400,
  };
};

async function readAdminContrastSample(target) {
  return target.evaluate(readAdminContrastSampleInBrowser);
}

// Rendered ratios are kept outside Playwright's transient output directory so
// the issue evidence survives a green run.
function writeAdminContrastEvidence(fileName, payload) {
  const directory = path.resolve(__dirname, "../../artifacts/issue-602");

  mkdirSync(directory, { recursive: true });
  writeFileSync(
    path.join(directory, fileName),
    `${JSON.stringify(payload, null, 2)}\n`
  );
}

// Theme and hover changes animate colors, so evidence and verdicts must read a
// value that stopped changing instead of a mid-transition frame.
// Theme choice persists in local storage, so tests must converge on the wanted
// theme instead of assuming which one the previous step left behind.
async function setAdminTheme(page, theme) {
  for (let attempt = 0; attempt < 3; attempt += 1) {
    if ((await page.locator("html").getAttribute("data-theme")) === theme) {
      return;
    }

    await page.locator("[data-theme-toggle]").first().click();
  }

  await expect(page.locator("html")).toHaveAttribute("data-theme", theme);
}

async function readSettledAdminContrastSample(target) {
  let previous = null;

  for (let attempt = 0; attempt < 20; attempt += 1) {
    const sample = await readAdminContrastSample(target);

    if (
      previous !== null &&
      previous.foreground.join() === sample.foreground.join() &&
      previous.background.join() === sample.background.join()
    ) {
      return sample;
    }

    previous = sample;
    await target.page().waitForTimeout(50);
  }

  return previous;
}

function expectAdminContrastAtLeast(sample, label) {
  const ratio = adminContrastRatio(sample.foreground, sample.background);
  const largeText =
    sample.fontSize >= 24 ||
    (sample.fontSize >= 18.66 && sample.fontWeight >= 700);
  const threshold = largeText ? 3 : 4.5;

  expect(
    ratio,
    `${label} "${sample.text}" ${sample.fontSize}px/${sample.fontWeight} measured ${ratio.toFixed(2)}:1`
  ).toBeGreaterThanOrEqual(threshold);

  return {
    label,
    text: sample.text,
    rendered: `${sample.fontSize}px/${sample.fontWeight}`,
    foreground: `rgb(${sample.foreground.join(", ")})`,
    background: `rgb(${sample.background.join(", ")})`,
    ratio: Number(ratio.toFixed(2)),
    threshold,
  };
}

async function tabUntilUploadFocused(
  page,
  inputId,
  fromSelector,
  maxSteps = 160
) {
  await page.locator(fromSelector).focus();

  const input = page.locator(`#${inputId}`);

  for (let step = 0; step <= maxSteps; step += 1) {
    if (await input.evaluate((element) => element === document.activeElement)) {
      return;
    }

    if (step < maxSteps) {
      await page.keyboard.press("Tab");
    }
  }

  await expect(input).toBeFocused();
}

async function selectUploadFile(page, inputId, fileName) {
  const [chooser] = await Promise.all([
    page.waitForEvent("filechooser"),
    page.locator(`#${inputId}`).press("Enter"),
  ]);

  await chooser.setFiles({
    name: fileName,
    mimeType: "image/png",
    buffer: adminUploadFixturePng,
  });

  await expect(page.locator(`label[for="${inputId}"] + span`)).toHaveText(
    fileName
  );
}

// One contract for every admin upload slot: Tab reaches the native control,
// focus is visible on the label the operator sees, the target is >=44px, the
// native picker opens from the keyboard, and the chosen filename is fed back.
async function expectUploadControlContract(page, inputId, focusOrigin) {
  const input = page.locator(`#${inputId}`);
  const trigger = page.locator(`label[for="${inputId}"]`).last();

  await expect(input).toHaveAttribute("type", "file");
  await expect(trigger).toBeVisible();

  const box = await trigger.boundingBox();

  expect(box, `${inputId} trigger box`).not.toBeNull();
  expect(
    Math.round(box.width),
    `${inputId} trigger width`
  ).toBeGreaterThanOrEqual(44);
  expect(
    Math.round(box.height),
    `${inputId} trigger height`
  ).toBeGreaterThanOrEqual(44);

  await tabUntilUploadFocused(page, inputId, focusOrigin);
  await expect(input).toBeFocused();

  const indicator = await trigger.evaluate((element) => {
    const style = getComputedStyle(element);

    return {
      outlineStyle: style.outlineStyle,
      outlineWidth: Number.parseFloat(style.outlineWidth) || 0,
      outlineColor: style.outlineColor,
      surrounding: getComputedStyle(element.parentElement).backgroundColor,
    };
  });

  expect(indicator.outlineStyle, `${inputId} focus outline style`).not.toBe(
    "none"
  );
  expect(
    indicator.outlineWidth,
    `${inputId} focus outline width`
  ).toBeGreaterThanOrEqual(2);

  await expect
    .poll(
      async () => {
        const ring = await trigger.evaluate((element) => ({
          outlineColor: getComputedStyle(element).outlineColor,
          surrounding: getComputedStyle(element.parentElement).backgroundColor,
        }));

        return adminContrastRatio(
          adminColorTriplet(ring.outlineColor),
          adminColorTriplet(ring.surrounding)
        );
      },
      { message: `${inputId} focus ring contrast against its surface` }
    )
    .toBeGreaterThanOrEqual(3);

  // Shift+Tab must leave the control and Tab must return to it, so the native
  // input is reachable in both directions rather than only forward.
  await page.keyboard.press("Shift+Tab");
  await expect(input).not.toBeFocused();
  await page.keyboard.press("Tab");
  await expect(input).toBeFocused();

  await selectUploadFile(page, inputId, `${inputId}-terpilih.png`);
}

function observeRetiredCheckoutRequests(page) {
  const retiredRequests = [];

  page.on("request", (request) => {
    const url = request.url();

    if (url.includes("/modal") || /[?&]checkout=1(?:&|$)/.test(url)) {
      retiredRequests.push(url);
    }
  });

  return retiredRequests;
}

function observeCustomerNavigation(page) {
  const requests = [];
  const listener = (request) => {
    if (
      !request.isNavigationRequest() ||
      request.frame() !== page.mainFrame()
    ) {
      return;
    }

    const url = new URL(request.url());
    requests.push({
      method: request.method(),
      pathname: url.pathname,
    });
  };

  page.on("request", listener);

  return {
    requests,
    stop: () => page.off("request", listener),
  };
}

function expectCustomerNavigationSequence(flow, start, expected, label) {
  const actual = flow.requests
    .slice(start)
    .filter(({ method }) => method === "GET")
    .map(({ method, pathname }) => `${method} ${pathname}`);

  expect(
    actual,
    `${label} should have the expected document sequence`
  ).toHaveLength(expected.length);
  expected.forEach((matcher, index) => {
    expect(actual[index], `${label} navigation ${index + 1}`).toMatch(matcher);
  });
}

function expectSingleCustomerCheckoutSubmission(flow, start, label) {
  const checkoutRequests = flow.requests
    .slice(start)
    .filter(
      ({ method, pathname }) =>
        method === "POST" && pathname === "/shop/checkout"
    );
  const checkoutNavigations = flow.requests
    .slice(start)
    .filter(({ pathname }) =>
      /^\/shop\/checkout(?:\/products\/\d+)?$/.test(pathname)
    );

  expect(
    checkoutRequests,
    `${label} should submit checkout exactly once`
  ).toHaveLength(1);
  expect(
    checkoutNavigations,
    `${label} should visit review and submit exactly once`
  ).toHaveLength(2);
}
// Chromium's CDP page scale changes the visual viewport and proves the real
// 200% scale when supported. It does not change CSS media-query layout, so
// the documented fallback halves the CSS viewport to exercise browser-zoom
// reflow in headless or non-Chromium environments.
async function emulate200PercentCheckoutScale(context, page, viewport) {
  let cdp;

  try {
    cdp = await context.newCDPSession(page);
    await cdp.send("Emulation.setPageScaleFactor", { pageScaleFactor: 2 });

    const scale = await page.evaluate(() => window.visualViewport?.scale ?? 1);
    if (Math.abs(scale - 2) <= 0.01) {
      return {
        mode: "cdp-page-scale",
        fallbackViewport: {
          width: Math.max(1, Math.floor(viewport.width / 2)),
          height: Math.max(1, Math.floor(viewport.height / 2)),
        },
        reset: async () => {
          await cdp
            .send("Emulation.resetPageScaleFactor")
            .catch(() => undefined);
        },
      };
    }
  } catch {
    // Fall through to the CSS viewport fallback below.
  }

  if (cdp) {
    await cdp.send("Emulation.resetPageScaleFactor").catch(() => undefined);
  }

  const fallbackViewport = {
    width: Math.max(1, Math.floor(viewport.width / 2)),
    height: Math.max(1, Math.floor(viewport.height / 2)),
  };
  await page.setViewportSize(fallbackViewport);

  return {
    mode: "half-viewport-fallback",
    fallbackViewport,
    reset: async () => undefined,
  };
}

async function navigateToCheckoutFromProductCard(page, productName) {
  const card = page
    .locator("article.product-card")
    .filter({ hasText: productName })
    .first();
  const checkoutLink = card
    .locator('a[href*="/shop/checkout/products/"]')
    .filter({ hasText: "Beli sekarang" });

  await expect(card).toBeVisible();
  await expect(checkoutLink).toHaveCount(1);
  await card.evaluate((element) =>
    element.scrollIntoView({
      block: "center",
      inline: "nearest",
      behavior: "instant",
    })
  );
  await expect(card).toHaveCSS("transform", "none");
  await expect(card).toHaveCSS("opacity", "1");
  await checkoutLink.click({ force: true, noWaitAfter: true });
  await page.waitForLoadState("domcontentloaded");
  await expectStorefrontPageCheckout(page);
}

async function addProductCardToCart(page, productName) {
  const card = page
    .locator("article.product-card")
    .filter({ hasText: productName })
    .first();

  await expect(card).toBeVisible();
  await card
    .getByRole("button", { name: /^Tambahkan .+ ke keranjang$/ })
    .click();
  await expect(page).toHaveURL(/\/shop\/cart(?:\?|$)/);
}

async function completeManualCheckout(page, buyerName) {
  await expectStorefrontPageCheckout(page);
  await page.getByRole("textbox", { name: "Nama pembeli" }).fill(buyerName);
  await page.getByRole("checkbox", { name: /Saya menyetujui/ }).check();
  await page.locator("[data-checkout-sticky-submit]:visible").click();
  await expect(page).toHaveURL(/\/shop\/orders\/\d+/);
  await expect(
    page.getByRole("heading", { name: "Order QRIS dibuat" })
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Invoice QRIS" })
  ).toBeVisible();
  expect(new URL(page.url()).searchParams.get("signature")).not.toBeNull();
}

async function expectStorefrontPageCheckout(page) {
  await page.waitForLoadState("domcontentloaded");
  await expect(page).toHaveURL(/\/shop\/checkout(?:\/products\/\d+)?(?:\?|$)/);
  await expect(
    page.getByRole("heading", {
      name: /Review (produk|pesanan) sebelum bayar\./,
    })
  ).toBeVisible();
  await expect(page.locator("#checkout-form")).toBeVisible();
  expect(page.url()).not.toContain("checkout=1");
}

async function scrollCheckoutToSettledBottom(page) {
  await page.waitForFunction(() => {
    const fontsReady = !document.fonts || document.fonts.status === "loaded";
    const imagesReady = Array.from(document.images).every(
      (image) => image.complete
    );

    return fontsReady && imagesReady;
  });

  await page.evaluate(async () => {
    document.documentElement.style.scrollBehavior = "auto";
    document.body.style.scrollBehavior = "auto";
    const scrollingElement =
      document.scrollingElement ?? document.documentElement;
    const nextFrame = () =>
      new Promise((resolve) => window.requestAnimationFrame(resolve));
    let previousHeight = -1;
    let stableFrames = 0;

    for (let attempt = 0; attempt < 120; attempt += 1) {
      scrollingElement.scrollTop = scrollingElement.scrollHeight;
      await nextFrame();

      const maxScrollTop = Math.max(
        0,
        scrollingElement.scrollHeight - scrollingElement.clientHeight
      );
      const sticky = document.querySelector("[data-checkout-sticky-action]");
      const form = document.querySelector("#checkout-form");
      const stickyRect = sticky?.getBoundingClientRect();
      const formRect = form?.getBoundingClientRect();
      const atBottom = Math.abs(scrollingElement.scrollTop - maxScrollTop) <= 1;
      const formIsUncovered =
        stickyRect && formRect ? formRect.bottom <= stickyRect.top + 1 : false;
      const heightIsStable = scrollingElement.scrollHeight === previousHeight;

      if (atBottom && formIsUncovered && heightIsStable) {
        stableFrames += 1;
        if (stableFrames >= 2) {
          return;
        }
      } else {
        stableFrames = 0;
      }

      previousHeight = scrollingElement.scrollHeight;
    }

    throw new Error(
      `Checkout did not settle at the bottom: ${JSON.stringify({
        scrollTop: scrollingElement.scrollTop,
        maxScrollTop: Math.max(
          0,
          scrollingElement.scrollHeight - scrollingElement.clientHeight
        ),
        documentHeight: scrollingElement.scrollHeight,
        stickyTop: document
          .querySelector("[data-checkout-sticky-action]")
          ?.getBoundingClientRect().top,
        formBottom: document
          .querySelector("#checkout-form")
          ?.getBoundingClientRect().bottom,
      })}`
    );
  });
}

async function expectStorefrontCheckoutRecovery(page) {
  await page.waitForLoadState("domcontentloaded");
  await expect(page).toHaveURL(/\/shop\/checkout(?:\/products\/\d+)?(?:\?|$)/);
  await expect(
    page.getByRole("heading", {
      name: /Review (produk|pesanan) sebelum bayar\./,
    })
  ).toBeVisible();
  await expect(page.locator("#checkout-error-summary")).toBeVisible();
  expect(page.url()).not.toContain("checkout=1");
}

async function expectStorefrontCheckoutResponsiveContract(page, viewport) {
  const metrics = await page.evaluate(() => {
    const isVisible = (element) => {
      const style = window.getComputedStyle(element);
      const rect = element.getBoundingClientRect();

      return (
        style.display !== "none" &&
        style.visibility !== "hidden" &&
        style.opacity !== "0" &&
        rect.width > 0 &&
        rect.height > 0
      );
    };
    const isInViewport = (rect) =>
      rect.right > 0 &&
      rect.bottom > 0 &&
      rect.left < window.innerWidth &&
      rect.top < window.innerHeight;
    const overlaps = (first, second) =>
      Math.min(first.right, second.right) - Math.max(first.left, second.left) >
        1 &&
      Math.min(first.bottom, second.bottom) - Math.max(first.top, second.top) >
        1;
    const rectFor = (element) => {
      const rect = element.getBoundingClientRect();

      return {
        left: rect.left,
        right: rect.right,
        top: rect.top,
        bottom: rect.bottom,
        width: rect.width,
        height: rect.height,
      };
    };
    const checkoutControls = Array.from(
      document.querySelectorAll(
        [
          "#checkout-form button",
          "#checkout-form summary",
          "#checkout-form select",
          '#checkout-form input[type="text"]',
          '#checkout-form input[type="number"]',
          '#checkout-form input[type="email"]',
          '#checkout-form label[for="checkout-terms-accepted"]',
        ].join(", ")
      )
    ).filter(isVisible);
    const checkoutTargets = Array.from(
      document.querySelectorAll(
        [
          "#checkout-error-summary",
          "#checkout-form button",
          "#checkout-form summary",
          "#checkout-form select",
          '#checkout-form input:not([type="hidden"])',
          '#checkout-form label[for="checkout-terms-accepted"]',
        ].join(", ")
      )
    ).filter(isVisible);
    const visibleTargetRects = checkoutTargets
      .map(rectFor)
      .filter(isInViewport);
    const fixedSurfaceRects = Array.from(document.querySelectorAll("*"))
      .filter((element) => {
        if (!isVisible(element)) {
          return false;
        }

        const position = window.getComputedStyle(element).position;
        return position === "fixed" || position === "sticky";
      })
      .map(rectFor)
      .filter(isInViewport);
    const nestedScrollable = Array.from(document.querySelectorAll("main *"))
      .filter((element) => {
        const style = window.getComputedStyle(element);

        return (
          isVisible(element) &&
          (style.overflowY === "auto" || style.overflowY === "scroll") &&
          element.scrollHeight > element.clientHeight + 1
        );
      })
      .map((element) => element.id || element.className || element.tagName);
    const visibleDialogs = Array.from(
      document.querySelectorAll('[role="dialog"]')
    ).filter(isVisible);
    const normalTextSizes = [
      ...document.querySelectorAll(
        "#checkout-form > div > label, [data-checkout-item] p.text-sm, [data-checkout-payment-amount-help]"
      ),
    ]
      .filter(isVisible)
      .map((element) =>
        Number.parseFloat(window.getComputedStyle(element).fontSize)
      );
    const metadataTextSizes = [
      ...document.querySelectorAll(
        "[data-checkout-item] p, [data-checkout-item] span"
      ),
    ]
      .filter(isVisible)
      .map((element) =>
        Number.parseFloat(window.getComputedStyle(element).fontSize)
      );

    const horizontalOverflow = Array.from(document.querySelectorAll("body *"))
      .filter(isVisible)
      .map((element) => {
        const rect = element.getBoundingClientRect();

        return {
          label: element.id || element.className || element.tagName,
          left: rect.left,
          right: rect.right,
        };
      })
      .filter(({ left, right }) => left < -1 || right > window.innerWidth + 1);

    return {
      documentScrollWidth: document.documentElement.scrollWidth,
      bodyScrollWidth: document.body.scrollWidth,
      viewportWidth: window.innerWidth,
      horizontalOverflow,
      controls: checkoutControls.map((element) => {
        const rect = rectFor(element);

        return {
          label: (
            element.textContent ||
            element.getAttribute("aria-label") ||
            element.id
          ).trim(),
          width: rect.width,
          height: rect.height,
        };
      }),
      fixedOverlaps: fixedSurfaceRects.flatMap((surface) =>
        visibleTargetRects.filter((target) => overlaps(surface, target))
      ),
      nestedScrollable,
      visibleDialogs: visibleDialogs.length,
      normalTextSizes,
      metadataTextSizes,
    };
  });

  expect(metrics.documentScrollWidth).toBeLessThanOrEqual(viewport.width + 1);
  expect(metrics.bodyScrollWidth).toBeLessThanOrEqual(viewport.width + 1);
  expect(
    metrics.horizontalOverflow,
    `visible elements overflow ${metrics.viewportWidth}px viewport: ${JSON.stringify(
      metrics.horizontalOverflow
    )}`
  ).toEqual([]);
  expect(
    metrics.controls.filter(
      ({ width, height }) => Math.round(width) < 44 || Math.round(height) < 44
    )
  ).toEqual([]);
  expect(metrics.fixedOverlaps).toEqual([]);
  expect(metrics.nestedScrollable).toEqual([]);
  expect(metrics.visibleDialogs).toBe(0);
  expect(metrics.normalTextSizes.every((size) => size >= 14)).toBe(true);
  expect(metrics.metadataTextSizes.every((size) => size >= 12)).toBe(true);

  await expectStorefrontCheckoutFocusIndicators(page);

  if (viewport.width === 390) {
    const flowBudget = await page.evaluate(() => {
      window.scrollTo(0, 0);
      const submit = [
        ...document.querySelectorAll(
          "[data-checkout-submit], [data-checkout-sticky-submit]"
        ),
      ].find((element) => {
        const style = window.getComputedStyle(element);
        const rect = element.getBoundingClientRect();

        return (
          style.display !== "none" &&
          style.visibility !== "hidden" &&
          style.opacity !== "0" &&
          rect.width > 0 &&
          rect.height > 0
        );
      });
      if (!submit) {
        throw new Error("No visible checkout submit control");
      }
      const rect = submit.getBoundingClientRect();
      let positionedAncestor = null;
      for (let ancestor = submit; ancestor; ancestor = ancestor.parentElement) {
        if (
          ["fixed", "sticky"].includes(
            window.getComputedStyle(ancestor).position
          )
        ) {
          positionedAncestor = ancestor;
          break;
        }
      }
      const positionedRect = positionedAncestor?.getBoundingClientRect();

      return {
        submitTop: positionedRect
          ? positionedRect.top
          : rect.top + window.scrollY,
        viewportHeight: window.innerHeight,
        contactOpen: document.querySelector("#checkout-contact-details")?.open,
        paymentOpen:
          document.querySelector("#checkout-payment-disclosure")?.open ?? false,
      };
    });

    expect(flowBudget.submitTop).toBeLessThanOrEqual(
      flowBudget.viewportHeight * 2 + 1
    );
    expect(flowBudget.contactOpen).toBe(false);
    expect(flowBudget.paymentOpen).toBe(false);
  }

  const submit = page
    .locator(
      "[data-checkout-submit]:visible, [data-checkout-sticky-submit]:visible"
    )
    .first();
  await submit.evaluate((element) =>
    element.scrollIntoView({
      block: "center",
      inline: "nearest",
      behavior: "instant",
    })
  );
  if (await submit.isEnabled()) {
    await submit.focus();
    await expect(submit).toBeFocused();
  } else {
    await expect(submit).toHaveAttribute("aria-disabled", "true");
  }
  const submitBox = await submit.boundingBox();
  expect(submitBox).not.toBeNull();
  expect(submitBox.x).toBeGreaterThanOrEqual(0);
  expect(submitBox.y).toBeGreaterThanOrEqual(0);
  expect(submitBox.x + submitBox.width).toBeLessThanOrEqual(viewport.width + 1);
  expect(submitBox.y + submitBox.height).toBeLessThanOrEqual(
    viewport.height + 1
  );
}
async function expectStorefrontCheckoutFocusIndicators(page) {
  const focusable = page.locator(
    [
      "#checkout-form a[href]",
      "#checkout-form button",
      "#checkout-form summary",
      "#checkout-form select",
      '#checkout-form input:not([type="hidden"])',
    ].join(", ")
  );
  const reports = [];

  for (let index = 0; index < (await focusable.count()); index += 1) {
    const control = focusable.nth(index);
    if (!(await control.isVisible())) {
      continue;
    }

    await control.evaluate((element) =>
      element.scrollIntoView({
        block: "center",
        inline: "nearest",
        behavior: "instant",
      })
    );
    await control.focus();
    await expect(control).toBeFocused();
    reports.push(
      await control.evaluate((element) => {
        const style = window.getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        const outlineWidth = Number.parseFloat(style.outlineWidth) || 0;
        const outlineOffset = Number.parseFloat(style.outlineOffset) || 0;
        const ringPadding = Math.max(0, outlineWidth + outlineOffset);
        const ring = {
          left: rect.left - ringPadding,
          right: rect.right + ringPadding,
          top: rect.top - ringPadding,
          bottom: rect.bottom + ringPadding,
        };
        const clippedBy = [];

        for (
          let ancestor = element.parentElement;
          ancestor;
          ancestor = ancestor.parentElement
        ) {
          const ancestorStyle = window.getComputedStyle(ancestor);
          const clipsX = ["hidden", "clip", "auto", "scroll"].includes(
            ancestorStyle.overflowX
          );
          const clipsY = ["hidden", "clip", "auto", "scroll"].includes(
            ancestorStyle.overflowY
          );
          if (!clipsX && !clipsY) {
            continue;
          }

          const ancestorRect = ancestor.getBoundingClientRect();
          if (
            (clipsX &&
              (ring.left < ancestorRect.left - 1 ||
                ring.right > ancestorRect.right + 1)) ||
            (clipsY &&
              (ring.top < ancestorRect.top - 1 ||
                ring.bottom > ancestorRect.bottom + 1))
          ) {
            clippedBy.push(
              ancestor.id || ancestor.className || ancestor.tagName
            );
          }
        }

        return {
          label: (
            element.textContent ||
            element.getAttribute("aria-label") ||
            element.id ||
            element.tagName
          ).trim(),
          focusVisible: element.matches(":focus-visible"),
          outlineStyle: style.outlineStyle,
          outlineWidth,
          ring,
          viewport: {
            left: 0,
            right: window.innerWidth,
            top: 0,
            bottom: window.innerHeight,
          },
          clippedBy,
        };
      })
    );
  }

  const invalid = reports.filter(
    ({ focusVisible, outlineStyle, outlineWidth, ring, viewport, clippedBy }) =>
      !focusVisible ||
      outlineStyle === "none" ||
      outlineWidth <= 0 ||
      ring.left < viewport.left - 1 ||
      ring.right > viewport.right + 1 ||
      ring.top < viewport.top - 1 ||
      ring.bottom > viewport.bottom + 1 ||
      clippedBy.length > 0
  );

  expect(
    invalid,
    `focused checkout controls have clipped or missing focus indicators: ${JSON.stringify(
      invalid
    )}`
  ).toEqual([]);
}

async function settleSmoothScroll(page) {
  await page.evaluate(
    () =>
      new Promise((resolve) => {
        let previous = window.scrollY;
        let stableFrames = 0;

        const tick = () => {
          if (window.scrollY === previous) {
            stableFrames += 1;
          } else {
            stableFrames = 0;
            previous = window.scrollY;
          }

          if (stableFrames >= 5) {
            resolve();
            return;
          }

          requestAnimationFrame(tick);
        };

        requestAnimationFrame(tick);
      })
  );
}

async function startBrowserServer() {
  serverProcess = spawn(
    "php",
    [
      "-S",
      `127.0.0.1:${new URL(baseUrl).port}`,
      "-t",
      path.join(appDir, "public"),
      path.join(__dirname, "laravel-browser-router.php"),
    ],
    {
      cwd: appDir,
      env: appEnv(),
      stdio: ["ignore", "ignore", "ignore"],
    }
  );

  await waitForServer(`${baseUrl}/admin/login`);
}

async function stopBrowserServer() {
  const processToStop = serverProcess;
  serverProcess = null;

  if (!processToStop || processToStop.exitCode !== null) {
    return;
  }

  await new Promise((resolve) => {
    processToStop.once("exit", resolve);
    processToStop.kill();
  });
}

function resetResponsiveFixtures() {
  runArtisan(["migrate:fresh", "--force"]);
  runArtisan(["credentials:backfill-identity-registry"]);
  invoiceFixtures = JSON.parse(
    runArtisan(["tinker", "--execute", responsiveSeedScript])
  );
}

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
    CREDENTIAL_IDENTITY_FINGERPRINT_KEY: "browser-only-fingerprint-key",
    SESSION_DRIVER: "file",
    CACHE_STORE: "array",
    QUEUE_CONNECTION: "database",
    MAIL_MAILER: "log",
    // prettier-ignore
    DOGFOOD_FAKE_TELEGRAM_OUTBOUND: 'true',
    TELEGRAM_ADMIN_CHAT_ID: "998877",
    KLIKQRIS_SANDBOX_ENABLED: "true",
    KLIKQRIS_SANDBOX_API_KEY: "test-only-browser-key",
    KLIKQRIS_SANDBOX_MERCHANT_ID: "merchant-browser",
    KLIKQRIS_REPORTING_CACHE_SECONDS: "0",
    BINANCE_PAY_ID: "987654321",
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

  throw new Error(
    `Timed out waiting for ${url}: ${lastError?.message ?? "no response"}`
  );
}

// Test data setup: create one owner plus enough product and order data to measure
// mobile card layout as a real two-column grid rather than inferred from CSS.
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
use App\Models\ProductFamily;
use App\Models\ProductFamilySlugRedirect;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Models\CustomerLoginLink;
use App\Services\StoreBalance\StoreBalanceLedger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\Support\QrisTestPayload;

foreach ([
    'customer_usdt_balance_accounts',
    'customer_usdt_balance_entries',
    'customer_usdt_balance_accounting_events',
] as $tableName) {
    if (! Schema::hasTable($tableName)) {
        Schema::create($tableName, static function (Blueprint $table): void {
            $table->id();
        });
    }
}

User::query()->updateOrCreate(
    ['email' => 'test@example.com'],
    [
        'name' => 'Test User',
        'email_verified_at' => now(),
        'password' => Hash::make('password'),
        'role' => UserRole::Owner,
    ],
);

User::factory()->create([
    'email' => 'admin-browser@example.test',
    'name' => 'Browser QA Admin',
    'password' => Hash::make('password'),
    'role' => UserRole::Admin,
]);

Customer::query()->updateOrCreate(
    ['telegram_id' => 9007199254740993],
    [
        'first_name' => 'Balance Adjustment QA',
        'username' => 'balance_adjustment_qa',
        'locale' => 'id',
    ],
);

$balanceCustomer = Customer::query()->updateOrCreate(
    ['email' => 'balance-browser@example.test'],
    [
        'telegram_id' => 9007199254740995,
        'first_name' => 'Browser Balance Customer',
        'username' => 'balance_browser',
        'email_verified_at' => now(),
    ],
);
$loginToken = str_repeat('a', 64);
CustomerLoginLink::query()->create([
    'email' => $balanceCustomer->email,
    'token_hash' => hash('sha256', $loginToken),
    'expires_at' => now()->addMinutes(15),
    'requested_ip' => '127.0.0.1',
]);
app(StoreBalanceLedger::class)->creditTopUp(
    $balanceCustomer,
    12_345,
    'browser-balance-credit',
    'browser-balance:credit',
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
\App\Models\ManualPaymentSetting::query()->updateOrCreate(
    ['id' => 1],
    [
        'payment_mode' => 'manual',
        'binance_pay_enabled' => false,
        'is_active' => true,
    ],
);

$family = ProductFamily::query()->firstOrCreate(
    ['slug' => 'browser-responsive-family'],
    [
        'name' => 'Browser QA Product Family',
        'description' => 'Grouped family for admin browser coverage.',
        'sort_order' => 98,
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
    'is_active' => true,
    'product_family_id' => $family->id,
    'family_sort_order' => 1,
    'family_option_label' => 'QA option',
    'category_id' => $category->id,
    'name' => 'Browser QA Produk A',
    'description' => "Paket QA dengan deskripsi panjang.\nBaris pendukung kedua memuat detail panjang berulang-ulang untuk membuktikan bahwa konten pendukung tidak boleh mengubur harga dan tombol Beli pada layar sempit.",
    'product_type' => 'Akun QA',
    'access_duration' => '30 hari',
    'region' => 'Global',
    'account_type' => 'Private',
    'included_items' => "Akun siap pakai\nPanduan login",
    'limitations' => 'Tidak untuk dijual kembali',
    'fulfillment_expectation' => 'Dikirim setelah pembayaran terverifikasi.',
    'warranty_policy' => 'Garansi login 7 hari.',
    'support_policy' => 'Support pada jam operasional.',
    'price_amount' => 45000,
    'usdt_price_amount' => 2000000,
    'stock' => 9,
    'reserved_stock' => 0,
    'is_active' => true,
]);
ProductStockItem::create(['product_id' => $productA->id, 'secret' => 'browser-stock-a@example.test|pass']);
ProductStockItem::create(['product_id' => $productA->id, 'secret' => 'browser-stock-b@example.test|pass']);
foreach (range(3, 9) as $stockIndex) {
    ProductStockItem::create([
        'product_id' => $productA->id,
        'secret' => "browser-stock-{$stockIndex}@example.test|pass",
    ]);
}
Product::factory()->create([
    'is_active' => true,
    'product_family_id' => $family->id,
    'family_sort_order' => 2,
    'family_option_label' => 'QA secondary',
    'category_id' => $category->id,
    'name' => 'Browser QA Produk Family Secondary',
    'description' => 'Deskripsi varian sekunder untuk browser selector coverage.',
    'access_duration' => '60 hari',
    'price_amount' => 65000,
    'stock' => 4,
    'reserved_stock' => 0,
]);
Product::factory()->create([
    'category_id' => $category->id,
    'name' => 'Browser QA Produk A',
    'price_amount' => 45000,
    'usdt_price_amount' => 2000000,
    'stock' => 9,
    'reserved_stock' => 1,
    'is_active' => true,
]);
$tierProduct = Product::factory()->create([
    'category_id' => $otherCategory->id,
    'name' => 'Browser QA Produk B',
    'price_amount' => 75000,
    'stock' => 6,
    'reserved_stock' => 1,
    'is_active' => true,
]);
$tierProduct->quantityPriceTiers()->create([
    'minimum_quantity' => 2,
    'price_amount' => 70000,
    'usdt_price_amount' => null,
]);

$longCheckoutProduct = Product::factory()->create([
    'category_id' => $otherCategory->id,
    'name' => 'Browser QA Produk Dengan Nama Sangat Panjang Yang Harus Tetap Membungkus Tanpa Overflow',
    'description' => 'Long checkout fixture for responsive wrapping and reflow evidence.',
    'price_amount' => 88000,
    'usdt_price_amount' => 2_500_000,
    'stock' => 4,
    'reserved_stock' => 0,
    'is_active' => true,
]);
$unavailableProduct = Product::factory()->create([
    'category_id' => $otherCategory->id,
    'name' => 'Browser QA Sold Out Product',
    'description' => 'Unavailable Product fixture for waitlist browser evidence.',
    'image_path' => null,
    'price_amount' => 91000,
    'stock' => 0,
    'reserved_stock' => 0,
    'is_active' => true,
]);

$noImageProduct = Product::factory()->create([
    'category_id' => $otherCategory->id,
    'name' => 'Browser QA No Image Product',
    'description' => 'No-image Product fixture for fallback asset browser evidence.',
    'image_path' => null,
    'price_amount' => 52000,
    'stock' => 2,
    'reserved_stock' => 0,
    'is_active' => true,
]);

ProductFamilySlugRedirect::query()->updateOrCreate(
    ['old_slug' => 'browser-responsive-legacy'],
    ['product_family_id' => $family->id],
);


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
    'provider' => 'klikqris',
    'merchant_ref' => 'KLIK-BROWSER-MATCH',
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

$qrisFee = QrisTestPayload::officialFee(45000);
$qrisTotal = 45000 + $qrisFee;
$qrisPayload = QrisTestPayload::forAmount($qrisTotal, 'BROWSERPENDING');

Payment::factory()->create([
    'order_id' => $qrisOrder->id,
    'provider' => 'pakasir',
    'method' => 'api_qris',
    'provider_payment_method' => 'qris',
    'provider_payment_number' => $qrisPayload,
    'provider_payment_number_hash' => hash('sha256', $qrisPayload),
    'provider_fee' => $qrisFee,
    'provider_total_payment' => $qrisTotal,
    'amount' => 45000,
    'status' => PaymentStatus::Pending,
    'expired_at' => now()->addMinutes(15),
]);

$missingProviderTotalOrder = Order::factory()->create([
    'customer_id' => $customer->id,
    'invoice_number' => 'INV-BROWSER-QRIS-NO-FINAL',
    'order_status' => OrderStatus::PendingPayment,
    'payment_status' => PaymentStatus::Pending,
    'total_amount' => 45000,
    'expires_at' => now()->addMinutes(15),
]);

Payment::factory()->create([
    'order_id' => $missingProviderTotalOrder->id,
    'provider' => 'pakasir',
    'method' => 'api_qris',
    'provider_payment_method' => 'qris',
    'provider_payment_number' => '00020101021226610016ID.CO.QRIS.BROWSERNOFINAL99',
    'provider_payment_number_hash' => hash('sha256', '00020101021226610016ID.CO.QRIS.BROWSERNOFINAL99'),
    'provider_fee' => null,
    'provider_total_payment' => null,
    'provider_create_state' => 'provider_total_pending',
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
    'provider_payment_number' => '00020101021226610016ID.CO.QRIS.BROWSERSTATUSPENDING99',
    'provider_payment_number_hash' => hash('sha256', '00020101021226610016ID.CO.QRIS.BROWSERSTATUSPENDING99'),
    'provider_fee' => 350,
    'provider_total_payment' => 45350,
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

$reviewOrder = Order::factory()->create([
    'invoice_number' => 'INV-BROWSER-DELIVERY-REVIEW',
    'order_status' => OrderStatus::Processing,
    'payment_status' => PaymentStatus::Paid,
]);
$unknownEffect = \App\Models\TelegramOutboundEffect::factory()->create([
    'source_type' => Order::class,
    'source_id' => $reviewOrder->id,
    'effect_type' => \App\Services\Telegram\TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
    'state' => \App\Enums\TelegramOutboundEffectState::Unknown,
    'unknown_at' => now()->subMinutes(5),
    'last_error_code' => 'telegram_transport_ambiguous',
    'outcome_metadata' => [
        'exception' => 'ConnectionException',
        'raw_payload' => 'BROWSER-PRIVATE-OUTCOME',
    ],
]);

echo json_encode([
    'unknownEffectPath' => route('admin.telegram-outbound-effects.show', $unknownEffect, false),
    'adminOrderPath' => route('admin.orders.show', $paidInvoiceOrder, false),
    'longProductCheckoutPath' => route('storefront.shop.checkout.product', ['product' => $longCheckoutProduct], false),
    'tierProductCheckoutPath' => route('storefront.shop.checkout.product', ['product' => $tierProduct], false),
    'pendingWithNativeQris' => [
        'orderId' => $qrisOrder->id,
        'showUrl' => URL::signedRoute('storefront.shop.orders.show', ['order' => $qrisOrder, 'key' => 'browser-recovery-key'], null, false),
        'statusPath' => parse_url(URL::signedRoute('storefront.shop.orders.status', $qrisOrder, null, false), PHP_URL_PATH),
    ],
    'pendingStatusRefresh' => [
        'orderId' => $statusPendingOrder->id,
        'showUrl' => URL::signedRoute('storefront.shop.orders.show', $statusPendingOrder, null, false),
    ],
    'pendingMissingProviderTotal' => [
        'orderId' => $missingProviderTotalOrder->id,
        'showUrl' => URL::signedRoute('storefront.shop.orders.show', $missingProviderTotalOrder, null, false),
        'statusPath' => parse_url(URL::signedRoute('storefront.shop.orders.status', $missingProviderTotalOrder, null, false), PHP_URL_PATH),
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
