import { expect, test } from "@playwright/test";
import { spawn, spawnSync } from "node:child_process";
import { mkdtempSync, openSync, closeSync, rmSync } from "node:fs";
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

test.beforeAll(async () => {
  const tempDir = mkdtempSync(
    path.join(tmpdir(), "telegram-bot-admin-responsive-")
  );
  dbPath = path.join(tempDir, "database.sqlite");
  closeSync(openSync(dbPath, "w"));

  baseUrl = `http://127.0.0.1:${await findAvailablePort()}`;

  runArtisan(["optimize:clear"]);
  runArtisan(["migrate:fresh", "--force"]);
  runArtisan(["credentials:backfill-identity-registry"]);
  invoiceFixtures = JSON.parse(
    runArtisan(["tinker", "--execute", responsiveSeedScript])
  );

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
});

test.afterAll(async () => {
  if (serverProcess && !serverProcess.killed) {
    serverProcess.kill();
  }

  if (dbPath) {
    rmSync(path.dirname(dbPath), { recursive: true, force: true });
  }
});

test("keeps customer checkout modal keyboard accessible and restores failed state", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const productCard = page
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk A" })
    .first();
  await productCard.scrollIntoViewIfNeeded();
  await productCard.focus();
  await page.keyboard.press("Enter");

  let dialog = page.getByRole("dialog", { name: "Browser QA Produk A" });
  await expect(dialog).toBeVisible();
  await expect(
    dialog.locator('input[type="radio"][name="payment_choice"]').first()
  ).toBeAttached();
  await expect(
    dialog.locator("fieldset").getByText("Bayar via QR Manual", { exact: true })
  ).toBeVisible();
  await expectTouchTargetAtLeast44px(dialog.getByLabel("Tutup detail produk"));
  await expectTouchTargetAtLeast44px(dialog.getByLabel("Tambah jumlah"));

  await page.evaluate(() => document.activeElement?.blur());
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(productCard).toBeFocused();

  await page.keyboard.press("Enter");
  dialog = page.getByRole("dialog", { name: "Browser QA Produk A" });
  await expect(dialog).toBeVisible();
  await expect(dialog.locator("#storefront-buyer-name")).toBeFocused();
  await dialog.getByLabel("Tambah jumlah").click();
  await expect(dialog.locator('[aria-live="polite"]')).toHaveText(
    "Jumlah 2. Total produk Rp90.000."
  );
  await dialog.locator("#storefront-buyer-name").fill("Browser Buyer");
  await expect(dialog.locator("#storefront-buyer-name")).toHaveValue(
    "Browser Buyer"
  );
  await page.waitForTimeout(100);
  await dialog
    .locator("#storefront-terms-accepted")
    .evaluate((input) => input.removeAttribute("required"));
  await dialog.getByRole("button", { name: /Bayar/i }).click();
  await page.waitForLoadState("domcontentloaded");
  dialog = page.getByRole("dialog", { name: "Browser QA Produk A" });
  await expect(dialog).toBeVisible();
  await expect(dialog.locator("#storefront-buyer-name")).toHaveValue(
    "Browser Buyer"
  );
  await expect(dialog.locator("#storefront-buyer-name")).toBeFocused();
  await expect(dialog.locator("#storefront-checkout-error")).toBeVisible();
  await expect(
    dialog.locator("[data-storefront-optional-support]")
  ).not.toHaveAttribute("open");
  await expectNoDocumentOverflow(page);
});

test("keeps customer checkout lazy modal loading cancellable and accessible", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const productCard = page
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk A" })
    .first();
  const productId = Number(
    (await productCard.getAttribute("id")).replace("product-", "")
  );
  const productUrl = await productCard.getAttribute("href");
  const modalUrlPattern = `**/produk/${productId}/modal`;
  let releaseRequest;
  let holdNextRequest = true;
  const requestHeld = new Promise((resolve) => {
    releaseRequest = resolve;
  });

  await page.route(modalUrlPattern, async (route) => {
    if (holdNextRequest) {
      holdNextRequest = false;
      await requestHeld;
    }

    try {
      await route.continue();
    } catch {
      // The browser may abort the held request when the modal closes.
    }
  });

  try {
    await page.evaluate(
      ({ productId, productUrl }) => {
        const root = document.querySelector('[x-data="storefrontModal()"]');
        const modal = root?._x_dataStack?.[0];
        const opener = document.getElementById(`product-${productId}`);

        if (!modal || !opener) {
          throw new Error("Storefront modal component is unavailable");
        }

        window.__lazyModalPromise = modal.openProductModalFromUrl(
          `/produk/${productId}/modal`,
          {
            opener,
            fallbackUrl: productUrl,
            quantity: 2,
            buyerName: "Lazy Buyer",
            termsAccepted: true,
            email: "lazy@example.test",
          }
        );
      },
      { productId, productUrl }
    );

    const loadingDialog = page.getByRole("dialog", {
      name: "Memuat detail produk",
    });
    await expect(loadingDialog).toBeVisible();
    await expect(page.locator("body")).toHaveCSS("overflow", "hidden");

    await loadingDialog.getByLabel("Tutup detail produk").click();
    await expect(loadingDialog).toBeHidden();
    await expect(productCard).toBeFocused();

    releaseRequest();
    await page.evaluate(() => window.__lazyModalPromise);
    await expect(
      page.getByRole("dialog", { name: "Browser QA Produk A" })
    ).toHaveCount(0);

    await page.evaluate(
      ({ productId, productUrl }) => {
        const root = document.querySelector('[x-data="storefrontModal()"]');
        const modal = root?._x_dataStack?.[0];
        const opener = document.getElementById(`product-${productId}`);

        window.__lazyModalPromise = modal.openProductModalFromUrl(
          `/produk/${productId}/modal`,
          {
            opener,
            fallbackUrl: productUrl,
            quantity: 2,
            buyerName: "Lazy Buyer",
            termsAccepted: true,
            email: "lazy@example.test",
          }
        );
      },
      { productId, productUrl }
    );

    const dialog = page.getByRole("dialog", {
      name: "Browser QA Produk A",
    });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator("#storefront-buyer-name")).toHaveValue(
      "Lazy Buyer"
    );
    await expect(dialog.locator("#storefront-email")).toHaveValue(
      "lazy@example.test"
    );
    await expect(dialog.locator("#storefront-terms-accepted")).toBeChecked();
    await expect(dialog.locator("span.min-w-8")).toHaveText("2");

    await page.keyboard.press("Escape");
    await expect(dialog).toBeHidden();
    await expect(productCard).toBeFocused();
  } finally {
    releaseRequest();
    await page.unroute(modalUrlPattern);
  }
});

test("keeps customer checkout lazy modal failures on the product page", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const productCard = page
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk A" })
    .first();
  const productId = Number(
    (await productCard.getAttribute("id")).replace("product-", "")
  );
  const productUrl = await productCard.getAttribute("href");
  const modalUrlPattern = `**/produk/${productId}/modal`;
  const productPage = page.waitForURL(
    (url) => url.pathname === `/produk/${productId}`
  );

  await page.route(modalUrlPattern, (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        status: "fallback",
        product: null,
        reason: "checkout_unavailable",
        fallback_url: productUrl,
      }),
    })
  );

  try {
    await page.evaluate(
      ({ productId, productUrl }) => {
        const root = document.querySelector('[x-data="storefrontModal()"]');
        const modal = root?._x_dataStack?.[0];
        const opener = document.getElementById(`product-${productId}`);

        window.__lazyModalPromise = modal.openProductModalFromUrl(
          `/produk/${productId}/modal`,
          { opener, fallbackUrl: productUrl }
        );
      },
      { productId, productUrl }
    );
    await productPage;
    await expect(
      page.getByRole("heading", { name: "Browser QA Produk A" })
    ).toBeVisible();
  } finally {
    await page.unroute(modalUrlPattern);
  }

  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  const networkFailureNavigation = page.waitForURL(
    (url) => url.pathname === `/produk/${productId}`
  );
  await page.route(modalUrlPattern, (route) => route.abort());

  try {
    await page.evaluate(
      ({ productId, productUrl }) => {
        const root = document.querySelector('[x-data="storefrontModal()"]');
        const modal = root?._x_dataStack?.[0];
        const opener = document.getElementById(`product-${productId}`);

        window.__lazyModalPromise = modal.openProductModalFromUrl(
          `/produk/${productId}/modal`,
          { opener, fallbackUrl: productUrl }
        );
      },
      { productId, productUrl }
    );
    await networkFailureNavigation;
    await expect(
      page.getByRole("heading", { name: "Browser QA Produk A" })
    ).toBeVisible();
  } finally {
    await page.unroute(modalUrlPattern);
  }
});

test("restores customer checkout errors on the shop catalog through lazy modal", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });

  const productCard = page
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk A" })
    .first();
  await expect(productCard).toBeVisible();

  const productId = Number(
    (await productCard.getAttribute("id")).replace("product-", "")
  );
  expect(await productCard.getAttribute("data-product")).toBeNull();
  const modalResponse = page.waitForResponse(
    (response) =>
      response.url() === `${baseUrl}/produk/${productId}/modal` &&
      response.request().method() === "GET"
  );

  await page.evaluate((productId) => {
    const root = document.querySelector('[x-data="storefrontModal()"]');
    const modal = root?._x_dataStack?.[0];

    if (!modal) {
      throw new Error("Storefront modal component is unavailable");
    }

    modal.restoreCheckout = {
      productId,
      quantity: 2,
      buyerName: "Restored Buyer",
      termsAccepted: true,
      supportContactChannel: "",
      supportContactValue: "",
      email: "restored@example.test",
      paymentMethod: "qris",
    };
    modal.reopenFailedCheckout();
  }, productId);

  await modalResponse;
  const dialog = page.getByRole("dialog", {
    name: "Browser QA Produk A",
  });

  await expect(dialog).toBeVisible();
  await expect(page.locator("body")).toHaveCSS("overflow", "hidden");
  await expect(dialog.locator("#storefront-buyer-name")).toHaveValue(
    "Restored Buyer"
  );
  await expect(dialog.locator("#storefront-email")).toHaveValue(
    "restored@example.test"
  );
  await expect(dialog.locator("#storefront-terms-accepted")).toBeChecked();
  await expect(dialog.locator("span.min-w-8")).toHaveText("2");

  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(productCard).toBeFocused();
  await expectNoDocumentOverflow(page);
});

test("keeps customer checkout QRIS-first across browser locales", async ({
  browser,
}) => {
  runArtisan([
    "tinker",
    "--execute",
    "App\\Models\\ManualPaymentSetting::query()->updateOrCreate(['id' => 1], ['payment_mode' => 'manual', 'binance_pay_enabled' => true, 'binance_verification_mode' => 'order_id', 'binance_order_id_compatibility_ready_at' => now(), 'is_active' => true]);",
  ]);

  for (const locale of ["id-ID", "en-US"]) {
    const context = await browser.newContext({
      locale,
      extraHTTPHeaders: { "Accept-Language": locale },
      viewport: { width: 390, height: 900 },
    });
    const page = await context.newPage();

    try {
      await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

      const productCard = page
        .locator("a[data-modal-url]")
        .filter({ hasText: "Browser QA Produk A" })
        .first();
      await productCard.click();

      const dialog = page.getByRole("dialog", {
        name: "Browser QA Produk A",
      });
      const paymentMethods = dialog.locator(
        'input[type="radio"][name="payment_choice"]'
      );
      const qris = dialog.locator(
        'input[type="radio"][name="payment_choice"][value="qris"]'
      );
      const binance = dialog.locator(
        'input[type="radio"][name="payment_choice"][value="binance_pay"]'
      );
      const scrollRegion = dialog.locator("[data-storefront-checkout-scroll]");
      const optionalSupport = dialog.locator(
        "[data-storefront-optional-support]"
      );
      const prepurchaseSupport = dialog.locator(
        "[data-storefront-prepurchase-support]"
      );
      const submitButton = dialog.locator("[data-storefront-checkout-submit]");

      await expect(paymentMethods).toHaveCount(2);
      expect(
        await paymentMethods.evaluateAll((inputs) =>
          inputs.map((input) => input.value)
        )
      ).toEqual(["qris", "binance_pay"]);
      await expect(qris).toBeChecked();
      await expect(binance).not.toBeChecked();
      await expect(scrollRegion).toHaveCSS("overflow-y", "auto");
      await expect(scrollRegion).toHaveCSS("overscroll-behavior-y", "contain");
      await expect(optionalSupport).not.toHaveAttribute("open");
      await expect(prepurchaseSupport).not.toHaveAttribute("open");
      await expectElementWithinViewport(page, submitButton);
      await expect(
        dialog.getByRole("button", { name: "Bayar via QR Manual" })
      ).toBeVisible();
      await expect(
        dialog.getByText(
          "Total produk di atas belum termasuk biaya QRIS. Biaya dan total QRIS final ditentukan provider setelah QRIS dibuat. Bayar hanya nominal total pada invoice QRIS."
        )
      ).toBeVisible();

      await dialog
        .locator('label[for="storefront-payment-method-binance_pay"]')
        .click();
      await expect(binance).toBeChecked();
      await expect(qris).not.toBeChecked();
      await expect(
        dialog.getByRole("button", { name: /Binance Pay/ })
      ).toBeVisible();
      await expect(
        dialog.getByText(
          "Choose a Binance payment rail before submitting. The final rail is sent explicitly with this checkout."
        )
      ).toBeVisible();

      await dialog
        .locator('label[for="storefront-payment-method-qris"]')
        .click();
      await expect(qris).toBeChecked();
      await expect(
        dialog.getByRole("button", { name: "Bayar via QR Manual" })
      ).toBeVisible();
    } finally {
      await context.close();
    }
  }
});

test("keeps the customer QRIS submit button stable while hovering", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  await page
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk A" })
    .first()
    .click();

  const dialog = page.getByRole("dialog", { name: "Browser QA Produk A" });
  const submitButton = dialog.locator("[data-storefront-checkout-submit]");

  await expect(submitButton).toBeVisible();
  await submitButton.hover();

  await expect
    .poll(() =>
      submitButton.evaluate(
        (button) => window.getComputedStyle(button).animationName
      )
    )
    .toBe("none");
  await expect
    .poll(() =>
      submitButton.evaluate(
        (button) => window.getComputedStyle(button).transform
      )
    )
    .toBe("none");

  const firstBox = await submitButton.boundingBox();
  await page.waitForTimeout(600);
  const secondBox = await submitButton.boundingBox();

  expect(firstBox).not.toBeNull();
  expect(secondBox).not.toBeNull();
  expect(
    Math.abs((secondBox?.x ?? 0) - (firstBox?.x ?? 0))
  ).toBeLessThanOrEqual(1);
  expect(
    Math.abs((secondBox?.y ?? 0) - (firstBox?.y ?? 0))
  ).toBeLessThanOrEqual(1);
});

test("respects reduced motion in the customer checkout modal", async ({
  page,
}) => {
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  await page
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk A" })
    .first()
    .click();

  const dialog = page.getByRole("dialog", { name: "Browser QA Produk A" });
  const motion = await dialog.evaluate((element) => {
    const duration = getComputedStyle(element).transitionDuration;
    const durations = duration.split(",").map((value) => value.trim());

    return {
      prefersReducedMotion: window.matchMedia(
        "(prefers-reduced-motion: reduce)"
      ).matches,
      maxDurationMs: Math.max(
        ...durations.map((value) =>
          value.endsWith("ms")
            ? Number.parseFloat(value)
            : Number.parseFloat(value) * 1000
        )
      ),
    };
  });

  expect(motion.prefersReducedMotion).toBe(true);
  expect(motion.maxDurationMs).toBeLessThanOrEqual(1);
});

test("restores QRIS for customer checkout when Binance becomes unavailable", async ({
  page,
}) => {
  runArtisan([
    "tinker",
    "--execute",
    "App\\Models\\ManualPaymentSetting::query()->updateOrCreate(['id' => 1], ['payment_mode' => 'manual', 'binance_pay_enabled' => true, 'binance_verification_mode' => 'order_id', 'binance_order_id_compatibility_ready_at' => now(), 'is_active' => true]);",
  ]);

  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const productCard = page
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk A" })
    .first();
  await productCard.click();

  const dialog = page.getByRole("dialog", { name: "Browser QA Produk A" });
  const binance = dialog.locator(
    'input[type="radio"][name="payment_choice"][value="binance_pay"]'
  );
  await expect(binance).toBeVisible();
  await dialog
    .locator('label[for="storefront-payment-method-binance_pay"]')
    .click();
  await dialog.getByLabel("Nama pembeli").fill("Browser Buyer");

  runArtisan([
    "tinker",
    "--execute",
    "App\\Models\\ManualPaymentSetting::query()->firstOrFail()->update(['binance_pay_enabled' => false]);",
  ]);

  await dialog
    .locator("#storefront-terms-accepted")
    .evaluate((input) => input.removeAttribute("required"));
  await dialog.getByRole("button", { name: /Binance Pay/ }).click();
  await page.waitForLoadState("domcontentloaded");

  const restoredDialog = page.getByRole("dialog", {
    name: "Browser QA Produk A",
  });
  const qris = restoredDialog.locator(
    'input[type="radio"][name="payment_choice"][value="qris"]'
  );

  await expect(restoredDialog).toBeVisible();
  await expect(qris).toBeChecked();
  await expect(qris).toBeEnabled();
  await expect(
    restoredDialog.getByRole("button", { name: "Bayar via QR Manual" })
  ).toBeEnabled();
});

test("opens the selected product checkout from the landing catalog", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const productCard = page
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk A" })
    .first();
  await productCard.scrollIntoViewIfNeeded();
  await productCard.click();

  const dialog = page.getByRole("dialog", { name: "Browser QA Produk A" });
  await expect(dialog).toBeVisible();
  await expect(
    dialog.locator('form:has(#storefront-buyer-name) input[name="product_id"]')
  ).toHaveValue(/^\d+$/);
  await expect(dialog.locator("#storefront-buyer-name")).toBeFocused();
  await expectNoDocumentOverflow(page);
});

test("opens the selected product checkout from a direct product URL", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });

  const productHref = await page
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk A" })
    .first()
    .getAttribute("href");
  const productUrl = new URL(productHref, baseUrl);
  productUrl.searchParams.set("checkout", "1");

  await page.goto(productUrl.toString(), { waitUntil: "domcontentloaded" });

  const dialog = page.getByRole("dialog", { name: "Browser QA Produk A" });
  await expect(dialog).toBeVisible();
  await expect(
    dialog.locator('form:has(#storefront-buyer-name) input[name="product_id"]')
  ).toHaveValue(/^\d+$/);
  await expect(
    page.getByRole("link", { name: "Kembali ke katalog" })
  ).toHaveAttribute("href", /#product-\d+$/);
  await expectNoDocumentOverflow(page);
});

test("keeps the direct product fallback usable without the checkout modal", async ({
  browser,
}) => {
  const context = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 390, height: 900 },
  });
  const page = await context.newPage();

  try {
    await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
    const productHref = await page
      .locator("a[data-modal-url]")
      .filter({ hasText: "Browser QA Produk A" })
      .first()
      .getAttribute("href");
    const productUrl = new URL(productHref, baseUrl);
    productUrl.searchParams.set("checkout", "1");

    await page.goto(productUrl.toString(), { waitUntil: "domcontentloaded" });

    await expect(
      page.getByRole("heading", { name: "Browser QA Produk A" })
    ).toBeVisible();
    await expect(
      page.getByRole("button", { name: "Bayar via QR Manual" })
    ).toBeVisible();
    await expect(
      page.getByRole("spinbutton", { name: "Jumlah", exact: true })
    ).toHaveValue("1");
    await expect(page.getByLabel("Nama pembeli")).toBeVisible();
    await expect(
      page.getByRole("dialog", { name: "Browser QA Produk A" })
    ).toHaveCount(0);
    await expectNoDocumentOverflow(page);
  } finally {
    await context.close();
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

  await page.clock.runFor(20_000);
  expect(calls).toBe(1);
  await page.clock.runFor(10_000);
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

test("uses a compact two-column shop grid and keeps focus on the active filter at mobile width", async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 900 });
  await page.goto(`${baseUrl}/shop`, { waitUntil: "domcontentloaded" });

  const catalogGrid = page.locator("#storefront-product-grid");
  const productA = catalogGrid
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk A" })
    .first();
  const productB = catalogGrid
    .locator("a[data-modal-url]")
    .filter({ hasText: "Browser QA Produk B" })
    .first();
  await page.waitForTimeout(700);
  await expectTwoColumns(productA, productB);

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
    [320, 2],
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
    menu.getByRole("link", { name: "Akun pelanggan" })
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
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await installClipboardProbe(page);
  await login(page);

  await expect(page.getByRole("heading", { name: "Test User" })).toBeVisible();
  await expect(
    page.getByRole("navigation", { name: "Navigasi desktop" })
  ).toBeVisible();
  await expect(
    page.getByRole("navigation", { name: "Navigasi dashboard" })
  ).toBeHidden();
  await expect(
    page.getByRole("button", { name: "Bagikan Toko" })
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

  await expectNoDocumentOverflow(page);
  await expectPrimaryActionsAtLeast44px(page);

  await page.goto(`${baseUrl}/admin?tab=produk`);
  await exerciseProductManagementModals(page);
  await expectNoDocumentOverflow(page);
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
test("uses a compact sticky shell header on internal admin tabs", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);

  for (const [tab, label] of [
    ["produk", "Produk"],
    ["penjualan", "Pesanan"],
    ["broadcast", "Siaran"],
    ["telegram-channel", "Store Channel"],
    ["funnel", "Funnel Storefront"],
  ]) {
    await page.goto(`${baseUrl}/admin?tab=${tab}`);

    const compactHeader = page.getByRole("banner", {
      name: "Navigasi section admin",
    });
    await expect(
      page.locator("header").filter({ hasText: "Halo," })
    ).toHaveCount(0);
    await expect(compactHeader).toBeVisible();
    await expect(
      compactHeader.getByRole("heading", { name: label, exact: true })
    ).toBeVisible();
    await expect(compactHeader).toContainText("Toko aktif");
    await expect(compactHeader).toHaveCSS("position", "sticky");
    await expect(
      compactHeader.getByRole("link", { name: "Buka Toko" })
    ).toHaveCount(0);
    await expect(
      compactHeader.getByRole("button", { name: "Bagikan Toko" })
    ).toHaveCount(0);

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    const headerBox = await compactHeader.boundingBox();
    expect(headerBox?.y ?? -1).toBeGreaterThanOrEqual(0);
  }
});

test("keeps responsive navigation and utility actions usable", async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 740 });
  await login(page);

  const mobileNav = page.getByRole("navigation", {
    name: "Navigasi dashboard",
  });
  await expect(mobileNav).toBeVisible();
  await expect(
    mobileNav.getByRole("button", { name: "Ringkasan", exact: true })
  ).toBeVisible();
  await expect(
    mobileNav.getByRole("button", { name: "Pesanan", exact: true })
  ).toBeVisible();
  await expect(
    mobileNav.getByRole("button", { name: "Produk", exact: true })
  ).toBeVisible();
  await expect(
    mobileNav.getByRole("button", { name: "Lainnya", exact: true })
  ).toBeVisible();
  await expect(
    mobileNav.getByRole("button", { name: "Ringkasan", exact: true })
  ).toHaveAttribute("aria-current", "page");
  await expect(
    mobileNav.getByRole("button", { name: "Siaran", exact: true })
  ).toHaveCount(0);

  const mobileNavMetrics = await mobileNav.evaluate((element) => {
    const buttons = [...element.querySelectorAll("button")].map((button) => {
      const rect = button.getBoundingClientRect();

      return { left: rect.left, right: rect.right };
    });

    return {
      right: element.getBoundingClientRect().right,
      viewportWidth: window.innerWidth,
      buttons,
    };
  });

  expect(mobileNavMetrics.right).toBeLessThanOrEqual(
    mobileNavMetrics.viewportWidth + 1
  );
  expect(
    Math.max(...mobileNavMetrics.buttons.map((button) => button.right))
  ).toBeLessThanOrEqual(mobileNavMetrics.viewportWidth + 1);

  await mobileNav.getByRole("button", { name: "Lainnya", exact: true }).click();

  const moreSheet = page.getByRole("dialog", { name: "Menu lainnya" });
  await expect(moreSheet).toBeVisible();
  await expect(
    moreSheet.getByRole("button", { name: "Siaran", exact: true })
  ).toBeVisible();
  await expect(
    moreSheet.getByRole("button", { name: "Store Channel", exact: true })
  ).toBeVisible();
  await expect(
    moreSheet.getByRole("button", { name: "Funnel storefront", exact: true })
  ).toBeVisible();
  await expect(
    moreSheet.getByRole("button", { name: "Tambah Produk", exact: true })
  ).toBeVisible();
  await expect(
    moreSheet.getByRole("button", { name: "Siaran", exact: true })
  ).toBeFocused();

  const closeMenu = moreSheet.getByRole("button", { name: "Tutup menu" });
  const lastUtilityAction = moreSheet.getByRole("button", {
    name: "Tambah Produk",
    exact: true,
  });
  await lastUtilityAction.focus();
  await page.keyboard.press("Tab");
  await expect(closeMenu).toBeFocused();
  await closeMenu.focus();
  await page.keyboard.press("Shift+Tab");
  await expect(lastUtilityAction).toBeFocused();

  await page.keyboard.press("Escape");
  await expect(moreSheet).toBeHidden();
  await expect(
    mobileNav.getByRole("button", { name: "Lainnya", exact: true })
  ).toBeFocused();

  await page.goto(`${baseUrl}/admin?tab=broadcast`);
  const broadcastTextarea = page.locator("#broadcast-message");
  await broadcastTextarea.scrollIntoViewIfNeeded();

  const broadcastOverlap = await page.evaluate(() => {
    const nav = document
      .querySelector(".mobile-bottom-nav")
      ?.getBoundingClientRect();
    const field = document
      .querySelector("#broadcast-message")
      ?.getBoundingClientRect();

    if (!nav || !field) {
      return -1;
    }

    return Math.max(
      0,
      Math.min(nav.bottom, field.bottom) - Math.max(nav.top, field.top)
    );
  });

  expect(broadcastOverlap).toBe(0);

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`${baseUrl}/admin?tab=produk`);

  const desktopNav = page.getByRole("navigation", { name: "Navigasi desktop" });
  const desktopButtons = desktopNav.getByRole("button");
  const desktopBoxes = await desktopButtons.evaluateAll((buttons) =>
    buttons.map((button) => {
      const rect = button.getBoundingClientRect();

      return { top: Math.round(rect.top), bottom: Math.round(rect.bottom) };
    })
  );

  await expect(desktopButtons).toHaveCount(9);
  expect(new Set(desktopBoxes.map((box) => box.top)).size).toBe(1);
  expect(new Set(desktopBoxes.map((box) => box.bottom)).size).toBe(1);
  await expect(
    desktopNav.getByRole("button", { name: "Produk", exact: true })
  ).toHaveAttribute("aria-current", "page");
});

test("closes the mobile utility sheet from outside click and restores its trigger", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);

  const nav = page.getByRole("navigation", { name: "Navigasi dashboard" });
  const trigger = nav.getByRole("button", { name: "Lainnya", exact: true });
  await trigger.click();

  const sheet = page.getByRole("dialog", { name: "Menu lainnya" });
  await expect(sheet).toBeVisible();
  await page.mouse.click(6, 120);
  await expect(sheet).toBeHidden();
  await expect(trigger).toBeFocused();
});

for (const width of [768, 1024, 1280, 1440, 1920]) {
  test(`keeps all desktop navigation items reachable without overflow at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    await login(page);

    const desktopNav = page.getByRole("navigation", {
      name: "Navigasi desktop",
    });
    await expect(desktopNav).toBeVisible();
    await expect(
      page.getByRole("navigation", { name: "Navigasi dashboard" })
    ).toBeHidden();

    const desktopButtons = desktopNav.getByRole("button");
    const desktopBoxes = await desktopButtons.evaluateAll((buttons) =>
      buttons.map((button) => {
        const rect = button.getBoundingClientRect();

        return { top: Math.round(rect.top), bottom: Math.round(rect.bottom) };
      })
    );

    await expect(desktopButtons).toHaveCount(9);
    if (width >= 1024) {
      expect(new Set(desktopBoxes.map((box) => box.top)).size).toBe(1);
      expect(new Set(desktopBoxes.map((box) => box.bottom)).size).toBe(1);
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

test("keeps fixed navigation below channel fields and modal surfaces", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);

  const mobileNav = page.getByRole("navigation", {
    name: "Navigasi dashboard",
  });
  await expect(
    mobileNav.getByRole("button", { name: "Ringkasan", exact: true })
  ).toBeVisible();
  await expect(
    mobileNav.getByRole("button", { name: "Pesanan", exact: true })
  ).toBeVisible();
  await expect(
    mobileNav.getByRole("button", { name: "Produk", exact: true })
  ).toBeVisible();
  await expect(
    mobileNav.getByRole("button", { name: "Lainnya", exact: true })
  ).toBeVisible();
  await expectNoDocumentOverflow(page);

  await page.goto(`${baseUrl}/admin?tab=telegram-channel`);

  const channelLink = page.getByRole("textbox", {
    name: "Link channel publik",
  });
  await channelLink.scrollIntoViewIfNeeded();
  const channelOverlap = await page.evaluate(() => {
    const nav = document
      .querySelector(".mobile-bottom-nav")
      ?.getBoundingClientRect();
    const field = document
      .querySelector("#store-channel-link")
      ?.getBoundingClientRect();

    if (!nav || !field) {
      return -1;
    }

    return Math.max(
      0,
      Math.min(nav.bottom, field.bottom) - Math.max(nav.top, field.top)
    );
  });

  expect(channelOverlap).toBe(0);

  await page.goto(`${baseUrl}/admin?tab=produk`);
  await page.getByRole("button", { name: /Tambah Produk/i }).click();

  const productModal = page.getByRole("dialog", { name: /Tambah produk/i });
  await expect(productModal).toBeVisible();
  await expectElementWithinViewport(page, productModal);
  const layerMetrics = await page.evaluate(() => {
    const nav = document.querySelector(".mobile-bottom-nav");
    const modalBackdrop =
      document.querySelector("#product-modal")?.parentElement;

    return {
      navZIndex: Number.parseInt(window.getComputedStyle(nav).zIndex, 10),
      modalZIndex: Number.parseInt(
        window.getComputedStyle(modalBackdrop).zIndex,
        10
      ),
    };
  });

  expect(layerMetrics.modalZIndex).toBeGreaterThan(layerMetrics.navZIndex);
});

test("keeps tabs, cards, and the stock modal usable at Telegram mini app width", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 900 });
  await login(page);

  await expectNoDocumentOverflow(page);
  await expectBottomNavUsable(page);
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
  await expect(page.locator("#product-1")).toBeVisible();
  await expectOneColumn(
    page.locator("#product-1"),
    page.locator('article[id^="product-"]').filter({
      hasText: "Browser QA Produk B",
    })
  );
  await expectNoDocumentOverflow(page);
  await submitProductFromMiniAppModal(page);

  const productCard = page.locator("#product-1");
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

  const productCard = page.locator("#product-1");
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
});

test("keeps structured Account Input reviewed, bounded, and private in page memory", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);

  const productCard = page.locator("#product-1");
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
    name: "desktop/tablet navigation",
    viewport: { width: 1024, height: 900 },
    navigationName: "Navigasi desktop",
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
  await page.goto(`${baseUrl}/admin?tab=penjualan&sales_filter=waiting`);

  const nav = page.getByRole("navigation", { name: "Navigasi desktop" });
  await nav.getByRole("button", { name: "Produk", exact: true }).click();
  await page.waitForURL(`${baseUrl}/admin?tab=produk&sales_filter=waiting`);
  await nav.getByRole("button", { name: "Pesanan", exact: true }).click();

  await expect(page).toHaveURL(
    `${baseUrl}/admin?tab=penjualan&sales_filter=waiting`
  );
});

for (const broadcastDirectCase of [
  {
    name: "desktop",
    viewport: { width: 1024, height: 900 },
    navigationName: "Navigasi desktop",
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
      await expect(
        nav.getByRole("button", { name: "Lainnya", exact: true })
      ).toHaveClass(/bottom-nav-active/);
    } else {
      await expect(
        nav.getByRole("button", { name: "Siaran", exact: true })
      ).toHaveClass(/bottom-nav-active/);
    }
  });
}

async function expectClickedDashboardControlsToWork(page, navigationName) {
  const nav = page.getByRole("navigation", { name: navigationName });
  const isMobileNav = navigationName === "Navigasi dashboard";
  await expect(nav).toBeVisible();

  await nav.getByRole("button", { name: "Produk", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: /Produk Saya/ })
  ).toBeVisible();
  await expect(page.locator("#product-1")).toBeVisible();

  await nav.getByRole("button", { name: "Pesanan", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: /Penjualan Saya/ })
  ).toBeVisible();
  await expect(page.getByText("INV-BROWSER-A")).toBeVisible();

  if (isMobileNav) {
    await clickMobileUtility(page, nav, "Store Channel");
  } else {
    await nav.getByRole("button", { name: "Channel", exact: true }).click();
  }
  await expect(
    page.getByRole("region", { name: "Telegram Channel" })
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Setup Store Channel" })
  ).toBeVisible();

  if (isMobileNav) {
    await clickMobileUtility(page, nav, "Siaran");
    await expect(
      nav.getByRole("button", { name: "Lainnya", exact: true })
    ).toHaveClass(/bottom-nav-active/);
  } else {
    const broadcastButton = nav.getByRole("button", {
      name: "Siaran",
      exact: true,
    });
    await broadcastButton.click();
    await expect(broadcastButton).toHaveClass(/bottom-nav-active/);
  }
  await expect(
    page.locator("#admin-section-broadcast h2").first()
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Status Siaran" })
  ).toBeVisible();

  if (isMobileNav) {
    await clickMobileUtility(page, nav, "Tambah Produk");
  } else {
    await nav.getByRole("button", { name: "Tambah", exact: true }).click();
  }
  const productModal = page.getByRole("dialog", { name: /Tambah produk/i });
  await expect(productModal).toBeVisible();
  await expect(productModal.locator("#product-name")).toBeVisible();
  await expect(
    page.getByRole("heading", { name: /Produk Saya/ })
  ).toBeVisible();
}

async function clickMobileUtility(page, nav, actionName) {
  await nav.getByRole("button", { name: "Lainnya", exact: true }).click();
  const sheet = page.getByRole("dialog", { name: "Menu lainnya" });
  await expect(sheet).toBeVisible();
  await sheet.getByRole("button", { name: actionName, exact: true }).click();
}

test("saves product name and icon edits from the admin modal", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await login(page);
  await page.goto(`${baseUrl}/admin?tab=produk`);

  const productCard = page
    .locator("article[id^='product-']")
    .filter({ hasText: "Browser QA Produk A" })
    .first();
  const familyGroup = page
    .locator('[id^="admin-product-family-group-"]')
    .filter({ hasText: "Browser QA Product Family" })
    .first();
  await familyGroup.getByRole("button", { name: "Lihat product" }).click();
  await expect(productCard).toBeVisible();
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
  await expect(
    page
      .locator("article:visible")
      .filter({ hasText: "Browser QA Produk A Edited" })
  ).toBeVisible();
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

async function login(page) {
  await page.goto(`${baseUrl}/admin/login`);
  await page.getByLabel("Username / email admin").fill("test@example.com");
  await page.locator('input[type="password"]').fill("password");
  await page.getByRole("button", { name: /sign in|login|masuk/i }).click();
  await page.waitForURL(`${baseUrl}/admin`);
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

  await page.getByRole("button", { name: "Bagikan Toko" }).click();
  await expect(
    page.locator("header").getByRole("button", { name: "Tersalin" })
  ).toBeVisible();
  await expect
    .poll(() => page.evaluate(() => window.__copiedText))
    .toBe(visibleStoreUrl);
}

async function exerciseProductManagementModals(page) {
  const productCard = page.locator("#product-1");
  const familyGroup = page
    .locator('[id^="admin-product-family-group-"]')
    .filter({ has: productCard })
    .first();
  await familyGroup.getByRole("button", { name: "Lihat product" }).click();
  await expect(productCard).toBeVisible();

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

async function expectBottomNavUsable(page) {
  const nav = page.getByRole("navigation", { name: "Navigasi dashboard" });
  await expect(nav).toBeVisible();
  await expect(nav).toHaveCSS("position", "fixed");
  await expect(nav.getByText("Ringkasan")).toBeVisible();
  await expect(nav.getByText("Produk")).toBeVisible();
  await expect(nav.getByText("Pesanan")).toBeVisible();
  await expect(nav.getByText("Lainnya")).toBeVisible();

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
  const firstCenterX = first.x + first.width / 2;
  const secondCenterX = second.x + second.width / 2;
  expect(Math.abs(firstCenterX - secondCenterX)).toBeLessThanOrEqual(2);

  const [top, bottom] = first.y <= second.y ? [first, second] : [second, first];
  expect(bottom.y).toBeGreaterThanOrEqual(top.y + top.height - 2);
}

async function expectTwoColumns(firstLocator, secondLocator) {
  const first = await firstLocator.boundingBox();
  const second = await secondLocator.boundingBox();

  expect(first).not.toBeNull();
  expect(second).not.toBeNull();
  expect(Math.abs(first.y - second.y)).toBeLessThanOrEqual(2);
  expect(second.x).toBeGreaterThanOrEqual(first.x + first.width - 2);
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
use App\Models\ProductStockItem;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\Support\QrisTestPayload;

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
    'price_amount' => 45000,
    'usdt_price_amount' => 2000000,
    'stock' => 0,
    'reserved_stock' => 0,
    'is_active' => true,
]);
ProductStockItem::create(['product_id' => $productA->id, 'secret' => 'browser-stock-a@example.test|pass']);
ProductStockItem::create(['product_id' => $productA->id, 'secret' => 'browser-stock-b@example.test|pass']);
Product::factory()->create([
    'category_id' => $category->id,
    'name' => 'Browser QA Produk A',
    'price_amount' => 45000,
    'usdt_price_amount' => 2000000,
    'stock' => 9,
    'reserved_stock' => 1,
    'is_active' => true,
]);
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

echo json_encode([
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
