import { expect, test } from "@playwright/test";
import { spawn, spawnSync } from "node:child_process";
import { closeSync, mkdtempSync, openSync, rmSync } from "node:fs";
import net from "node:net";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const appDir = path.resolve(__dirname, "../..");

let baseUrl;
let dbPath;
let serverProcess;

test.describe.configure({ mode: "serial" });

test.beforeAll(async () => {
  const tempDir = mkdtempSync(path.join(os.tmpdir(), "telegram-bot-api-docs-"));
  dbPath = path.join(tempDir, "database.sqlite");
  closeSync(openSync(dbPath, "w"));
  baseUrl = `http://127.0.0.1:${await findAvailablePort()}`;

  runArtisan(["optimize:clear"]);
  runArtisan(["migrate:fresh", "--force"]);

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

  await waitForServer(`${baseUrl}/api-docs`);
});

test.afterAll(async () => {
  if (serverProcess && !serverProcess.killed) serverProcess.kill();
  if (dbPath) rmSync(path.dirname(dbPath), { recursive: true, force: true });
});

test("desktop shows every endpoint, both languages and readable code in the initial HTML", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  const response = await page.goto(`${baseUrl}/api-docs`, {
    waitUntil: "domcontentloaded",
  });
  expect(response.status()).toBe(200);
  await expect(
    page.getByRole("heading", { level: 1, name: "API Supplier Lhie Store" })
  ).toBeVisible();

  await expect(
    page.locator('#overview .api-docs-prose[data-api-docs-block="id"]')
  ).toBeVisible();
  await expect(
    page.locator('#overview .api-docs-prose[data-api-docs-block="en"]')
  ).toBeVisible();
  expect(await page.locator("[data-api-docs-example-block]").count()).toBe(28);
  expect(
    await page.locator("[data-api-docs-example-block]:visible").count()
  ).toBe(28);

  for (const [id, method, endpoint] of [
    ["getbalance", "GET", "/api/v1/balance"],
    ["listproducts", "GET", "/api/v1/products"],
    ["getproduct", "GET", "/api/v1/products/{id}"],
    ["createorder", "POST", "/api/v1/orders"],
    ["listorders", "GET", "/api/v1/orders"],
    ["getorder", "GET", "/api/v1/orders/{id}"],
    ["getorderdelivery", "GET", "/api/v1/orders/{id}/delivery"],
  ]) {
    const article = page.locator(`#endpoint-${id}`);
    await expect(article).toBeVisible();
    await expect(article.locator("code").first()).toHaveText(endpoint);
    await expect(article.locator("span").first()).toHaveText(method);
  }

  const code = page
    .locator('[data-api-docs-example-block="curl"]')
    .first()
    .locator("pre");
  await expect(code).toContainText("--connect-timeout 5 --max-time 15");
  await expect(code).toContainText("Authorization: Bearer");
  await expect(code).toHaveAttribute("tabindex", "0");

  await assertNoHorizontalOverflow(page);
});

test("mobile reflows the table of contents and keeps every control reachable", async ({
  page,
}) => {
  await page.setViewportSize({ width: 375, height: 780 });
  await page.goto(`${baseUrl}/api-docs`, { waitUntil: "domcontentloaded" });

  const desktopNav = page.locator("nav.api-docs-toc");
  await expect(desktopNav).toBeHidden();

  const disclosure = page.locator("main details").first();
  await expect(disclosure.locator("summary")).toBeVisible();
  await disclosure.locator("summary").click();
  await expect(
    disclosure.locator('a[href="#endpoint-getbalance"]')
  ).toBeVisible();
  await disclosure.locator('a[href="#endpoint-getbalance"]').click();
  await expect(page.locator("#endpoint-getbalance")).toBeInViewport();

  await assertMinimumTapTargets(page);
  await assertNoHorizontalOverflow(page);
});

test("keyboard focuses the controls and switches the explained language", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(`${baseUrl}/api-docs`, { waitUntil: "domcontentloaded" });

  const english = page.locator('[data-api-docs-lang-button="en"]');
  await expect(english).toBeVisible();
  await english.focus();
  const outline = await english.evaluate((element) => {
    const style = window.getComputedStyle(element);
    return {
      width: style.outlineWidth,
      style: style.outlineStyle,
      color: style.outlineColor,
    };
  });
  expect(outline.style).not.toBe("none");
  expect(parseFloat(outline.width)).toBeGreaterThanOrEqual(2);

  const englishProse = page.locator(
    '#overview .api-docs-prose[data-api-docs-block="en"]'
  );
  const indonesianProse = page.locator(
    '#overview .api-docs-prose[data-api-docs-block="id"]'
  );
  await page.keyboard.press("Enter");

  await expect(english).toHaveAttribute("aria-pressed", "true");
  await expect(
    page.locator('[data-api-docs-lang-button="id"]')
  ).toHaveAttribute("aria-pressed", "false");
  await expect(indonesianProse).toBeHidden();
  await expect(englishProse).toBeVisible();

  await page.locator('[data-api-docs-lang-button="id"]').focus();
  await page.keyboard.press("Enter");
  await expect(englishProse).toBeHidden();
  await expect(indonesianProse).toBeVisible();

  const python = page.locator('[data-api-docs-example-button="python"]');
  await python.focus();
  await page.keyboard.press("Enter");
  await expect(
    page.locator('[data-api-docs-example-block="curl"]').first()
  ).toBeHidden();
  await expect(
    page.locator('[data-api-docs-example-block="python"]').first()
  ).toBeVisible();

  for (const theme of ["light", "dark"]) {
    await page.evaluate((value) => {
      document.documentElement.dataset.theme = value;
    }, theme);
    await assertRenderedContrast(
      page,
      "#overview .api-docs-prose[data-api-docs-block='id']",
      4.5
    );
    await assertRenderedContrast(
      page,
      "#overview .api-docs-prose[data-api-docs-block='id'] p",
      4.5
    );
    await assertRenderedContrast(page, "nav.api-docs-toc a", 4.5);
    await assertRenderedContrast(
      page,
      "#endpoint-getbalance .api-docs-table td",
      4.5
    );
    await assertRenderedContrast(page, '[data-api-docs-lang-button="en"]', 4.5);
    await assertRenderedContrast(
      page,
      '[data-api-docs-example-block="curl"] pre code',
      4.5
    );
  }
});

test("copy actions copy the base URL and a code sample", async ({
  page,
  context,
}) => {
  await context.grantPermissions(["clipboard-read", "clipboard-write"], {
    origin: baseUrl,
  });
  await page.goto(`${baseUrl}/api-docs`, { waitUntil: "domcontentloaded" });

  const baseCopy = page.locator("[data-api-docs-copy]").first();
  await expect(baseCopy).toBeVisible();
  await baseCopy.click();
  const copiedBase = await page.evaluate(() => navigator.clipboard.readText());
  expect(copiedBase).toBe(`${baseUrl}/api/v1`);

  const codeCopy = page
    .locator('#endpoint-createorder [data-api-docs-example-block="python"]')
    .locator("[data-api-docs-copy]");
  await codeCopy.click();
  const copiedCode = await page.evaluate(() => navigator.clipboard.readText());
  expect(copiedCode).toContain("urllib.request");
  expect(copiedCode).toContain("Idempotency-Key");
});

test("filter narrows the endpoint list and explains an empty result", async ({
  page,
}) => {
  await page.goto(`${baseUrl}/api-docs`, { waitUntil: "domcontentloaded" });

  const filter = page.locator("[data-api-docs-filter]");
  await expect(filter).toBeVisible();
  await filter.fill("delivery");
  await expect(page.locator("#endpoint-getorder")).toBeHidden();
  await expect(page.locator("#endpoint-getorderdelivery")).toBeVisible();

  await filter.fill("tidak-ada-endpoint-seperti-ini");
  await expect(page.locator("[data-api-docs-empty]")).toBeVisible();
  await expect(page.locator("#endpoint-getbalance")).toBeHidden();

  await filter.fill("");
  await expect(page.locator("#endpoint-getbalance")).toBeVisible();
});

test("machine files are retrievable with safe content types", async ({
  page,
}) => {
  const openapi = await page.request.get(`${baseUrl}/api-docs/openapi.json`);
  expect(openapi.status()).toBe(200);
  expect(openapi.headers()["content-type"]).toMatch(/application\/json/);
  expect(openapi.headers()["cache-control"]).toContain("max-age=3600");
  const document = await openapi.json();
  expect(document.openapi).toBe("3.1.0");
  expect(Object.keys(document.paths)).toHaveLength(6);

  const reference = await page.request.get(`${baseUrl}/api-docs/reference.md`);
  expect(reference.status()).toBe(200);
  expect(reference.headers()["content-type"]).toMatch(/text\/markdown/);
  expect(await reference.text()).toContain("### `POST /api/v1/orders`");

  const llms = await page.request.get(`${baseUrl}/llms.txt`);
  expect(llms.status()).toBe(200);
  expect(llms.headers()["content-type"]).toMatch(/text\/plain/);
  expect(await llms.text()).toContain("/api-docs/reference.md");

  const trailing = await page.request.get(`${baseUrl}/api-docs/`);
  expect(trailing.status()).toBe(200);
});

test("without JavaScript both languages and all samples stay readable and no control is dead", async ({
  browser,
}) => {
  const context = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 1280, height: 900 },
  });
  const page = await context.newPage();
  const response = await page.goto(`${baseUrl}/api-docs`, {
    waitUntil: "domcontentloaded",
  });
  expect(response.status()).toBe(200);

  await expect(
    page.getByRole("heading", { level: 1, name: "API Supplier Lhie Store" })
  ).toBeVisible();
  await expect(
    page.locator('#overview .api-docs-prose[data-api-docs-block="id"]')
  ).toBeVisible();
  await expect(
    page.locator('#overview .api-docs-prose[data-api-docs-block="en"]')
  ).toBeVisible();
  expect(
    await page.locator("[data-api-docs-example-block]:visible").count()
  ).toBe(28);
  await expect(
    page.locator('[data-api-docs-example-block="php"]').first().locator("pre")
  ).toContainText("CURLOPT_TIMEOUT");

  await expect(page.locator("[data-api-docs-lang-control]")).toBeHidden();
  await expect(page.locator("[data-api-docs-example-control]")).toBeHidden();
  await expect(page.locator("[data-api-docs-filter]")).toBeHidden();
  await expect(page.locator("[data-api-docs-copy]:visible")).toHaveCount(0);

  await context.close();
});

async function assertRenderedContrast(page, selector, minimum) {
  const ratio = await page.evaluate((target) => {
    const element = document.querySelector(target);
    if (!element) return null;

    const channel = (value) => {
      const scaled = value / 255;
      return scaled <= 0.03928
        ? scaled / 12.92
        : ((scaled + 0.055) / 1.055) ** 2.4;
    };
    const luminance = ([red, green, blue]) =>
      0.2126 * channel(red) + 0.7152 * channel(green) + 0.0722 * channel(blue);
    const rgb = (value) => {
      const parts = (value.match(/[\d.]+/g) || []).map(Number);
      return parts.length >= 3 ? parts.slice(0, 3) : null;
    };

    let background = null;
    for (let node = element; node; node = node.parentElement) {
      const value = window.getComputedStyle(node).backgroundColor;
      const parts = (value.match(/[\d.]+/g) || []).map(Number);
      if (parts.length >= 3 && (parts.length < 4 || parts[3] > 0)) {
        background = parts.slice(0, 3);
        break;
      }
    }

    const foreground = rgb(window.getComputedStyle(element).color);
    if (!foreground || !background) return null;

    const first = luminance(foreground);
    const second = luminance(background);
    return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
  }, selector);

  expect(ratio, `contrast for ${selector}`).not.toBeNull();
  expect(ratio, `contrast for ${selector}`).toBeGreaterThanOrEqual(minimum);
}

async function assertNoHorizontalOverflow(page) {
  const offenders = await page.evaluate(() => {
    const allowed = (element) =>
      element.closest('[role="region"], .api-docs-code') !== null;
    const bad = [];

    document.querySelectorAll("body *").forEach((element) => {
      if (element.getClientRects().length === 0) return;
      if (window.getComputedStyle(element).visibility === "hidden") return;
      const rect = element.getBoundingClientRect();
      if (rect.width === 0) return;
      if (allowed(element)) return;
      if (rect.right > window.innerWidth + 1 || rect.left < -1) {
        bad.push(
          `${element.tagName}.${String(element.className).slice(0, 60)}`
        );
      }
    });

    return bad.slice(0, 10);
  });

  expect(offenders).toEqual([]);
  expect(await page.evaluate(() => window.scrollX)).toBe(0);
}

async function assertMinimumTapTargets(page) {
  const undersized = await page.evaluate(() => {
    const targets = [
      ...document.querySelectorAll("[data-api-docs] button"),
      ...document.querySelectorAll("[data-api-docs] .api-docs-toc a"),
      ...document.querySelectorAll("[data-api-docs] main details summary"),
    ];
    const bad = [];

    targets.forEach((element) => {
      if (element.hidden || element.getClientRects().length === 0) return;
      const rect = element.getBoundingClientRect();
      if (rect.height < 43.5 || rect.width < 43.5) {
        bad.push(
          `${element.tagName}.${String(element.className).slice(0, 50)} ${Math.round(rect.width)}x${Math.round(rect.height)}`
        );
      }
    });

    return bad;
  });

  expect(undersized).toEqual([]);
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
