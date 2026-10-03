import { test, expect } from "@playwright/test";
import { spawn, spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import net from "node:net";
import { fileURLToPath } from "node:url";

const directory = path.dirname(fileURLToPath(import.meta.url));
const appDir = path.resolve(directory, "../..");
let tempDir, environment, server, fixture;
test.describe.configure({ mode: "serial" });

test.beforeAll(async () => {
  tempDir = mkdtempSync(path.join(tmpdir(), "buymail-browser-"));
  const database = path.join(tempDir, "database.sqlite");
  writeFileSync(database, "");
  const port = await new Promise((resolve) => {
    const socket = net.createServer();
    socket.listen(0, "127.0.0.1", () => {
      const selected = socket.address().port;
      socket.close(() => resolve(selected));
    });
  });
  environment = {
    ...process.env,
    APP_ENV: "testing",
    APP_DEBUG: "true",
    APP_URL: `http://127.0.0.1:${port}`,
    APP_CONFIG_CACHE: path.join(tempDir, "config.php"),
    APP_FORCE_HTTPS: "false",
    TRUSTED_HOSTS: "",
    DB_CONNECTION: "sqlite",
    DB_DATABASE: database,
    DB_URL: "",
    SESSION_DRIVER: "file",
    CACHE_STORE: "array",
    QUEUE_CONNECTION: "database",
    TELEGRAM_BOT_TOKEN: "",
    TELEGRAM_ADMIN_CHAT_ID: "",
    MAIL_MAILER: "array",
    ADMIN_ALLOWED_LOGINS: "",
    BCRYPT_ROUNDS: "4",
  };
  run([path.join(directory, "fixtures/warranty.php"), "prepare"]);
  fixture = JSON.parse(run([path.join(directory, "fixtures/warranty.php")]));
  server = spawn(
    "php",
    [
      "-S",
      `127.0.0.1:${port}`,
      "-t",
      "public",
      path.join(directory, "laravel-browser-router.php"),
    ],
    { cwd: appDir, env: environment, stdio: "ignore" }
  );
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    try {
      if ((await fetch(environment.APP_URL + "/admin/login")).ok) return;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error("Disposable warranty server did not start.");
});

test.afterAll(async () => {
  if (server && server.exitCode === null) {
    await new Promise((resolve) => {
      server.once("exit", resolve);
      server.kill();
    });
  }
  if (fixture) run([path.join(directory, "fixtures/warranty.php"), "cleanup"]);
  if (tempDir) rmSync(tempDir, { recursive: true, force: true });
});

test("verified claim offers English and Indonesian refund/replacement without overflow", async ({
  page,
}, testInfo) => {
  await page.setViewportSize({ width: 320, height: 720 });
  await page.goto(fixture.url);
  await expect(
    page.getByRole("heading", { name: "Account support and refunds" })
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Refund this account" })
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Replace this account" })
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth
    )
  ).toBe(true);
  await page
    .getByRole("link", { name: "Bahasa Indonesia", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Bantuan akun dan pengembalian dana" })
  ).toBeVisible();
  await page.getByRole("link", { name: "English", exact: true }).click();
  await page.getByRole("button", { name: "Refund this account" }).click();
  await expect(
    page.getByText("The refund for this account has been completed.", {
      exact: true,
    })
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Replace this account" })
  ).toHaveCount(0);
  expect(await page.locator("body").innerText()).not.toContain(
    "synthetic-password"
  );
  await page.screenshot({
    path: testInfo.outputPath("warranty-refunded-mobile.png"),
    fullPage: true,
  });
});

test("owner can inspect private warranty and deposit panels without exposing the saved key", async ({
  page,
}, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(environment.APP_URL + "/admin/login");
  await page.locator('[name="email"]').fill("warranty-browser@example.test");
  await page.locator('[name="password"]').fill("synthetic-browser-password");
  await page.getByRole("button", { name: "Masuk Dashboard" }).click();
  await page.goto(environment.APP_URL + "/admin?tab=suppliers");
  await expect(
    page.getByText("Private source", { exact: false }).first()
  ).toBeVisible();
  expect(await page.locator("body").innerText()).not.toContain(
    "synthetic-test-key"
  );
  await page
    .getByRole("link", { name: "Klaim Gmail / refund / penggantian" })
    .click();
  await expect(
    page.getByRole("heading", { name: "Klaim Gmail · Refund & penggantian" })
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth
    )
  ).toBe(true);
  await page.screenshot({
    path: testInfo.outputPath("warranty-admin-mobile.png"),
    fullPage: true,
  });
  await page.goto(environment.APP_URL + "/admin?tab=suppliers");
  await page
    .getByRole("link", { name: "Deposit BuyMail & histori privat" })
    .click();
  await expect(
    page.getByRole("button", { name: "Buat satu instruksi top-up supplier" })
  ).toBeVisible();
  expect(await page.locator("body").innerText()).not.toContain(
    "synthetic-test-key"
  );
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth
    )
  ).toBe(true);
});

function run(args) {
  const result = spawnSync("php", args, {
    cwd: appDir,
    env: environment,
    encoding: "utf8",
  });
  if (result.status !== 0) throw new Error(result.stderr || result.stdout);
  return result.stdout.trim();
}
