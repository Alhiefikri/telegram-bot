import { test, expect } from "@playwright/test";
import { spawn, spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import net from "node:net";
import { fileURLToPath } from "node:url";

const directory = path.dirname(fileURLToPath(import.meta.url));
const appDir = path.resolve(directory, "../..");
let temporary, environment, server;
test.describe.configure({ mode: "serial" });
test.setTimeout(120_000);
const password = "Browser account password 123";
const email = "account-browser@example.test";
const run = (operation, input = undefined) => {
  const result = spawnSync(
    "php",
    [path.join(directory, "fixtures/customer-account.php"), operation],
    {
      cwd: appDir,
      env: environment,
      encoding: "utf8",
      input: input === undefined ? undefined : JSON.stringify(input),
    }
  );
  if (result.status !== 0)
    throw new Error("Disposable account fixture failed: " + result.stderr);
  return JSON.parse(result.stdout);
};

test.beforeAll(async () => {
  temporary = mkdtempSync(path.join(tmpdir(), "customer-account-browser-"));
  const database = path.join(temporary, "database.sqlite");
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
    APP_DEBUG: "false",
    APP_URL: `http://127.0.0.1:${port}`,
    APP_FORCE_HTTPS: "false",
    TRUSTED_HOSTS: "",
    APP_CONFIG_CACHE: path.join(temporary, "config.php"),
    DB_CONNECTION: "sqlite",
    DB_DATABASE: database,
    DB_URL: "",
    SESSION_DRIVER: "database",
    SESSION_CONNECTION: "sqlite",
    SESSION_ENCRYPT: "false",
    CACHE_STORE: "database",
    QUEUE_CONNECTION: "database",
    MAIL_MAILER: "array",
    TELEGRAM_BOT_TOKEN: "",
    BCRYPT_ROUNDS: "4",
  };
  run("prepare");
  server = spawn(
    "php",
    [
      "-S",
      `127.0.0.1:${port}`,
      "-t",
      "public",
      path.join(directory, "fixtures/customer-auth-router.php"),
    ],
    { cwd: appDir, env: environment, stdio: "ignore" }
  );
  for (let attempt = 0; attempt < 150; attempt++) {
    try {
      if ((await fetch(environment.APP_URL + "/akun")).ok) return;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw new Error("Disposable account server did not start.");
});
test.afterAll(async () => {
  if (server && server.exitCode === null) {
    await new Promise((resolve) => {
      server.once("exit", resolve);
      server.kill();
    });
  }
  if (temporary) rmSync(temporary, { recursive: true, force: true });
});

const account = (page) => page.goto(environment.APP_URL + "/akun");
const logout = async (page) => {
  await page.getByRole("button", { name: "Keluar", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Masuk ke akun pelanggan" })
  ).toBeVisible();
};
const noOverflow = async (page) =>
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth
    )
  ).toBe(true);

test("registration, verification, password login, OTP, reset and retained magic link use real forms", async ({
  page,
}) => {
  await account(page);
  await page.getByRole("link", { name: "Daftar", exact: true }).click();
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page.getByLabel("Nama lengkap (opsional)").fill("Browser Customer");
  await page.getByLabel("Password baru", { exact: true }).fill(password);
  await page.getByLabel("Ulangi password baru", { exact: true }).fill(password);
  await page
    .getByRole("button", { name: "Daftar dan kirim verifikasi" })
    .click();
  await expect(
    page.getByRole("heading", { name: "Periksa email Anda" })
  ).toBeVisible();
  const verification = run("mail").token;
  await page.goto(
    environment.APP_URL + "/akun/verifikasi-email#token=" + verification
  );
  await page
    .getByRole("button", { name: "Konfirmasi email dan masuk" })
    .click();
  await expect(
    page.getByRole("heading", { name: "Pesanan saya", exact: true })
  ).toBeVisible();
  await page
    .getByRole("navigation", { name: "Navigasi akun pelanggan" })
    .getByRole("link", { name: "Saldo saya" })
    .click();
  await expect(page.getByRole("heading", { name: "Saldo saya" })).toBeVisible();
  await page
    .getByRole("navigation", { name: "Navigasi akun pelanggan" })
    .getByRole("link", { name: "Klaim pesanan" })
    .click();
  await expect(page.getByLabel("Nomor invoice")).toBeVisible();
  await page
    .getByRole("navigation", { name: "Navigasi akun pelanggan" })
    .getByRole("link", { name: "Keamanan akun" })
    .click();
  await expect(
    page.getByRole("heading", { name: "Keamanan akun" })
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Keluar dari akun pelanggan" })
    .click();
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page
    .getByLabel("Password", { exact: true })
    .fill("Wrong account password");
  await page.getByRole("button", { name: "Masuk dengan password" }).click();
  await expect(page.getByRole("alert")).toBeVisible();
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Masuk dengan password" }).click();
  await expect(
    page.getByRole("heading", { name: "Pesanan saya", exact: true })
  ).toBeVisible();
  await logout(page);
  run("reset-limits");
  await page
    .getByRole("link", { name: "Masuk dengan kode email", exact: true })
    .click();
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page
    .getByRole("button", { name: "Kirim kode email", exact: true })
    .click();
  await expect(page.getByLabel("Kode email", { exact: true })).toBeVisible();
  const code = run("mail").token;
  await page
    .getByLabel("Kode email", { exact: true })
    .fill(code === "000000" ? "111111" : "000000");
  await page.getByRole("button", { name: "Konfirmasi kode dan masuk" }).click();
  await expect(page.getByRole("alert")).toBeVisible();
  await page.getByLabel("Kode email", { exact: true }).fill(code);
  await page.getByRole("button", { name: "Konfirmasi kode dan masuk" }).click();
  await expect(
    page.getByRole("heading", { name: "Pesanan saya", exact: true })
  ).toBeVisible();
  await logout(page);
  run("reset-limits");
  await page.getByRole("link", { name: "Lupa password?", exact: true }).click();
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page.getByRole("button", { name: "Kirim instruksi pemulihan" }).click();
  await expect(page.getByRole("status")).toBeVisible();
  const reset = run("mail").token;
  await page.goto(
    environment.APP_URL + "/akun/password-reset/confirm#token=" + reset
  );
  await page.getByLabel("Password baru", { exact: true }).fill(password);
  await page.getByLabel("Ulangi password baru", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Simpan password baru" }).click();
  await expect(
    page.getByRole("heading", { name: "Masuk ke akun pelanggan" })
  ).toBeVisible();
  await page.goto(
    environment.APP_URL + "/akun/password-reset/confirm#token=" + "f".repeat(64)
  );
  await page.getByLabel("Password baru", { exact: true }).fill(password);
  await page.getByLabel("Ulangi password baru", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Simpan password baru" }).click();
  await expect(page.getByRole("alert")).toContainText("kedaluwarsa");
  await page
    .getByRole("link", { name: "Masuk tanpa password melalui link email" })
    .click();
  await page
    .getByLabel("Email", { exact: true })
    .fill("legacy-browser@example.test");
  await page.getByRole("button", { name: "Kirim link daftar / masuk" }).click();
  const magic = run("mail").token;
  await page.goto(environment.APP_URL + "/akun/login-link#token=" + magic);
  await page.getByRole("button", { name: "Konfirmasi link dan masuk" }).click();
  await expect(
    page.getByRole("heading", { name: "Pesanan saya", exact: true })
  ).toBeVisible();
});

test("mobile, tablet, desktop and dark account states retain focus and usable controls", async ({
  page,
}, testInfo) => {
  for (const width of [320, 768, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    await account(page);
    for (const view of ["login", "register", "code", "reset", "magic"]) {
      await page.goto(environment.APP_URL + "/akun?view=" + view);
      await noOverflow(page);
      await expect(
        page.getByRole("button", { name: "Masuk dengan Telegram", exact: true })
      ).toHaveCount(0);
      await expect(page.getByRole("button", { name: /Google/ })).toHaveCount(0);
    }
  }
  await page.setViewportSize({ width: 320, height: 900 });
  await account(page);
  await page.getByLabel("Email", { exact: true }).focus();
  await page.keyboard.press("Tab");
  expect(
    await page
      .getByLabel("Password", { exact: true })
      .evaluate((input) => input === document.activeElement)
  ).toBe(true);
  expect(
    await page
      .getByLabel("Password", { exact: true })
      .evaluate((input) => getComputedStyle(input).outlineStyle)
  ).not.toBe("none");
  await page.keyboard.press("Tab");
  await page.keyboard.press("Enter");
  await expect(page.getByLabel("Password", { exact: true })).toHaveAttribute(
    "type",
    "text"
  );
  await page.screenshot({
    path: testInfo.outputPath("account-mobile.png"),
    fullPage: true,
  });
  await page.evaluate(() => (document.documentElement.dataset.theme = "dark"));
  await noOverflow(page);
  await page.screenshot({
    path: testInfo.outputPath("account-mobile-dark.png"),
    fullPage: true,
  });
  await page.evaluate(() => (document.documentElement.style.fontSize = "200%"));
  await noOverflow(page);
});

test("no-JavaScript forms support login and unknown-email OTP without account enumeration", async ({
  browser,
}) => {
  const context = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 320, height: 900 },
  });
  const page = await context.newPage();
  await account(page);
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Masuk dengan password" }).click();
  await expect(
    page.getByRole("heading", { name: "Pesanan saya", exact: true })
  ).toBeVisible();
  await logout(page);
  await page
    .getByRole("link", { name: "Masuk dengan kode email", exact: true })
    .click();
  await page
    .getByLabel("Email", { exact: true })
    .fill("unknown-browser@example.test");
  await page
    .getByRole("button", { name: "Kirim kode email", exact: true })
    .click();
  await expect(page.getByLabel("Kode email", { exact: true })).toBeVisible();
  await page.getByLabel("Kode email", { exact: true }).fill("000000");
  await page.getByRole("button", { name: "Konfirmasi kode dan masuk" }).click();
  await expect(page.getByRole("alert")).toBeVisible();
  await noOverflow(page);
  await context.close();
});

test("no-JavaScript verification rejects an expired registration and offers recovery", async ({
  browser,
}) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.goto(environment.APP_URL + "/akun?view=register");
  await page
    .getByLabel("Email", { exact: true })
    .fill("expired-browser@example.test");
  await page.getByLabel("Password baru", { exact: true }).fill(password);
  await page.getByLabel("Ulangi password baru", { exact: true }).fill(password);
  await page
    .getByRole("button", { name: "Daftar dan kirim verifikasi" })
    .click();
  const token = run("mail").token;
  run("expire");
  await page.goto(environment.APP_URL + "/akun/verifikasi-email");
  await page.getByLabel("Kode dari link email").fill(token);
  await page
    .getByRole("button", { name: "Konfirmasi email dan masuk" })
    .click();
  await expect(page.getByRole("alert")).toContainText("kedaluwarsa");
  await expect(
    page.getByRole("button", { name: "Masuk dengan password" })
  ).toBeVisible();
  await context.close();
});

test("configured Google and Telegram forms link, unlink and sign in without changing ownership", async ({
  page,
}) => {
  run("providers-on");
  run("reset-limits");
  try {
    await page.setViewportSize({ width: 320, height: 900 });
    await account(page);
    await page.getByLabel("Email", { exact: true }).fill(email);
    await page.getByLabel("Password", { exact: true }).fill(password);
    await page.getByRole("button", { name: "Masuk dengan password" }).click();
    await page.goto(environment.APP_URL + "/akun/keamanan");
    await page
      .getByRole("button", { name: "Hubungkan Google", exact: true })
      .click();
    await expect(
      page.getByRole("button", { name: "Lepas Google", exact: true })
    ).toBeVisible();
    await noOverflow(page);
    await page
      .getByRole("button", { name: "Lepas Google", exact: true })
      .click();
    await expect(
      page.getByRole("button", { name: "Hubungkan Google", exact: true })
    ).toBeVisible();
    await page
      .getByRole("button", { name: "Hubungkan Telegram", exact: true })
      .click();
    await page
      .getByRole("button", { name: "Periksa konfirmasi Telegram" })
      .click();
    await expect(
      page.getByText("Konfirmasi belum diterima.", { exact: false })
    ).toBeVisible();
    const deepLink = await page
      .getByRole("link", { name: "Buka Store Bot", exact: true })
      .getAttribute("href");
    if (
      !run("telegram-confirm", {
        capability: new URL(deepLink).searchParams.get("start"),
      }).confirmed
    )
      throw new Error("Disposable Telegram proof failed.");
    await page
      .getByRole("button", { name: "Periksa konfirmasi Telegram" })
      .click();
    await expect(
      page.getByText("Telegram sudah terhubung.", { exact: false })
    ).toBeVisible();
    await page
      .getByRole("button", { name: "Keluar dari akun pelanggan" })
      .click();
    await page
      .getByRole("button", { name: "Masuk dengan Telegram", exact: true })
      .click();
    const loginLink = await page
      .getByRole("link", { name: "Buka Store Bot", exact: true })
      .getAttribute("href");
    if (
      !run("telegram-confirm", {
        capability: new URL(loginLink).searchParams.get("start"),
      }).confirmed
    )
      throw new Error("Disposable Telegram login proof failed.");
    await page
      .getByRole("button", { name: "Periksa konfirmasi Telegram" })
      .click();
    await expect(
      page.getByRole("heading", { name: "Pesanan saya", exact: true })
    ).toBeVisible();
    await logout(page);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.getByRole("link", { name: "Daftar", exact: true }).click();
    await page
      .getByRole("link", { name: "Daftar dengan Google", exact: true })
      .click();
    await expect(
      page.getByRole("heading", { name: "Pesanan saya", exact: true })
    ).toBeVisible();
    await logout(page);
    await page
      .getByRole("link", { name: "Masuk dengan Google", exact: true })
      .click();
    await expect(
      page.getByRole("heading", { name: "Pesanan saya", exact: true })
    ).toBeVisible();
    await noOverflow(page);
  } finally {
    run("providers-off");
  }
});

test("rollback retains the working magic-link and Recovery Key surface on mobile and desktop", async ({
  page,
}) => {
  run("rollback");
  run("reset-limits");
  try {
    for (const width of [320, 1280]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(environment.APP_URL + "/akun?view=register");
      await expect(
        page.getByRole("button", { name: "Kirim link daftar / masuk" })
      ).toBeVisible();
      await expect(
        page.getByRole("link", { name: "Daftar", exact: true })
      ).toHaveCount(0);
      await expect(
        page.getByRole("button", { name: "Masuk dengan password" })
      ).toHaveCount(0);
      await noOverflow(page);
    }
    await page
      .getByLabel("Email", { exact: true })
      .fill("rollback-browser@example.test");
    await page
      .getByRole("button", { name: "Kirim link daftar / masuk" })
      .click();
    const token = run("mail").token;
    await page.goto(environment.APP_URL + "/akun/login-link#token=" + token);
    await page
      .getByRole("button", { name: "Konfirmasi link dan masuk" })
      .click();
    await expect(
      page.getByRole("heading", { name: "Pesanan saya", exact: true })
    ).toBeVisible();
    await page.goto(environment.APP_URL + "/akun/keamanan");
    await expect(
      page.getByRole("button", { name: "Simpan password pertama" })
    ).toHaveCount(0);
    await page
      .getByRole("button", { name: "Keluar dari akun pelanggan" })
      .click();
    await page
      .getByRole("link", { name: "Akses dengan invoice + Recovery Key" })
      .click();
    await expect(page.getByLabel("Nomor invoice")).toBeVisible();
  } finally {
    run("enable");
  }
});

test("Telegram-only accounts verify an unused email on the same account", async ({
  page,
}) => {
  run("providers-on");
  run("reset-limits");
  run("telegram-only");
  try {
    await page.setViewportSize({ width: 320, height: 900 });
    await account(page);
    await page
      .getByRole("button", { name: "Masuk dengan Telegram", exact: true })
      .click();
    const deepLink = await page
      .getByRole("link", { name: "Buka Store Bot", exact: true })
      .getAttribute("href");
    if (
      !run("telegram-confirm", {
        capability: new URL(deepLink).searchParams.get("start"),
        actor: "7654322",
      }).confirmed
    )
      throw new Error("Disposable Telegram-only proof failed.");
    await page
      .getByRole("button", { name: "Periksa konfirmasi Telegram" })
      .click();
    await expect(
      page.getByRole("heading", { name: "Pesanan saya", exact: true })
    ).toBeVisible();
    await page.goto(environment.APP_URL + "/akun/keamanan");
    await page
      .getByLabel("Email untuk verifikasi")
      .fill("telegram-enrollment@example.test");
    await page
      .getByRole("button", { name: "Kirim verifikasi email", exact: true })
      .click();
    const token = run("mail").token;
    await page.goto(
      environment.APP_URL + "/akun/verifikasi-email#token=" + token
    );
    await page
      .getByRole("button", { name: "Konfirmasi email dan masuk" })
      .click();
    await expect(
      page.getByRole("heading", { name: "Pesanan saya", exact: true })
    ).toBeVisible();
    await page.goto(environment.APP_URL + "/akun/keamanan");
    await expect(page.getByLabel("Email untuk verifikasi")).toHaveCount(0);
    await expect(
      page.getByRole("button", { name: "Simpan password pertama", exact: true })
    ).toBeVisible();
    await expect(
      page.getByText("Telegram sudah terhubung.", { exact: false })
    ).toBeVisible();
    await noOverflow(page);
  } finally {
    run("providers-off");
  }
});
