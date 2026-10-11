# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: tests/Browser/buymail-warranty.spec.js >> supplier creation confirms password without losing the unsaved API key
- Location: tests/Browser/buymail-warranty.spec.js:183:1

# Error details

```
Test timeout of 30000ms exceeded.
```

```
Error: expect(locator).toBeVisible() failed

Locator:  getByRole('dialog', { name: 'Konfirmasi password', exact: true })
Expected: visible
Received: undefined

Call log:
  - Expect "toBeVisible" with timeout 5000ms
  - waiting for getByRole('dialog', { name: 'Konfirmasi password', exact: true })

```

```
Error: "route.fetch: Test timeout of 30000ms exceeded.
Call log:
  - → POST http://127.0.0.1:41751/admin/suppliers
    - user-agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/149.0.7827.55 Safari/537.36
    - accept: application/json
    - accept-encoding: gzip,deflate,br
    - accept-language: en-US
    - content-type: multipart/form-data; boundary=----WebKitFormBoundaryZmuKIFzJX0peZdCk
    - cookie: XSRF-TOKEN=eyJpdiI6IloxQnN4clBXNkdWZzVidG9KQi92L2c9PSIsInZhbHVlIjoiZTFMOUUyRnppUXRGYzVjRXR5U2I4UkFKMmZHOTkzSU1mL0dZVW5nT0xQaWtuN2c5eWVzZUdxV0pldDMzYWtDQk5oVWpyM1ZnekhndXVwdHJDemdrNVp3ZDZBM2FZZUNwUjFTUGVDbnFtODFUUEp4QzEyekp3M3ZHVkFGVnFuUHYiLCJtYWMiOiIyNjMyYzBiMzRiNzliNTU3M2Y3OGJiNzc1ZTE0ZmQ0ZTEzNzE5N2RmYTc4MmVlZmFlMTU3ZDZjNzIzOTdmMjU0IiwidGFnIjoiIn0%3D; laravel-session=eyJpdiI6IlIxcXo2YVJPaTVVTDJ3YlZDenYxTEE9PSIsInZhbHVlIjoieHVjeEVuM2x0NHZCZjdzVjVsU3o2NGsxTjhxenZwZWMycFlpZ2xTRFBoUU95WFlnaVQ1K1NGU3RXSjBWZ2xISmhFV1BPc1ZKc3p6U2dBR1NmVVN5ODRhNlFkZnNJUnR6UnQ2RkdaaldsY2xrTFJCMERsaTdsazZhcGkvNEhOR2IiLCJtYWMiOiI1NDkyNTdiYzc3ZDM0ZDU5NDhhZjc4NDg1YjIyNDQ0M2JjYWRlZTFmZWIyZTNkM2Y1YmJkNmM0MzUzNmQ4N2E1IiwidGFnIjoiIn0%3D
    - origin: http://127.0.0.1:41751
    - referer: http://127.0.0.1:41751/admin?tab=suppliers
    - sec-ch-ua: "HeadlessChrome";v="149", "Chromium";v="149", "Not)A;Brand";v="24"
    - sec-ch-ua-mobile: ?0
    - sec-ch-ua-platform: "Linux"
    - content-length: 731
" while running route callback.
Consider awaiting `await page.unrouteAll({ behavior: 'ignoreErrors' })`
before the end of the test to ignore remaining routes in flight.
```

# Test source

```ts
  99  |     await page.evaluate(
  100 |       () => document.documentElement.scrollWidth <= window.innerWidth
  101 |     )
  102 |   ).toBe(true);
  103 |   await page
  104 |     .getByRole("link", { name: "Bahasa Indonesia", exact: true })
  105 |     .click();
  106 |   await expect(
  107 |     page.getByRole("heading", { name: "Bantuan akun dan pengembalian dana" })
  108 |   ).toBeVisible();
  109 |   await page.getByRole("link", { name: "English", exact: true }).click();
  110 |   await page.getByRole("button", { name: "Refund this account" }).click();
  111 |   await expect(
  112 |     page.getByText("The refund for this account has been completed.", {
  113 |       exact: true,
  114 |     })
  115 |   ).toBeVisible();
  116 |   await expect(
  117 |     page.getByRole("button", { name: "Replace this account" })
  118 |   ).toHaveCount(0);
  119 |   expect(await page.locator("body").innerText()).not.toContain(
  120 |     "synthetic-password"
  121 |   );
  122 |   await page.screenshot({
  123 |     path: testInfo.outputPath("warranty-refunded-mobile.png"),
  124 |     fullPage: true,
  125 |   });
  126 | });
  127 | 
  128 | test("owner can inspect private warranty and deposit panels without exposing the saved key", async ({
  129 |   page,
  130 | }, testInfo) => {
  131 |   await page.setViewportSize({ width: 390, height: 844 });
  132 |   await page.goto(environment.APP_URL + "/admin/login");
  133 |   await page.locator('[name="email"]').fill("warranty-browser@example.test");
  134 |   await page.locator('[name="password"]').fill("synthetic-browser-password");
  135 |   await page.getByRole("button", { name: "Masuk Dashboard" }).click();
  136 |   await page.goto(environment.APP_URL + "/admin?tab=suppliers");
  137 |   await expect(
  138 |     page.getByText("Private source", { exact: false }).first()
  139 |   ).toBeVisible();
  140 |   expect(await page.locator("body").innerText()).not.toContain(
  141 |     "synthetic-test-key"
  142 |   );
  143 |   await page
  144 |     .getByRole("link", { name: "Klaim Gmail / refund / penggantian" })
  145 |     .click();
  146 |   await expect(
  147 |     page.getByRole("banner", { name: "Konteks halaman" }).getByRole("heading", {
  148 |       name: "Klaim Gmail · Refund & penggantian",
  149 |       exact: true,
  150 |     })
  151 |   ).toBeVisible();
  152 |   await expect(
  153 |     page.getByText("Klaim harus sampai ke supplier sebelum deadline.", {
  154 |       exact: false,
  155 |     })
  156 |   ).toBeVisible();
  157 |   expect(
  158 |     await page.evaluate(
  159 |       () => document.documentElement.scrollWidth <= window.innerWidth
  160 |     )
  161 |   ).toBe(true);
  162 |   await page.screenshot({
  163 |     path: testInfo.outputPath("warranty-admin-mobile.png"),
  164 |     fullPage: true,
  165 |   });
  166 |   await page.goto(environment.APP_URL + "/admin?tab=suppliers");
  167 |   await page
  168 |     .getByRole("link", { name: "Deposit BuyMail & histori privat" })
  169 |     .click();
  170 |   await expect(
  171 |     page.getByRole("button", { name: "Buat satu instruksi top-up supplier" })
  172 |   ).toBeVisible();
  173 |   expect(await page.locator("body").innerText()).not.toContain(
  174 |     "synthetic-test-key"
  175 |   );
  176 |   expect(
  177 |     await page.evaluate(
  178 |       () => document.documentElement.scrollWidth <= window.innerWidth
  179 |     )
  180 |   ).toBe(true);
  181 | });
  182 | 
  183 | test("supplier creation confirms password without losing the unsaved API key", async ({
  184 |   page,
  185 | }) => {
  186 |   const apiKey = "synthetic-browser-unsaved-api-key";
  187 |   const replacement = "synthetic-browser-replacement-key";
  188 |   const keys = [apiKey, replacement];
  189 |   const responses = [];
  190 |   // Inspect the actual response before browser navigation can evict Chromium's
  191 |   // response-body handle. Fetch once, preserve redirects and fulfill unchanged.
  192 |   await page.route(environment.APP_URL + "/**", async (route) => {
  193 |     if (
  194 |       !["document", "fetch", "xhr"].includes(route.request().resourceType())
  195 |     ) {
  196 |       await route.continue();
  197 |       return;
  198 |     }
> 199 |     const response = await route.fetch({ maxRedirects: 0 });
      |                                  ^ Error: "route.fetch: Test timeout of 30000ms exceeded.
  200 |     responses.push({
  201 |       text: JSON.stringify(response.headers()) + "\n" + (await response.text()),
  202 |     });
  203 |     await route.fulfill({ response });
  204 |   });
  205 |   const initial = supplierState(keys);
  206 |   await page.goto(environment.APP_URL + "/admin/login");
  207 |   await page.locator('[name="email"]').fill("warranty-browser@example.test");
  208 |   await page.locator('[name="password"]').fill("synthetic-browser-password");
  209 |   await page
  210 |     .getByRole("button", { name: "Masuk Dashboard", exact: true })
  211 |     .click();
  212 |   await page.waitForURL(environment.APP_URL + "/admin");
  213 |   await page.goto(environment.APP_URL + "/admin?tab=suppliers");
  214 |   await page.getByText("Tambah supplier", { exact: true }).click();
  215 |   const form = page.locator('form[action$="/admin/suppliers"]').first();
  216 |   await form.locator('[name="api_key"]').fill(apiKey);
  217 |   await form.getByLabel("Pilih supplier").selectOption("prodseller");
  218 |   await form.locator('[name="exchange_rate_idr"]').fill("18000");
  219 |   await form
  220 |     .getByRole("button", { name: "Buat supplier", exact: true })
  221 |     .click();
  222 |   const dialog = page.getByRole("dialog", {
  223 |     name: "Konfirmasi password",
  224 |     exact: true,
  225 |   });
  226 |   await expect(dialog).toBeVisible();
  227 |   await expect(form.locator('[name="api_key"]')).toHaveValue(apiKey);
  228 |   await dialog
  229 |     .getByLabel("Password admin", { exact: true })
  230 |     .fill("incorrect-synthetic-password");
  231 |   await dialog.getByRole("button", { name: "Konfirmasi dan simpan" }).click();
  232 |   await expect(dialog.getByRole("alert")).toContainText("Password");
  233 |   await expect(form.locator('[name="api_key"]')).toHaveValue(apiKey);
  234 |   expect(supplierState(keys).suppliers).toEqual(initial.suppliers);
  235 |   await expectNoSecretLeak(page, keys, responses);
  236 |   await dialog.getByRole("button", { name: "Batal", exact: true }).click();
  237 |   await expect(dialog).not.toBeVisible();
  238 |   await expect(form.locator('[name="api_key"]')).toHaveValue(apiKey);
  239 |   await expect(form.locator('[name="exchange_rate_idr"]')).toHaveValue("18000");
  240 |   expect(supplierState(keys).suppliers).toEqual(initial.suppliers);
  241 |   await expectNoSecretLeak(page, keys, responses);
  242 |   await form
  243 |     .getByRole("button", { name: "Buat supplier", exact: true })
  244 |     .click();
  245 |   await expect(dialog).toBeVisible();
  246 |   await dialog
  247 |     .getByLabel("Password admin", { exact: true })
  248 |     .fill("synthetic-browser-password");
  249 |   await dialog.getByRole("button", { name: "Konfirmasi dan simpan" }).click();
  250 |   await page.waitForURL(environment.APP_URL + "/admin?tab=suppliers");
  251 |   await expect(
  252 |     page.getByText(
  253 |       "Supplier dibuat. Sinkronkan sebelum mengaktifkan pemetaan.",
  254 |       { exact: true }
  255 |     )
  256 |   ).toBeVisible();
  257 |   await expect(page.locator("body")).not.toContainText(apiKey);
  258 |   const createdState = supplierState(keys);
  259 |   expect(createdState.suppliers).toHaveLength(initial.suppliers.length + 1);
  260 |   const created = createdState.suppliers.filter(
  261 |     (supplier) =>
  262 |       !initial.suppliers.some((existing) => existing.id === supplier.id)
  263 |   );
  264 |   expect(created).toHaveLength(1);
  265 |   expect(created[0]).toMatchObject({
  266 |     provider: "prodseller",
  267 |     key_matches: [true, false],
  268 |     key_encrypted: true,
  269 |   });
  270 |   expect(
  271 |     createdState.suppliers.filter((supplier) => supplier.id !== created[0].id)
  272 |   ).toEqual(initial.suppliers);
  273 |   await expectNoSecretLeak(page, keys, responses);
  274 | 
  275 |   // A fresh session must also preserve an edited key when confirmation is needed.
  276 |   await page.context().clearCookies();
  277 |   await page.goto(environment.APP_URL + "/admin/login");
  278 |   await page.locator('[name="email"]').fill("warranty-browser@example.test");
  279 |   await page.locator('[name="password"]').fill("synthetic-browser-password");
  280 |   await page
  281 |     .getByRole("button", { name: "Masuk Dashboard", exact: true })
  282 |     .click();
  283 |   await page.waitForURL(environment.APP_URL + "/admin");
  284 |   await page.goto(environment.APP_URL + "/admin?tab=suppliers");
  285 |   const connection = page.locator("article").filter({
  286 |     has: page.locator(`form[action$="/admin/suppliers/${created[0].id}"]`),
  287 |   });
  288 |   await connection
  289 |     .locator("summary")
  290 |     .filter({ hasText: "Edit koneksi" })
  291 |     .click();
  292 |   const editForm = connection
  293 |     .locator("form")
  294 |     .filter({ has: page.locator('[name="api_key"]') });
  295 |   await editForm.locator('[name="api_key"]').fill(replacement);
  296 |   await editForm
  297 |     .getByRole("button", { name: "Simpan supplier", exact: true })
  298 |     .click();
  299 |   const editDialog = connection.getByRole("dialog", {
```