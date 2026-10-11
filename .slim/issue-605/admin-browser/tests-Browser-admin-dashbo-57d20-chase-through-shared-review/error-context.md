# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: tests/Browser/admin-dashboard-responsive.spec.js >> customer checkout: completes a multi-item Cart purchase through shared review
- Location: tests/Browser/admin-dashboard-responsive.spec.js:995:1

# Error details

```
Test timeout of 30000ms exceeded.
```

```
Error: locator.click: Test timeout of 30000ms exceeded.
Call log:
  - waiting for locator('article.product-card').filter({ hasText: 'Browser QA Produk A' }).first().getByRole('button', { name: /^Tambahkan .+ ke keranjang$/ })
    - locator resolved to <button type="submit" title="Tambah ke keranjang" aria-label="Tambahkan Browser QA Produk A ke keranjang" class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-[0.7rem] border border-[var(--sf-border)] bg-[var(--sf-yellow)] px-3 text-[var(--sf-ink)] transition-colors hover:bg-[var(--sf-surface-warm)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--sf-accent)]">…</button>
  - attempting click action
    2 × waiting for element to be visible, enabled and stable
      - element is not stable
    - retrying click action
    - waiting 20ms
    2 × waiting for element to be visible, enabled and stable
      - element is not stable
    - retrying click action
      - waiting 100ms
    - waiting for element to be visible, enabled and stable
    - element is visible, enabled and stable
    - scrolling into view if needed
    - done scrolling
    - performing click action

```

# Test source

```ts
  5914 |     .filter(
  5915 |       ({ method, pathname }) =>
  5916 |         method === "POST" && pathname === "/shop/checkout"
  5917 |     );
  5918 |   const checkoutNavigations = flow.requests
  5919 |     .slice(start)
  5920 |     .filter(({ pathname }) =>
  5921 |       /^\/shop\/checkout(?:\/products\/\d+)?$/.test(pathname)
  5922 |     );
  5923 | 
  5924 |   expect(
  5925 |     checkoutRequests,
  5926 |     `${label} should submit checkout exactly once`
  5927 |   ).toHaveLength(1);
  5928 |   expect(
  5929 |     checkoutNavigations,
  5930 |     `${label} should visit review and submit exactly once`
  5931 |   ).toHaveLength(2);
  5932 | }
  5933 | // Chromium's CDP page scale changes the visual viewport and proves the real
  5934 | // 200% scale when supported. It does not change CSS media-query layout, so
  5935 | // the documented fallback halves the CSS viewport to exercise browser-zoom
  5936 | // reflow in headless or non-Chromium environments.
  5937 | async function emulate200PercentCheckoutScale(context, page, viewport) {
  5938 |   let cdp;
  5939 | 
  5940 |   try {
  5941 |     cdp = await context.newCDPSession(page);
  5942 |     await cdp.send("Emulation.setPageScaleFactor", { pageScaleFactor: 2 });
  5943 | 
  5944 |     const scale = await page.evaluate(() => window.visualViewport?.scale ?? 1);
  5945 |     if (Math.abs(scale - 2) <= 0.01) {
  5946 |       return {
  5947 |         mode: "cdp-page-scale",
  5948 |         fallbackViewport: {
  5949 |           width: Math.max(1, Math.floor(viewport.width / 2)),
  5950 |           height: Math.max(1, Math.floor(viewport.height / 2)),
  5951 |         },
  5952 |         reset: async () => {
  5953 |           await cdp
  5954 |             .send("Emulation.resetPageScaleFactor")
  5955 |             .catch(() => undefined);
  5956 |         },
  5957 |       };
  5958 |     }
  5959 |   } catch {
  5960 |     // Fall through to the CSS viewport fallback below.
  5961 |   }
  5962 | 
  5963 |   if (cdp) {
  5964 |     await cdp.send("Emulation.resetPageScaleFactor").catch(() => undefined);
  5965 |   }
  5966 | 
  5967 |   const fallbackViewport = {
  5968 |     width: Math.max(1, Math.floor(viewport.width / 2)),
  5969 |     height: Math.max(1, Math.floor(viewport.height / 2)),
  5970 |   };
  5971 |   await page.setViewportSize(fallbackViewport);
  5972 | 
  5973 |   return {
  5974 |     mode: "half-viewport-fallback",
  5975 |     fallbackViewport,
  5976 |     reset: async () => undefined,
  5977 |   };
  5978 | }
  5979 | 
  5980 | async function navigateToCheckoutFromProductCard(page, productName) {
  5981 |   const card = page
  5982 |     .locator("article.product-card")
  5983 |     .filter({ hasText: productName })
  5984 |     .first();
  5985 |   const checkoutLink = card
  5986 |     .locator('a[href*="/shop/checkout/products/"]')
  5987 |     .filter({ hasText: "Beli sekarang" });
  5988 | 
  5989 |   await expect(card).toBeVisible();
  5990 |   await expect(checkoutLink).toHaveCount(1);
  5991 |   await card.evaluate((element) =>
  5992 |     element.scrollIntoView({
  5993 |       block: "center",
  5994 |       inline: "nearest",
  5995 |       behavior: "instant",
  5996 |     })
  5997 |   );
  5998 |   await expect(card).toHaveCSS("transform", "none");
  5999 |   await expect(card).toHaveCSS("opacity", "1");
  6000 |   await checkoutLink.click({ force: true, noWaitAfter: true });
  6001 |   await page.waitForLoadState("domcontentloaded");
  6002 |   await expectStorefrontPageCheckout(page);
  6003 | }
  6004 | 
  6005 | async function addProductCardToCart(page, productName) {
  6006 |   const card = page
  6007 |     .locator("article.product-card")
  6008 |     .filter({ hasText: productName })
  6009 |     .first();
  6010 | 
  6011 |   await expect(card).toBeVisible();
  6012 |   await card
  6013 |     .getByRole("button", { name: /^Tambahkan .+ ke keranjang$/ })
> 6014 |     .click();
       |      ^ Error: locator.click: Test timeout of 30000ms exceeded.
  6015 |   await expect(page).toHaveURL(/\/shop\/cart(?:\?|$)/);
  6016 | }
  6017 | 
  6018 | async function completeManualCheckout(page, buyerName) {
  6019 |   await expectStorefrontPageCheckout(page);
  6020 |   await page.getByRole("textbox", { name: "Nama pembeli" }).fill(buyerName);
  6021 |   await page.getByRole("checkbox", { name: /Saya menyetujui/ }).check();
  6022 |   await page.locator("[data-checkout-sticky-submit]:visible").click();
  6023 |   await expect(page).toHaveURL(/\/shop\/orders\/\d+/);
  6024 |   await expect(
  6025 |     page.getByRole("heading", { name: "Order QRIS dibuat" })
  6026 |   ).toBeVisible();
  6027 |   await expect(
  6028 |     page.getByRole("heading", { name: "Invoice QRIS" })
  6029 |   ).toBeVisible();
  6030 |   expect(new URL(page.url()).searchParams.get("signature")).not.toBeNull();
  6031 | }
  6032 | 
  6033 | async function expectStorefrontPageCheckout(page) {
  6034 |   await page.waitForLoadState("domcontentloaded");
  6035 |   await expect(page).toHaveURL(/\/shop\/checkout(?:\/products\/\d+)?(?:\?|$)/);
  6036 |   await expect(
  6037 |     page.getByRole("heading", {
  6038 |       name: /Review (produk|pesanan) sebelum bayar\./,
  6039 |     })
  6040 |   ).toBeVisible();
  6041 |   await expect(page.locator("#checkout-form")).toBeVisible();
  6042 |   expect(page.url()).not.toContain("checkout=1");
  6043 | }
  6044 | 
  6045 | async function scrollCheckoutToSettledBottom(page) {
  6046 |   await page.waitForFunction(() => {
  6047 |     const fontsReady = !document.fonts || document.fonts.status === "loaded";
  6048 |     const imagesReady = Array.from(document.images).every(
  6049 |       (image) => image.complete
  6050 |     );
  6051 | 
  6052 |     return fontsReady && imagesReady;
  6053 |   });
  6054 | 
  6055 |   await page.evaluate(async () => {
  6056 |     document.documentElement.style.scrollBehavior = "auto";
  6057 |     document.body.style.scrollBehavior = "auto";
  6058 |     const scrollingElement =
  6059 |       document.scrollingElement ?? document.documentElement;
  6060 |     const nextFrame = () =>
  6061 |       new Promise((resolve) => window.requestAnimationFrame(resolve));
  6062 |     let previousHeight = -1;
  6063 |     let stableFrames = 0;
  6064 | 
  6065 |     for (let attempt = 0; attempt < 120; attempt += 1) {
  6066 |       scrollingElement.scrollTop = scrollingElement.scrollHeight;
  6067 |       await nextFrame();
  6068 | 
  6069 |       const maxScrollTop = Math.max(
  6070 |         0,
  6071 |         scrollingElement.scrollHeight - scrollingElement.clientHeight
  6072 |       );
  6073 |       const sticky = document.querySelector("[data-checkout-sticky-action]");
  6074 |       const form = document.querySelector("#checkout-form");
  6075 |       const stickyRect = sticky?.getBoundingClientRect();
  6076 |       const formRect = form?.getBoundingClientRect();
  6077 |       const atBottom = Math.abs(scrollingElement.scrollTop - maxScrollTop) <= 1;
  6078 |       const formIsUncovered =
  6079 |         stickyRect && formRect ? formRect.bottom <= stickyRect.top + 1 : false;
  6080 |       const heightIsStable = scrollingElement.scrollHeight === previousHeight;
  6081 | 
  6082 |       if (atBottom && formIsUncovered && heightIsStable) {
  6083 |         stableFrames += 1;
  6084 |         if (stableFrames >= 2) {
  6085 |           return;
  6086 |         }
  6087 |       } else {
  6088 |         stableFrames = 0;
  6089 |       }
  6090 | 
  6091 |       previousHeight = scrollingElement.scrollHeight;
  6092 |     }
  6093 | 
  6094 |     throw new Error(
  6095 |       `Checkout did not settle at the bottom: ${JSON.stringify({
  6096 |         scrollTop: scrollingElement.scrollTop,
  6097 |         maxScrollTop: Math.max(
  6098 |           0,
  6099 |           scrollingElement.scrollHeight - scrollingElement.clientHeight
  6100 |         ),
  6101 |         documentHeight: scrollingElement.scrollHeight,
  6102 |         stickyTop: document
  6103 |           .querySelector("[data-checkout-sticky-action]")
  6104 |           ?.getBoundingClientRect().top,
  6105 |         formBottom: document
  6106 |           .querySelector("#checkout-form")
  6107 |           ?.getBoundingClientRect().bottom,
  6108 |       })}`
  6109 |     );
  6110 |   });
  6111 | }
  6112 | 
  6113 | async function expectStorefrontCheckoutRecovery(page) {
  6114 |   await page.waitForLoadState("domcontentloaded");
```