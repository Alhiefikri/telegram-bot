import { expect, test } from "@playwright/test";
import { spawn, execFileSync } from "node:child_process";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import net from "node:net";
import path from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";

const appDir = fileURLToPath(new URL("../..", import.meta.url));
let baseUrl, fixture, server, invoiceUrl;

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
  const options = {
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
    },
  };
  execFileSync("php", ["artisan", "migrate", "--force"], options);
  invoiceUrl =
    baseUrl +
    execFileSync("php", ["artisan", "tinker", "--execute", seed], options)
      .toString()
      .trim();
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

const sizes = [
  [1366, 768],
  [390, 844],
  [320, 568],
];
for (const [width, height] of sizes) {
  for (const theme of ["light", "dark"]) {
    test(`${width}x${height} ${theme}: search, picker and first buy action fit`, async ({
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
          ? page.getByRole("navigation", { name: "Pilih produk" })
          : page.getByRole("combobox", { name: "Pilih produk" });
      const first = page.locator("[data-home-plan]").first();
      for (const element of [search, picker, first]) {
        await expect(element).toBeInViewport({ ratio: 1 });
      }
      await expect(
        first.getByRole("link", { name: "Beli sekarang" })
      ).toBeInViewport({ ratio: 1 });
      await expect(page.locator("html")).toHaveAttribute("data-theme", theme);
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth)
      ).toBe(width);
      await page.screenshot({
        path: info.outputPath(`${width}-${height}-${theme}.png`),
      });

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
    await expect(page).toHaveURL(/\?family=creative&q=bulanan$/);
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
    await page.getByRole("link", { name: "Desain Bulanan" }).click();
    await expect(page).toHaveURL(/\/produk\/\d+/);
    await page
      .getByRole("link", { name: "Kembali ke katalog" })
      .first()
      .click();
    await expect(page).toHaveURL(/\?family=creative&q=bulanan#product-\d+$/);
  } finally {
    await context.close();
  }
});

test("320px catalog still fits at 200% root text size", async ({
  page,
}, info) => {
  await page.setViewportSize({ width: 320, height: 568 });
  await page.goto(`${baseUrl}/?family=creative`);
  await page.addStyleTag({ content: "html { font-size: 200% }" });
  for (const control of await page
    .locator(
      "#products select, #products input, #products button, [data-home-plan] a"
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
$creative = \App\Models\ProductFamily::factory()->create(['slug'=>'creative','name'=>'Creative Studio', 'sort_order'=>1, 'icon'=>'🎨']);
$audio = \App\Models\ProductFamily::factory()->create(['slug'=>'audio','name'=>'Audio Library', 'sort_order'=>2]);
foreach ([[$creative, 'Desain Bulanan', 8], [$creative, 'Desain Tahunan', 0], [$audio, 'Musik Bulanan', 4], [null, 'Penyimpanan Bulanan', 2]] as $index => [$family, $name, $stock]) {
    \App\Models\Product::factory()->create(['name'=>$name, 'product_family_id'=>$family?->id, 'family_sort_order'=>$index, 'price_amount'=>35000, 'access_duration'=>'30 hari', 'stock'=>$stock, 'reserved_stock'=>0, 'is_active'=>true]);
}
$order = \App\Models\Order::factory()->create();
echo \Illuminate\Support\Facades\URL::temporarySignedRoute('storefront.shop.orders.show', now()->addHour(), $order, absolute: false);
`;
