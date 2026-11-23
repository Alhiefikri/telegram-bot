import { expect, test } from "@playwright/test";
import { spawn, execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import net from "node:net";
import path from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";

const appDir = fileURLToPath(new URL("../..", import.meta.url));
const qrFixturePayload =
  "DISPOSABLE-STOREFRONT-NOT-PAYABLE:" + "0123456789".repeat(18);
let baseUrl, fixture, server, invoiceUrl, options;
let localizedFixtures;

test.beforeAll(async () => {
  fixture = mkdtempSync(path.join(tmpdir(), "storefront-home-"));
  const db = path.join(fixture, "catalog.sqlite");
  writeFileSync(db, "");
  const port = await new Promise((resolve, reject) => {
    const socket = net.createServer().once("error", reject);
    socket.listen(0, "127.0.0.1", () => {
      const { port } = socket.address();
      socket.close(() => resolve(port));
    });
  });
  baseUrl = `http://127.0.0.1:${port}`;
  options = {
    cwd: appDir,
    env: {
      ...process.env,
      APP_ENV: "testing",
      APP_URL: baseUrl,
      APP_FORCE_HTTPS: "false",
      TRUSTED_HOSTS: "",
      APP_CONFIG_CACHE: path.join(fixture, "config.php"),
      APP_KEY: `base64:${Buffer.alloc(32, 1).toString("base64")}`,
      DB_CONNECTION: "sqlite",
      DB_DATABASE: db,
      DB_URL: "",
      CACHE_STORE: "array",
      QUEUE_CONNECTION: "sync",
      SESSION_DRIVER: "database",
      TELEGRAM_BOT_TOKEN: "",
      STOREFRONT_RELEASE_FIXTURE: "1",
    },
  };
  writeFileSync(path.join(fixture, "provider-state"), "PENDING");
  execFileSync("php", ["artisan", "migrate", "--force"], options);
  localizedFixtures = JSON.parse(
    execFileSync("php", ["artisan", "tinker", "--execute", seed], options)
  );
  invoiceUrl = baseUrl + localizedFixtures.unavailable;
  server = spawn(
    "php",
    [
      "-S",
      `127.0.0.1:${port}`,
      "-t",
      "public",
      "tests/Browser/laravel-browser-router.php",
    ],
    { ...options, stdio: "ignore" }
  );
  await expect
    .poll(async () => (await fetch(baseUrl).catch(() => null))?.status)
    .toBe(200);
});

test.afterAll(() => {
  server?.kill();
  if (fixture) rmSync(fixture, { recursive: true, force: true });
});

test.beforeEach(async ({ context }) => {
  // Never render the actual storefront's static payment QR in test artifacts.
  await context.route(`${baseUrl}/qr-lhie.jpg`, fulfillFixtureQr);
});

const sizes = [
  [1366, 768],
  [390, 844],
  [320, 568],
];

for (const [width, height] of sizes) {
  for (const theme of ["light", "dark"]) {
    for (const text of width === 320 ? [100, 200] : [100]) {
      test(`release layout ${width}x${height} ${theme} ${text}%`, async ({
        page,
      }, info) => {
        await page.setViewportSize({ width, height });
        await page.addInitScript(
          (value) => localStorage.setItem("lhie-store-theme", value),
          theme
        );
        await page.route(/^(?!http:\/\/127\.0\.0\.1:)/, (route) =>
          route.abort()
        );
        await routeFixtureQr(page);
        const measurements = {};
        for (const [name, url, selectors] of [
          [
            "home",
            "/",
            ["[data-home-plan]:first-child, #products article:first-of-type"],
          ],
          [
            "review",
            localizedFixtures.review,
            [
              "[data-checkout-submit]:visible, [data-checkout-sticky-submit]:visible",
            ],
          ],
          [
            "invoice",
            localizedFixtures.ready,
            [
              "[data-qris-image]",
              '.invoice-qris-total strong, .invoice-payment-panel dd:has-text("Rp35.016")',
              ".invoice-qris-deadline time, .invoice-payment-panel dl > div:last-child > dd",
            ],
          ],
        ]) {
          await page.goto(baseUrl + url);
          await expect(page.locator("html")).toHaveAttribute(
            "data-theme",
            theme
          );
          if (text === 200)
            await page.addStyleTag({ content: "html { font-size: 200%; }" });
          await page.evaluate(() => document.fonts.ready);
          const targets = selectors.map((selector) =>
            page.locator(selector).first()
          );
          const bounds = [];
          for (const target of targets) {
            await expect(target).toBeVisible();
            const rect = await target.boundingBox();
            bounds.push(Math.max(0, Math.ceil(rect.y + rect.height - height)));
          }
          const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - innerWidth
          );
          if (
            await page
              .locator(
                "#home-catalog-plans, .invoice-qris-total, .checkout-submit"
              )
              .count()
          ) {
            expect(overflow).toBe(0);
            if (width >= 390) expect(bounds).toEqual(targets.map(() => 0));
          }
          if (name === "invoice" && text === 200) {
            const actions = [
              page.locator("[data-payment-status-check]"),
              page.locator("#invoice-support > summary"),
            ];
            for (const action of actions) {
              await action.scrollIntoViewIfNeeded();
              await expect(action).toBeInViewport({ ratio: 1 });
              const { x, width: actionWidth } = await action.boundingBox();
              expect(x).toBeGreaterThanOrEqual(0);
              expect(x + actionWidth).toBeLessThanOrEqual(width);
            }
            await page.evaluate(() =>
              window.scrollTo({ top: 0, behavior: "instant" })
            );
          }
          const contrast = {};
          const pairs = {
            home: {
              buy: "[data-home-plan] .home-catalog-primary, #products article a[href*='/checkout/']",
              family: ".home-family-link[aria-current]",
              placeholder: "#catalog-search, #storefront-search",
            },
            review: { action: "[data-checkout-submit]:visible" },
            invoice: {
              total: selectors[1],
              deadline: selectors[2],
              instruction:
                "[data-invoice-qris-instruction], [data-payment-status-message]",
              status: ".invoice-status, [data-payment-status-title]",
            },
          }[name];
          for (const [key, selector] of Object.entries(pairs)) {
            const element = page.locator(selector).first();
            if (await element.count()) {
              contrast[key] = await contrastRatio(
                element,
                key === "placeholder" ? "::placeholder" : null
              );
            }
          }
          measurements[name] = { scrollToBottomPx: bounds, overflow, contrast };
          await page.screenshot({
            path: info.outputPath(`${name}.png`),
            animations: "disabled",
          });
          if (
            name === "home" &&
            (await page.locator("#catalog-search").count())
          ) {
            const search = page.locator("#catalog-search");
            await page.getByRole("search").getByRole("button").focus();
            await page.keyboard.press("Shift+Tab");
            await expect(search).toBeFocused();
            const outline = await search.evaluate(
              (element) => getComputedStyle(element).outlineStyle
            );
            expect(outline).toBe("solid");
            const focus = await contrastRatio(search, null, true);
            expect(focus).toBeGreaterThanOrEqual(3);
            contrast.focus = focus;
            await page.screenshot({ path: info.outputPath("focus.png") });
            await page.keyboard.press("Tab");
            await expect(
              page.getByRole("search").getByRole("button")
            ).toBeFocused();
          }
        }
        writeFileSync(
          info.outputPath("geometry.json"),
          JSON.stringify(measurements, null, 2)
        );
      });
    }
  }
}

async function contrastRatio(locator, pseudo, outline = false) {
  return locator.evaluate(
    (element, { pseudo, outline }) => {
      const context = document
        .createElement("canvas")
        .getContext("2d", { willReadFrequently: true });
      const rgba = (color) => {
        context.clearRect(0, 0, 1, 1);
        context.fillStyle = color;
        context.fillRect(0, 0, 1, 1);
        return [...context.getImageData(0, 0, 1, 1).data];
      };
      const over = ([r, g, b, alpha], background) =>
        [r, g, b].map(
          (value, i) => (value * alpha + background[i] * (255 - alpha)) / 255
        );
      const stack = [];
      for (
        let node = outline ? element.parentElement : element;
        node;
        node = node.parentElement
      ) {
        stack.unshift(rgba(getComputedStyle(node).backgroundColor));
      }
      const background = stack.reduce(
        (under, color) => over(color, under),
        [255, 255, 255]
      );
      const style = getComputedStyle(element, pseudo);
      const foreground = over(
        rgba(outline ? style.outlineColor : style.color),
        background
      );
      const luminance = (rgb) =>
        rgb.reduce((sum, channel, i) => {
          const value = channel / 255;
          return (
            sum +
            [0.2126, 0.7152, 0.0722][i] *
              (value <= 0.04045
                ? value / 12.92
                : ((value + 0.055) / 1.055) ** 2.4)
          );
        }, 0);
      const [lighter, darker] = [
        luminance(foreground),
        luminance(background),
      ].sort((a, b) => b - a);
      return Number(((lighter + 0.05) / (darker + 0.05)).toFixed(2));
    },
    { pseudo, outline }
  );
}

function routeFixtureQr(page) {
  return page.route(
    "https://klikqris.com/storage/sandbox/qris_release_fixture.png",
    fulfillFixtureQr
  );
}

function fulfillFixtureQr(route) {
  return route.fulfill({
    contentType: "image/png",
    body: Buffer.from(localizedFixtures.qr, "base64"),
  });
}

for (const [width, height] of sizes.slice(0, 2)) {
  for (const theme of ["light", "dark"]) {
    test(`release QR decode ${width}x${height} ${theme}`, async ({
      page,
    }, info) => {
      test.skip(
        process.env.STOREFRONT_QR_DECODE !== "1",
        "Enable with STOREFRONT_QR_DECODE=1 and ZBar installed"
      );
      await page.setViewportSize({ width, height });
      await page.addInitScript(
        (value) => localStorage.setItem("lhie-store-theme", value),
        theme
      );
      await page.route(/^(?!http:\/\/127\.0\.0\.1:)/, (route) => route.abort());
      await routeFixtureQr(page);
      await page.goto(baseUrl + localizedFixtures.ready);
      await expect(page.locator("html")).toHaveAttribute("data-theme", theme);
      await expect(page.locator(".invoice-qris-total strong")).toHaveText(
        "Rp35.016"
      );
      const frame = page.locator(".invoice-qris-frame");
      const image = frame.locator("img");
      await image.evaluate((img) => img.decode());
      for (const target of [
        frame,
        page.locator(".invoice-qris-total"),
        page.locator(".invoice-qris-deadline"),
      ]) {
        await expect(target).toBeInViewport({ ratio: 1 });
      }
      const snapshot = info.outputPath("synthetic-qr.png");
      await frame.screenshot({ path: snapshot, scale: "css" });
      const decoded = execFileSync("zbarimg", ["-q", "--raw", snapshot], {
        encoding: "utf8",
      });
      expect(decoded).toBe(`${qrFixturePayload}\n`);
      const dimensions = await frame.boundingBox();
      const imageDimensions = await image.boundingBox();
      writeFileSync(
        info.outputPath("qr-decode.json"),
        JSON.stringify(
          {
            viewport: { width, height },
            theme,
            frame: { width: dimensions.width, height: dimensions.height },
            image: {
              width: imageDimensions.width,
              height: imageDimensions.height,
            },
            payload: qrFixturePayload,
          },
          null,
          2
        )
      );
    });
  }
}

for (const [locale, language, headline, badge, manualWait, cancelled] of [
  [
    "id",
    "Bahasa Indonesia",
    "Batas pembayaran terlewati",
    "Batas bayar terlewati",
    "tunggu konfirmasi admin",
    "Order dibatalkan",
  ],
  [
    "ms",
    "Bahasa Melayu",
    "Tempoh bayaran tamat",
    "Tempoh bayaran tamat",
    "tunggu pengesahan admin",
    "Pesanan dibatalkan",
  ],
  [
    "en",
    "English",
    "Payment deadline passed",
    "Payment deadline passed",
    "wait for staff confirmation",
    "Order cancelled",
  ],
]) {
  for (const rail of ["ready", "manual"]) {
    test(`release QRIS deadline ${rail} ${locale} without navigation`, async ({
      page,
    }, info) => {
      await page.route(/^(?!http:\/\/127\.0\.0\.1:)/, (route) => route.abort());
      await routeFixtureQr(page);
      await page.goto(baseUrl);
      await page.locator("[data-storefront-locale] summary").click();
      await page.getByRole("link", { name: language }).click();
      const deadlineMs = Date.parse(localizedFixtures[`${rail}Deadline`]);
      await page.clock.install({ time: deadlineMs - 11_000 });
      await page.clock.pauseAt(deadlineMs - 10_000);

      let automaticChecks = 0;
      await page.route("**/shop/orders/*/status**", async (route) => {
        if (route.request().headers()["x-storefront-auto-poll"] === "1") {
          automaticChecks++;
        }
        await route.fulfill({
          json: {
            ok: true,
            is_paid: false,
            is_terminal: true,
            payment_status: "failed",
            order_status: "cancelled",
            message: "Verified terminal fixture",
          },
        });
      });
      const url = baseUrl + localizedFixtures[rail];
      await page.goto(url);
      await expect(page.locator("html")).toHaveAttribute("lang", locale);
      const qr = page.locator("[data-invoice-qris]");
      const total = page.locator(".invoice-qris-total strong");
      const deadline = page.locator(".invoice-qris-deadline time");
      const deadlineText = await deadline.innerText();
      const timer = page.getByRole("timer");
      await expect(qr).toBeVisible();
      await expect(total).toHaveText(
        rail === "manual" ? "Rp35.000" : "Rp35.016"
      );
      await expect(timer).toHaveText("00:00:10");
      const announcement = page.locator("[data-qris-deadline-announcement]");
      await expect(announcement).toBeEmpty();
      await announcement.evaluate((element) => {
        window.deadlineAnnouncements = 0;
        new MutationObserver(() => window.deadlineAnnouncements++).observe(
          element,
          { childList: true }
        );
      });
      // A fractional last second still displays permission to pay until the deadline.
      await page.clock.setSystemTime(deadlineMs - 9_500);
      await page.clock.runFor(9_000);
      await expect(timer).toHaveText("00:00:01");
      await expect(qr).toBeVisible();
      await page.clock.runFor(1_000);
      await expect(qr).toBeHidden();
      await expect(
        page.locator("[data-invoice-qris-instruction]")
      ).toBeHidden();
      await expect(total).toBeHidden();
      await expect(timer).toBeHidden();
      await expect(deadline).toHaveText(deadlineText);
      await expect(page.getByRole("heading", { level: 1 })).toHaveText(
        headline
      );
      await expect(page.locator("[data-invoice-badge]")).toHaveText(badge);
      // A deadline is not a terminal Order state.
      await expect(page.locator("[data-invoice-state]")).toHaveAttribute(
        "data-invoice-state",
        "pending"
      );
      await expect(page).toHaveURL(url);
      await expect(announcement).toContainText(headline);
      const notice = page.locator("[data-qris-deadline-notice]");
      await expect(notice).toBeVisible();
      if (rail === "manual") {
        await expect(notice).toContainText(manualWait);
        await expect(
          page.locator("[data-shop-payment-status], input[type=file]")
        ).toHaveCount(0);
        await page.locator(".invoice-qris-help").click();
        await expect(page.locator("#invoice-support")).toHaveAttribute(
          "open",
          ""
        );
      } else {
        await expect(page.locator("[data-payment-status-check]")).toBeEnabled();
        await expect(
          page.locator("[data-payment-status-followup] a")
        ).toBeVisible();
      }
      await notice.screenshot({ path: info.outputPath("deadline-notice.png") });
      await page.evaluate(() =>
        document.dispatchEvent(new Event("visibilitychange"))
      );
      await page.clock.runFor(21_000);
      expect(await page.evaluate(() => window.deadlineAnnouncements)).toBe(1);
      if (rail === "ready") {
        await expect(page.locator("[data-payment-status-message]")).toHaveText(
          "Verified terminal fixture"
        );
        await expect(page.locator("[data-invoice-state]")).toHaveAttribute(
          "data-invoice-state",
          "terminal"
        );
        await expect(page.getByRole("heading", { level: 1 })).toHaveText(
          cancelled
        );
        await expect(notice).toBeHidden();
        await expect(qr).toBeHidden();
        expect(automaticChecks).toBe(1);
      } else {
        expect(automaticChecks).toBe(0);
        // Reopen against the same pending Payment, now beyond the saved deadline
        // on the server as well. The server does not manufacture an Order outcome.
        const providerState = path.join(fixture, "provider-state");
        writeFileSync(providerState, "EXPIRED");
        try {
          expect((await page.goto(url)).status()).toBe(200);
          await expect(page.getByRole("heading", { level: 1 })).toHaveText(
            headline
          );
          await expect(page.locator("[data-invoice-badge]")).toHaveText(badge);
          await expect(qr).toHaveCount(0);
          await expect(total).toHaveCount(0);
          await expect(notice).toContainText(manualWait);
          await expect(page.locator("[data-invoice-state]")).toHaveAttribute(
            "data-invoice-state",
            "pending"
          );
          await expect(announcement).toBeEmpty();
        } finally {
          writeFileSync(providerState, "PENDING");
        }
      }
    });
  }
}

test("release guest direct, cart, payment and Recovery Key", async ({
  page,
  browser,
}) => {
  const provider = (state) =>
    writeFileSync(path.join(fixture, "provider-state"), state);
  const mode = (name) =>
    execFileSync(
      "php",
      [
        "artisan",
        "tinker",
        "--execute",
        String.raw`\App\Models\ManualPaymentSetting::query()->update(['payment_mode' => '${name}']);`,
      ],
      options
    );
  const foreign = await browser.newContext();
  const fresh = await foreign.newPage();
  const credential = "release-guest@example.test";
  const qr = page.locator("[data-invoice-qris] img");
  async function checkout() {
    await page.locator("#checkout-buyer-name").fill("Disposable guest");
    await page.locator("#checkout-terms-accepted").check();
    await page.locator("[data-checkout-submit]").click();
    await expect(page.locator("[data-invoice-state]")).toHaveAttribute(
      "data-invoice-state",
      "pending"
    );
  }

  try {
    // A synthetic image response: no network access to the provider or support.
    await page.route(/^(?!http:\/\/127\.0\.0\.1:)/, (route) => route.abort());
    await routeFixtureQr(page);
    await page.route("https://challenges.cloudflare.com/**", (route) =>
      route.fulfill({
        contentType: "application/javascript",
        body: `window.addEventListener('load', () => {
        const widget = document.querySelector('.cf-turnstile');
        if (!widget) return;
        const input = Object.assign(document.createElement('input'), {type: 'hidden', name: 'cf-turnstile-response', value: 'browser-checkout-proof'});
        widget.append(input);
        window.checkoutChallengeReady?.('browser-checkout-proof');
      });`,
      })
    );
    await fresh.route(/^(?!http:\/\/127\.0\.0\.1:)/, (route) => route.abort());
    mode("klikqris");
    provider("PENDING");
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.goto(baseUrl);
    await page
      .getByRole("link", { name: "Creative Studio", exact: true })
      .click();
    await page.locator("#catalog-search").fill("Desain Bulanan");
    await page.getByRole("search").getByRole("button").click();
    await expect(page.locator("[data-home-plan]")).toHaveCount(1);
    await page
      .getByRole("link", { name: "Beli sekarang", exact: true })
      .click();
    await page.clock.install();
    await checkout();
    const signed = page.url();
    const invoice = await page
      .locator(".invoice-order-hero .font-mono")
      .innerText();
    await expect(qr).toBeVisible();
    await expect(page.locator(".invoice-main")).toContainText("Rp35.016");
    await page.locator("#invoice-support > summary").click();
    const key = await page
      .locator('[aria-labelledby="recovery-key-title"] code')
      .innerText();
    const help = page.locator(
      '[data-support-category="payment_pending"][data-support-channel="whatsapp"]'
    );
    const message = new URL(await help.getAttribute("href")).searchParams.get(
      "text"
    );
    expect(message).toContain(invoice);
    expect(message).not.toContain(key);
    expect(message).not.toContain("signature");
    await expect(page.getByText(credential, { exact: true })).toHaveCount(0);
    const deadline = await page
      .locator(".invoice-qris-deadline time")
      .getAttribute("datetime");
    await page.clock.setSystemTime(new Date(deadline));
    await page.clock.runFor(1_000);
    await expect(qr).toBeHidden();
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(
      "Batas pembayaran terlewati"
    );
    provider("SUCCESS");
    await page.locator("[data-payment-status-check]").click();
    await expect(page.locator("[data-invoice-state]")).toHaveAttribute(
      "data-invoice-state",
      "paid"
    );
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(
      "Pembayaran terverifikasi"
    );
    await expect(page.locator("[data-qris-deadline-notice]")).toHaveCount(0);
    await page.clock.setSystemTime(new Date());
    async function reveal(target) {
      await target.locator("[data-credential-disclosure-toggle]").click();
      await target
        .getByRole("button", { name: "Ya, tampilkan kredensial" })
        .click();
      await expect(target.getByText(credential, { exact: true })).toBeVisible();
    }
    await reveal(page);
    await fresh.goto(signed);
    await expect(fresh.getByText(credential, { exact: true })).toHaveCount(0);
    const unsigned = new URL(signed);
    unsigned.search = "";
    expect((await fresh.goto(unsigned.href)).status()).toBe(403);

    async function recover(value) {
      await fresh.goto(baseUrl + "/akses-pesanan");
      await fresh.locator("#invoice-number").fill(invoice);
      await fresh.locator("#access-key").fill(value);
      await fresh
        .locator('form:has(#access-key) button[type="submit"]')
        .click();
    }
    await recover("LHIE-THISISNOTTHERECOVERYKEY");
    await expect(fresh.locator("#access-key")).toBeVisible();
    await recover(key);
    await reveal(fresh);

    provider("PENDING");
    await page.goto(baseUrl + localizedFixtures.product);
    const cartForm = page.locator('form[action$="/shop/cart/items"]').first();
    await cartForm.getByRole("button").click();
    await page.goto(baseUrl + "/shop/cart");
    await page.getByRole("link", { name: /Lanjut.*checkout/i }).click();
    await checkout();
    const expiring = page.url();
    await expect(qr).toBeVisible();
    provider("EXPIRED");
    expect((await fresh.goto(expiring)).status()).toBe(200);
    await expect(fresh.locator(".invoice-main")).toContainText(
      "Batas pembayaran terlewati"
    );
    await expect(fresh.locator("[data-invoice-qris] img")).toHaveCount(0);

    provider("PENDING");
    mode("manual");
    await page.goto(baseUrl + localizedFixtures.review);
    await checkout();
    await expect(page.locator(".invoice-main")).toContainText(
      "Kirim nomor invoice dan bukti bayar ke admin"
    );
    await expect(page.locator("[data-shop-payment-status]")).toHaveCount(0);
  } finally {
    mode("manual");
    provider("PENDING");
    await foreign.close();
  }
});

for (const [
  locale,
  language,
  catalog,
  review,
  manual,
  expired,
  paid,
  access,
] of [
  [
    "id",
    "Bahasa Indonesia",
    "Katalog produk",
    "Ringkasan pesanan",
    "Kirim nomor invoice dan bukti bayar ke admin",
    "Batas pembayaran terlewati",
    "Pembayaran terverifikasi",
    "Buka kembali data akun.",
  ],
  [
    "ms",
    "Bahasa Melayu",
    "Katalog produk",
    "Ringkasan pesanan",
    "bukti bayaran",
    "Tempoh bayaran tamat",
    "Bayaran disahkan",
    "Buka semula data akaun.",
  ],
  [
    "en",
    "English",
    "Product catalog",
    "Order summary",
    "proof of payment",
    "Payment deadline passed",
    "Payment verified",
    "Reopen your account details.",
  ],
]) {
  for (const [width, height] of sizes) {
    test(`localized funnel ${locale} at ${width}x${height}`, async ({
      page,
    }, info) => {
      await page.setViewportSize({ width, height });
      await page.goto(baseUrl);
      await page.locator("[data-storefront-locale] summary").click();
      await page.getByRole("link", { name: language }).click();

      async function check(heading, snapshot) {
        await expect(page.locator("html")).toHaveAttribute("lang", locale);
        await expect(
          page.getByRole("heading", { name: heading, exact: true }).first()
        ).toBeVisible();
        expect(
          await page.evaluate(() => document.documentElement.scrollWidth)
        ).toBe(width);
        if (snapshot)
          await page.screenshot({
            path: info.outputPath(`${snapshot}.png`),
            fullPage: true,
          });
      }
      await check(catalog, "catalog");
      if (width >= 768) {
        await expect(
          page.locator(".home-family-link[aria-current=true]")
        ).toHaveText(locale === "en" ? /All products/ : /Semua produk/);
      }
      await page.goto(baseUrl + localizedFixtures.product);
      await check("Desain Bulanan");
      await expect(
        page.getByText(
          locale === "en"
            ? "English merchant description"
            : "Base merchant description",
          { exact: true }
        )
      ).toBeVisible();
      await page.goto(baseUrl + localizedFixtures.review);
      await check(review, "review");
      await page.goto(baseUrl + localizedFixtures.unavailable);
      await check(
        {
          id: "Order tidak aktif",
          ms: "Pesanan tidak aktif",
          en: "Order inactive",
        }[locale],
        "unavailable"
      );
      await expect(page.locator("[data-invoice-qris]")).toHaveCount(0);
      await expect(page.locator("html")).toHaveAttribute("lang", locale);
      await page.goto(baseUrl + localizedFixtures.manual);
      await expect(page.locator(".invoice-main")).toContainText(manual);
      await expect(page.locator(".invoice-main")).toContainText(/Rp\s?35\.000/);
      const signedUrl = page.url();
      const otherLocale = locale === "en" ? "ms" : "en";
      const deadline = await page
        .locator(".invoice-qris-deadline time")
        .getAttribute("datetime");
      await page.locator("[data-storefront-locale] summary").click();
      await page
        .locator(`[data-storefront-locale] button[value=${otherLocale}]`)
        .click();
      await expect(page).toHaveURL(signedUrl);
      await expect(page.locator("html")).toHaveAttribute("lang", otherLocale);
      await expect(page.locator(".invoice-qris-deadline time")).toHaveAttribute(
        "datetime",
        deadline
      );
      await page.locator("[data-storefront-locale] summary").click();
      await page
        .locator(`[data-storefront-locale] button[value=${locale}]`)
        .click();
      await expect(page).toHaveURL(signedUrl);
      await expect(page.locator(".invoice-main")).toContainText(manual);
      await expect(page.locator("[data-shop-payment-status]")).toHaveCount(0);
      await check(
        { id: "Invoice QRIS", ms: "Invois QRIS", en: "QRIS invoice" }[locale],
        "manual"
      );
      await page.locator("#invoice-support > summary").click();
      const support = page.locator(
        '[data-support-category="payment_pending"][data-support-channel="whatsapp"]'
      );
      const message = new URL(
        await support.getAttribute("href")
      ).searchParams.get("text");
      expect(message).toMatch(/Inv(?:oice|ois): /);
      expect(message).not.toMatch(/signature|Recovery Key|https?:|password/i);
      await page.screenshot({
        path: info.outputPath("support.png"),
        fullPage: true,
      });
      await page.goto(baseUrl + localizedFixtures.expired);
      await check(expired, "expired");
      await page.goto(baseUrl + localizedFixtures.paid);
      await check(paid, "paid");
      await page.goto(baseUrl + "/akses-pesanan");
      await check(access, "access");
    });
  }
}
for (const [width, height] of sizes) {
  for (const theme of ["light", "dark"]) {
    test(`${width}x${height} ${theme}: search and filters fit, and buy action is reachable`, async ({
      page,
    }, info) => {
      await page.setViewportSize({ width, height });
      await page.addInitScript(
        (theme) => localStorage.setItem("lhie-store-theme", theme),
        theme
      );
      await page.goto(baseUrl);
      const search = page.getByRole("searchbox", { name: "Cari produk" });
      const picker =
        width >= 768
          ? page
              .getByRole("navigation", { name: "Pilih produk" })
              .getByRole("link", { name: "Semua produk" })
          : page.getByRole("combobox", { name: "Pilih produk" });
      const first = page.locator("[data-home-plan]").first();
      const sort = page.getByRole("combobox", { name: "Urutkan" });
      const stock = page.getByText("Hanya yang tersedia", { exact: true });
      for (const element of [search, picker, sort, stock]) {
        await expect(element).toBeInViewport({ ratio: 1 });
      }
      await expect(first).toBeInViewport();
      await expect(page.locator("html")).toHaveAttribute("data-theme", theme);
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth)
      ).toBe(width);
      await page.screenshot({
        path: info.outputPath(`${width}-${height}-${theme}.png`),
      });
      const buy = first.getByRole("link", { name: "Beli sekarang" });
      if (width < 390) await buy.scrollIntoViewIfNeeded();
      await expect(buy).toBeInViewport({ ratio: 1 });

      await search.focus();
      await page.keyboard.press("Tab");
      const submit = page.getByRole("button", { name: "Terapkan" });
      await expect(submit).toBeFocused();
      const colors = await submit.evaluate((node) => {
        const style = getComputedStyle(node);
        const surface = getComputedStyle(
          document.querySelector("#catalog-search")
        );
        const section = getComputedStyle(document.documentElement);
        return [
          style.color,
          style.backgroundColor,
          style.outlineColor,
          style.outlineWidth,
          surface.backgroundColor,
          getComputedStyle(
            document.querySelector("#catalog-search"),
            "::placeholder"
          ).color,
          surface.borderTopColor,
          section.backgroundColor,
        ];
      });
      expect(colors[3]).toBe("2px");
      expect(contrast(colors[0], colors[1])).toBeGreaterThanOrEqual(4.5);
      expect(contrast(colors[2], colors[7])).toBeGreaterThanOrEqual(3);
      expect(contrast(colors[4], colors[5])).toBeGreaterThanOrEqual(4.5);
      expect(contrast(colors[6], colors[4])).toBeGreaterThanOrEqual(3);
    });
  }
}

test("native Family and search controls restore history without JavaScript", async ({
  browser,
}, info) => {
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    javaScriptEnabled: false,
  });
  try {
    const page = await context.newPage();
    await page.goto(baseUrl);
    const picker = page.getByRole("combobox", { name: "Pilih produk" });
    const search = page.getByRole("searchbox", { name: "Cari produk" });
    const plans = page.locator("[data-home-plan]");
    await expect(picker.locator("option")).toHaveCount(3);
    await picker.focus();
    await page.keyboard.press("ArrowDown");
    await expect(picker).toHaveValue("creative");
    await search.fill("bulanan");
    await search.press("Enter");
    await expect(page).toHaveURL(/\?family=creative&q=bulanan&sort=$/);
    await expect(plans).toHaveCount(1);
    await expect(plans.first()).toContainText("Desain Bulanan");
    await page.screenshot({
      path: info.outputPath("390-844-filtered-no-js.png"),
    });
    await page.reload();
    await expect(picker).toHaveValue("creative");
    await expect(search).toHaveValue("bulanan");
    await page.getByRole("link", { name: "Reset pilihan produk" }).click();
    await expect(page).toHaveURL(/\?q=bulanan$/);
    await expect(plans).toHaveCount(3);
    await page.goBack();
    await expect(picker).toHaveValue("creative");
    await expect(plans).toHaveCount(1);
    await page.goForward();
    await expect(picker).toHaveValue("");
    await expect(plans).toHaveCount(3);
    await page.setViewportSize({ width: 1366, height: 768 });
    await page
      .getByRole("navigation", { name: "Pilih produk" })
      .getByRole("link", { name: "Creative Studio" })
      .press("Enter");
    await expect(page).toHaveURL(/\?family=creative&q=bulanan$/);
    await expect(plans).toHaveCount(1);
    await page
      .getByRole("link", { name: "Desain Bulanan", exact: true })
      .click();
    await expect(page).toHaveURL(/\/produk\/\d+/);
    await page
      .getByRole("link", { name: "Kembali ke katalog" })
      .first()
      .click();
    await expect(page).toHaveURL(/\?family=creative&q=bulanan#product-\d+$/);

    await page
      .getByRole("combobox", { name: "Urutkan" })
      .selectOption("price_desc");
    await page
      .getByRole("checkbox", { name: "Hanya yang tersedia" })
      .press("Space");
    await page
      .getByRole("search")
      .getByRole("button", { name: "Terapkan" })
      .press("Enter");
    await expect(page).toHaveURL(
      /\?family=creative&q=bulanan&sort=price_desc&in_stock=1$/
    );
    await expect(plans).toHaveCount(1);
  } finally {
    await context.close();
  }
});

test("catalog sort and stock controls update results and restore browser history", async ({
  page,
}) => {
  await page.goto(baseUrl);
  const sort = page.getByRole("combobox", { name: "Urutkan" });
  const stock = page.getByRole("checkbox", { name: "Hanya yang tersedia" });
  const count = page.locator("[data-catalog-count]");
  const names = page.locator("[data-home-plan] h2");
  await expect(count).toHaveText("4 produk");
  await expect(sort.locator("option")).toHaveCount(4);
  await sort.focus();
  await sort.selectOption("name_asc");
  await expect(page).toHaveURL(/\?sort=name_asc$/);
  await expect(sort).toBeFocused();
  await expect(names).toHaveText([
    "Desain Bulanan",
    "Desain Tahunan",
    "Musik Bulanan",
    "Penyimpanan Bulanan",
  ]);
  await stock.check();
  await expect(page).toHaveURL(/\?sort=name_asc&in_stock=1$/);
  await expect(count).toHaveText("3 produk");
  await expect(stock).toBeFocused();
  await page.goBack();
  await expect(stock).not.toBeChecked();
  await expect(count).toHaveText("4 produk");
});

test("320px catalog still fits at 200% root text size", async ({
  page,
}, info) => {
  await page.setViewportSize({ width: 320, height: 568 });
  await page.goto(`${baseUrl}/?family=creative`);
  await page.addStyleTag({ content: "html { font-size: 200% }" });
  for (const control of await page
    .locator(
      '#products select, #products input:not([type="checkbox"]), #products label[for="catalog-in-stock"], #products button, [data-home-plan] a'
    )
    .all()) {
    if (!(await control.isVisible())) continue;
    const box = await control.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(320);
    expect(box.height).toBeGreaterThanOrEqual(44);
  }
  await page.screenshot({ path: info.outputPath("320-568-text-200.png") });
});

test("guest locale survives a no-JS catalog and signed invoice round trip", async ({
  browser,
}) => {
  const options = {
    javaScriptEnabled: false,
    viewport: { width: 390, height: 844 },
  };
  const [contextA, contextB, anonymous] = await Promise.all(
    [1, 2, 3].map(() => browser.newContext(options))
  );
  try {
    const [a, b, guest] = await Promise.all(
      [contextA, contextB, anonymous].map((context) => context.newPage())
    );
    const catalog = `${baseUrl}/?family=creative&q=bulanan`;
    await Promise.all([a.goto(catalog), b.goto(baseUrl)]);
    const selector = (page) => page.locator("[data-storefront-locale]");
    await selector(a).locator("summary").press("Enter");
    await selector(a).getByRole("link", { name: "English" }).press("Enter");
    await expect(a).toHaveURL(catalog);
    await expect(a.locator("html")).toHaveAttribute("lang", "en");
    await expect(a.locator("[data-home-plan]")).toHaveCount(1);
    await selector(b).locator("summary").click();
    await selector(b).getByRole("link", { name: "Bahasa Melayu" }).click();
    await expect(b.locator("html")).toHaveAttribute("lang", "ms");

    const invoiceResponse = await a.goto(invoiceUrl);
    expect(invoiceResponse.headers()["referrer-policy"]).toBe("no-referrer");
    const invoiceState = await a
      .locator("[data-invoice-state]")
      .getAttribute("data-invoice-state");
    const form = selector(a).locator("form");
    await expect(form).toHaveAttribute("action", "/bahasa");
    const csrf = await form
      .locator('input[name="_token"]')
      .getAttribute("value");
    const destination = await form
      .locator('input[name="return"]')
      .getAttribute("value");
    const rejected = await contextA.request.post(`${baseUrl}/bahasa`, {
      form: { locale: "ms", return: destination },
    });
    expect(rejected.status()).toBe(419);
    expect(rejected.headers()["cache-control"]).toBe("no-store, private");
    expect(csrf).toBeTruthy();

    await selector(a).locator("summary").click();
    const switched = a.waitForResponse(
      (response) =>
        response.url() === `${baseUrl}/bahasa` && response.status() === 303
    );
    await selector(a).getByRole("button", { name: "Bahasa Indonesia" }).click();
    expect((await switched).headers().location).toBe(destination);
    await expect(a).toHaveURL(invoiceUrl);
    await expect(a.locator("html")).toHaveAttribute("lang", "id");
    expect(invoiceState).toBeTruthy();
    await expect(a.locator("[data-invoice-state]")).toHaveAttribute(
      "data-invoice-state",
      invoiceState
    );

    for (const [page, locale] of [
      [a, "id"],
      [b, "ms"],
      [guest, "id"],
    ]) {
      const response = await page.goto(baseUrl);
      expect(response.headers()["cache-control"]).toBe("no-store, private");
      await expect(page.locator("html")).toHaveAttribute("lang", locale);
    }
    expect(await anonymous.cookies()).toEqual([]);
  } finally {
    await Promise.all(
      [contextA, contextB, anonymous].map((context) => context.close())
    );
  }
});

function contrast(first, second) {
  const luminance = (color) =>
    color
      .match(/[\d.]+/g)
      .slice(0, 3)
      .map(Number)
      .map((value) => {
        const channel = value / 255;
        return channel <= 0.04045
          ? channel / 12.92
          : ((channel + 0.055) / 1.055) ** 2.4;
      })
      .reduce((sum, value, i) => sum + value * [0.2126, 0.7152, 0.0722][i], 0);
  const [a, b] = [luminance(first), luminance(second)].sort((a, b) => b - a);
  return (a + 0.05) / (b + 0.05);
}

const seed = String.raw`
\App\Models\ManualPaymentSetting::query()->create(['payment_mode' => 'manual', 'is_active' => true, 'whatsapp_support_number' => '6281234567890']);
$creative = \App\Models\ProductFamily::factory()->create(['slug'=>'creative','name'=>'Creative Studio', 'sort_order'=>1, 'icon'=>'🎨']);
$audio = \App\Models\ProductFamily::factory()->create(['slug'=>'audio','name'=>'Audio Library', 'sort_order'=>2]);
foreach ([[$creative, 'Desain Bulanan', 8], [$creative, 'Desain Tahunan', 0], [$audio, 'Musik Bulanan', 4], [null, 'Penyimpanan Bulanan', 2]] as $index => [$family, $name, $stock]) {
    $product = \App\Models\Product::factory()->create(['name'=>$name, 'product_family_id'=>$family?->id, 'family_sort_order'=>$index, 'price_amount'=>35000, 'access_duration'=>'30 hari', 'stock'=>$stock, 'reserved_stock'=>0, 'is_active'=>true, 'description'=>'Base merchant description', 'description_en'=>'English merchant description', 'description_ms'=>'']);
    if ($index === 0) $featured = $product;
}
$urls = ['product' => route('storefront.products.show', $featured, absolute: false), 'review' => route('storefront.shop.checkout.product', $featured, absolute: false)];
$urls['qr'] = base64_encode(app(\App\Services\Telegram\TelegramQrCodeRenderer::class)->png('${qrFixturePayload}'));
foreach (range(1, 8) as $slot) {
    \App\Models\ProductStockItem::query()->create(['product_id' => $featured->id, 'secret' => ($slot === 1 ? 'release-guest@example.test' : "release-guest-$slot@example.test").'|synthetic-fixture']);
}
foreach (['unavailable', 'manual', 'expired', 'paid'] as $state) {
    $order = \App\Models\Order::factory()->create(['order_status' => $state === 'paid' ? 'completed' : 'pending_payment', 'total_amount' => 35000, 'expires_at' => $state === 'expired' ? now()->subMinute() : now()->addMinutes(15)]);
    if ($state !== 'unavailable') \App\Models\Payment::factory()->for($order)->create(['method' => 'QR_MANUAL', 'provider' => 'manual', 'status' => $state === 'paid' ? 'paid' : 'pending', 'expired_at' => $order->expires_at]);
    $urls[$state] = \Illuminate\Support\Facades\URL::temporarySignedRoute('storefront.shop.orders.show', now()->addHour(), $order, absolute: false);
    if ($state === 'manual') $urls['manualDeadline'] = $order->expires_at->toIso8601String();
}
$ready = \Tests\Support\KlikQrisSandboxScenarios::klikQrisPayment([
    'merchant_ref' => 'INV-RELEASE-LAYOUT', 'amount' => 35000, 'provider_total_payment' => 35016, 'provider_expires_at' => now()->addMinutes(15),
    'provider_payload' => ['data' => ['order_id' => 'INV-RELEASE-LAYOUT', 'amount' => 35000, 'total_amount' => 35016, 'status' => 'PENDING',
      'qris_url' => 'https://klikqris.com/storage/sandbox/qris_release_fixture.png', 'expired_at' => now()->addMinutes(15)->toISOString()]],
]);
$urls['ready'] = \Illuminate\Support\Facades\URL::temporarySignedRoute('storefront.shop.orders.show', now()->addHour(), $ready->order, absolute: false);
$urls['readyDeadline'] = $ready->provider_expires_at->toIso8601String();
echo json_encode($urls);
`;
