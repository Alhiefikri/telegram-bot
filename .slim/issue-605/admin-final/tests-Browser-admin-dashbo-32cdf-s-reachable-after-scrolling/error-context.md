# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: tests/Browser/admin-dashboard-responsive.spec.js >> storefront navigation: header remains reachable after scrolling
- Location: tests/Browser/admin-dashboard-responsive.spec.js:77:1

# Error details

```
"beforeAll" hook timeout of 30000ms exceeded.
```

# Test source

```ts
  1   | import { expect, test } from "@playwright/test";
  2   | import { spawn, spawnSync } from "node:child_process";
  3   | import {
  4   |   mkdtempSync,
  5   |   mkdirSync,
  6   |   openSync,
  7   |   closeSync,
  8   |   rmSync,
  9   |   writeFileSync,
  10  | } from "node:fs";
  11  | import { tmpdir } from "node:os";
  12  | import path from "node:path";
  13  | import { fileURLToPath } from "node:url";
  14  | import net from "node:net";
  15  | 
  16  | const __dirname = path.dirname(fileURLToPath(import.meta.url));
  17  | const appDir = path.resolve(__dirname, "../..");
  18  | 
  19  | let baseUrl;
  20  | let dbPath;
  21  | let invoiceFixtures;
  22  | let serverProcess;
  23  | 
  24  | test.describe.configure({ mode: "serial" });
  25  | test.setTimeout(120_000);
  26  | 
  27  | const customerCheckoutViewports = [
  28  |   { width: 320, height: 568 },
  29  |   { width: 390, height: 844 },
  30  |   { width: 768, height: 900 },
  31  |   { width: 1280, height: 900 },
  32  |   { width: 1440, height: 900 },
  33  |   { width: 1920, height: 1080 },
  34  | ];
  35  | const customerCheckoutTestPrefix = "customer checkout:";
  36  | 
  37  | const customerBalanceLoginToken = "a".repeat(64);
  38  | 
> 39  | test.beforeAll(async () => {
      |      ^ "beforeAll" hook timeout of 30000ms exceeded.
  40  |   const tempDir = mkdtempSync(
  41  |     path.join(tmpdir(), "telegram-bot-admin-responsive-")
  42  |   );
  43  |   dbPath = path.join(tempDir, "database.sqlite");
  44  |   closeSync(openSync(dbPath, "w"));
  45  | 
  46  |   baseUrl = `http://127.0.0.1:${await findAvailablePort()}`;
  47  | 
  48  |   runArtisan(["optimize:clear"]);
  49  |   resetResponsiveFixtures();
  50  | 
  51  |   await startBrowserServer();
  52  | });
  53  | 
  54  | // Playwright isolates cookies and storage in each test context, but the PHP
  55  | // server intentionally shares one SQLite database across this serial file.
  56  | // Rebuild the database and restart the server before every customer test so
  57  | // reservations, checked-out Carts, Orders, payment settings, and in-memory
  58  | // response caches cannot leak between flows.
  59  | test.beforeEach(async ({}, testInfo) => {
  60  |   if (!testInfo.title.startsWith(customerCheckoutTestPrefix)) {
  61  |     return;
  62  |   }
  63  | 
  64  |   await stopBrowserServer();
  65  |   resetResponsiveFixtures();
  66  |   await startBrowserServer();
  67  | });
  68  | 
  69  | test.afterAll(async () => {
  70  |   await stopBrowserServer();
  71  | 
  72  |   if (dbPath) {
  73  |     rmSync(path.dirname(dbPath), { recursive: true, force: true });
  74  |   }
  75  | });
  76  | 
  77  | test("storefront navigation: header remains reachable after scrolling", async ({
  78  |   page,
  79  | }) => {
  80  |   await page.setViewportSize({ width: 1280, height: 900 });
  81  |   await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  82  |   await page.evaluate(() => window.scrollTo({ top: 600, behavior: "instant" }));
  83  |   expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(100);
  84  |   await expect
  85  |     .poll(async () =>
  86  |       page
  87  |         .locator("header")
  88  |         .evaluate((header) => header.getBoundingClientRect().top)
  89  |     )
  90  |     .toBeGreaterThanOrEqual(0);
  91  | });
  92  | 
  93  | test("theme defaults to light on a dark system and preserves explicit choices", async ({
  94  |   page,
  95  | }) => {
  96  |   await page.emulateMedia({ colorScheme: "dark" });
  97  |   await page.goto(`${baseUrl}/`, { waitUntil: "domcontentloaded" });
  98  |   await page.evaluate(() => window.localStorage.removeItem("lhie-store-theme"));
  99  |   await page.reload({ waitUntil: "domcontentloaded" });
  100 | 
  101 |   await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  102 | 
  103 |   const themeToggle = page.locator("[data-theme-toggle]").first();
  104 |   await expect(themeToggle).toBeVisible();
  105 | 
  106 |   const initialTheme = await page.locator("html").getAttribute("data-theme");
  107 |   expect(["light", "dark"]).toContain(initialTheme);
  108 |   const initialBackground = await page
  109 |     .locator("body")
  110 |     .evaluate((body) => getComputedStyle(body).backgroundColor);
  111 | 
  112 |   await themeToggle.click();
  113 | 
  114 |   const selectedTheme = await page.locator("html").getAttribute("data-theme");
  115 |   expect(selectedTheme).not.toBe(initialTheme);
  116 |   const selectedBackground = await page
  117 |     .locator("body")
  118 |     .evaluate((body) => getComputedStyle(body).backgroundColor);
  119 |   expect(selectedBackground).not.toBe(initialBackground);
  120 |   await expect(themeToggle).toHaveAttribute(
  121 |     "aria-pressed",
  122 |     selectedTheme === "dark" ? "true" : "false"
  123 |   );
  124 |   await expect
  125 |     .poll(() => page.evaluate(() => localStorage.getItem("lhie-store-theme")))
  126 |     .toBe(selectedTheme);
  127 | 
  128 |   await page.reload({ waitUntil: "domcontentloaded" });
  129 |   await expect(page.locator("html")).toHaveAttribute(
  130 |     "data-theme",
  131 |     selectedTheme
  132 |   );
  133 | });
  134 | 
  135 | test("admin dashboard exposes the shared theme preference control", async ({
  136 |   page,
  137 | }) => {
  138 |   await login(page);
  139 |   const balanceReportsLink = page.getByRole("link", {
```