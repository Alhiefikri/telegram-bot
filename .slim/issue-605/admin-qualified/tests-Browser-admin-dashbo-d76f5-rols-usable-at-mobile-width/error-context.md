# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: tests/Browser/admin-dashboard-responsive.spec.js >> keeps grouped Product Family controls usable at mobile width
- Location: tests/Browser/admin-dashboard-responsive.spec.js:2918:1

# Error details

```
Error: admin body text must not be hidden by horizontal clipping

expect(received).toEqual(expected) // deep equality

- Expected  -  1
+ Received  + 65

- Array []
+ Array [
+   Object {
+     "clipLeft": 62,
+     "clipRight": 63,
+     "label": "Product Family",
+     "left": 62,
+     "right": 176,
+   },
+   Object {
+     "clipLeft": 62,
+     "clipRight": 63,
+     "label": "Urutan option",
+     "left": 62,
+     "right": 164,
+   },
+   Object {
+     "clipLeft": 62,
+     "clipRight": 63,
+     "label": "Label option",
+     "left": 62,
+     "right": 158,
+   },
+   Object {
+     "clipLeft": 62,
+     "clipRight": 63,
+     "label": "Product Family",
+     "left": 62,
+     "right": 176,
+   },
+   Object {
+     "clipLeft": 62,
+     "clipRight": 63,
+     "label": "Urutan option",
+     "left": 62,
+     "right": 164,
+   },
+   Object {
+     "clipLeft": 62,
+     "clipRight": 63,
+     "label": "Label option",
+     "left": 62,
+     "right": 158,
+   },
+   Object {
+     "clipLeft": 62,
+     "clipRight": 63,
+     "label": "Product Family",
+     "left": 62,
+     "right": 176,
+   },
+   Object {
+     "clipLeft": 62,
+     "clipRight": 63,
+     "label": "Urutan option",
+     "left": 62,
+     "right": 164,
+   },
+   Object {
+     "clipLeft": 62,
+     "clipRight": 63,
+     "label": "Label option",
+     "left": 62,
+     "right": 158,
+   },
+ ]
```

# Test source

```ts
  5402 |   const manageModal = page.getByRole("dialog", { name: "Kelola Stok" });
  5403 |   await expect(manageModal).toBeVisible();
  5404 |   await expect(manageModal.getByText("#1")).toBeVisible();
  5405 |   await expect(
  5406 |     manageModal.getByText("browser-stock-a@example.test|pass")
  5407 |   ).toBeVisible();
  5408 |   await expect(manageModal.getByRole("textbox")).toBeHidden();
  5409 |   await manageModal
  5410 |     .getByRole("button", { name: "Ubah akun stok" })
  5411 |     .first()
  5412 |     .click();
  5413 |   await expect(manageModal.getByRole("textbox")).toBeVisible();
  5414 |   await manageModal.getByRole("button", { name: "Batal" }).click();
  5415 |   await expect(manageModal.getByRole("textbox")).toBeHidden();
  5416 |   await manageModal.getByRole("button", { name: /Tambah stok baru/ }).click();
  5417 | 
  5418 |   const reopenedStockModal = page.getByRole("dialog", { name: "Tambah Stok" });
  5419 |   await expect(reopenedStockModal).toBeVisible();
  5420 |   await reopenedStockModal.getByRole("button", { name: "Batal" }).click();
  5421 |   await expect(reopenedStockModal).toBeHidden();
  5422 | }
  5423 | 
  5424 | // A badge narrower than its own longest word means the label wrapped per
  5425 | // character, which is unreadable. Wrapping between words stays allowed.
  5426 | async function expectStatusBadgesReadable(container) {
  5427 |   const broken = await container
  5428 |     .locator("span.rounded-full")
  5429 |     .evaluateAll((elements) =>
  5430 |       elements
  5431 |         .filter((element) => {
  5432 |           const rect = element.getBoundingClientRect();
  5433 |           const style = window.getComputedStyle(element);
  5434 | 
  5435 |           return (
  5436 |             style.display !== "none" &&
  5437 |             style.visibility !== "hidden" &&
  5438 |             rect.width > 0 &&
  5439 |             (element.textContent || "").trim().length >= 3
  5440 |           );
  5441 |         })
  5442 |         .map((element) => {
  5443 |           const style = window.getComputedStyle(element);
  5444 |           const context = document.createElement("canvas").getContext("2d");
  5445 |           context.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
  5446 |           const label = (element.textContent || "").trim();
  5447 |           const longestWord = Math.max(
  5448 |             ...label.split(/\s+/).map((word) => context.measureText(word).width)
  5449 |           );
  5450 | 
  5451 |           return {
  5452 |             label,
  5453 |             width: Math.round(element.getBoundingClientRect().width),
  5454 |             longestWord: Math.round(longestWord),
  5455 |           };
  5456 |         })
  5457 |         .filter((element) => element.width + 1 < element.longestWord)
  5458 |     );
  5459 | 
  5460 |   expect(
  5461 |     broken,
  5462 |     `badge labels must not wrap per character: ${JSON.stringify(broken)}`
  5463 |   ).toEqual([]);
  5464 | }
  5465 | 
  5466 | async function expectNoDocumentOverflow(page) {
  5467 |   const metrics = await page.evaluate(() => {
  5468 |     return {
  5469 |       bodyScrollWidth: document.body.scrollWidth,
  5470 |       documentScrollWidth: document.documentElement.scrollWidth,
  5471 |       viewportWidth: window.innerWidth,
  5472 |     };
  5473 |   });
  5474 | 
  5475 |   const clippedAdminText = await page.evaluate(() => {
  5476 |     const content = document.querySelector(
  5477 |       ".admin-page main, .admin-dashboard-page main"
  5478 |     );
  5479 |     if (!content) return [];
  5480 | 
  5481 |     const clipped = [];
  5482 |     const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
  5483 |     while (walker.nextNode()) {
  5484 |       const node = walker.currentNode;
  5485 |       const label = node.textContent.trim();
  5486 |       if (!label || !node.parentElement.checkVisibility()) continue;
  5487 |       const range = document.createRange();
  5488 |       range.selectNodeContents(node);
  5489 |       for (const rect of range.getClientRects()) {
  5490 |         if (!rect.width || !rect.height) continue;
  5491 |         let left = 0;
  5492 |         let right = window.innerWidth;
  5493 |         let scrollable = false;
  5494 |         let ellipsis = false;
  5495 |         for (
  5496 |           let ancestor = node.parentElement;
  5497 |           ancestor;
  5498 |           ancestor = ancestor.parentElement
  5499 |         ) {
  5500 |           const style = getComputedStyle(ancestor);
  5501 |           if (style.textOverflow === "ellipsis") ellipsis = true;
> 5502 |           if (["auto", "scroll"].includes(style.overflowX)) {
       |     ^ Error: admin body text must not be hidden by horizontal clipping
  5503 |             scrollable = true;
  5504 |             break;
  5505 |           }
  5506 |           if (["hidden", "clip"].includes(style.overflowX)) {
  5507 |             const box = ancestor.getBoundingClientRect();
  5508 |             left = Math.max(left, box.left);
  5509 |             right = Math.min(right, box.right);
  5510 |           }
  5511 |         }
  5512 |         if (
  5513 |           !scrollable &&
  5514 |           !ellipsis &&
  5515 |           (rect.left < left - 1 || rect.right > right + 1)
  5516 |         ) {
  5517 |           clipped.push({
  5518 |             label: label.slice(0, 100),
  5519 |             left: rect.left,
  5520 |             right: rect.right,
  5521 |             clipLeft: left,
  5522 |             clipRight: right,
  5523 |           });
  5524 |           break;
  5525 |         }
  5526 |       }
  5527 |     }
  5528 |     return clipped;
  5529 |   });
  5530 | 
  5531 |   expect(
  5532 |     metrics.documentScrollWidth,
  5533 |     "document should not overflow horizontally"
  5534 |   ).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  5535 |   expect(
  5536 |     metrics.bodyScrollWidth,
  5537 |     "body should not overflow horizontally"
  5538 |   ).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  5539 |   expect(
  5540 |     clippedAdminText,
  5541 |     "admin body text must not be hidden by horizontal clipping"
  5542 |   ).toEqual([]);
  5543 | }
  5544 | 
  5545 | async function expectOneColumn(firstLocator, secondLocator) {
  5546 |   const first = await firstLocator.boundingBox();
  5547 |   const second = await secondLocator.boundingBox();
  5548 | 
  5549 |   expect(first).not.toBeNull();
  5550 |   expect(second).not.toBeNull();
  5551 |   const firstCenterX = first.x + first.width / 2;
  5552 |   const secondCenterX = second.x + second.width / 2;
  5553 |   expect(Math.abs(firstCenterX - secondCenterX)).toBeLessThanOrEqual(2);
  5554 | 
  5555 |   const [top, bottom] = first.y <= second.y ? [first, second] : [second, first];
  5556 |   expect(bottom.y).toBeGreaterThanOrEqual(top.y + top.height - 2);
  5557 | }
  5558 | 
  5559 | async function expectBefore(firstLocator, secondLocator) {
  5560 |   const [first, second] = await Promise.all([
  5561 |     firstLocator.first().boundingBox(),
  5562 |     secondLocator.first().boundingBox(),
  5563 |   ]);
  5564 | 
  5565 |   expect(first).not.toBeNull();
  5566 |   expect(second).not.toBeNull();
  5567 |   expect(first.y + first.height).toBeLessThanOrEqual(second.y + 2);
  5568 | }
  5569 | 
  5570 | async function expectPrimaryActionsAtLeast44px(page) {
  5571 |   const tooSmall = await page
  5572 |     .locator("button, a[href]")
  5573 |     .evaluateAll((elements) =>
  5574 |       elements
  5575 |         .filter((element) => {
  5576 |           const style = window.getComputedStyle(element);
  5577 |           const rect = element.getBoundingClientRect();
  5578 | 
  5579 |           return (
  5580 |             style.display !== "none" &&
  5581 |             style.visibility !== "hidden" &&
  5582 |             rect.width > 0 &&
  5583 |             rect.height > 0 &&
  5584 |             (element.matches(".admin-dashboard-action") ||
  5585 |               element.matches(".filter-pill"))
  5586 |           );
  5587 |         })
  5588 |         .map((element) => ({
  5589 |           label: (
  5590 |             element.textContent ||
  5591 |             element.getAttribute("aria-label") ||
  5592 |             element.href ||
  5593 |             element.tagName
  5594 |           ).trim(),
  5595 |           height: element.getBoundingClientRect().height,
  5596 |         }))
  5597 |         .filter((element) => Math.round(element.height) < 44)
  5598 |     );
  5599 | 
  5600 |   expect(
  5601 |     tooSmall,
  5602 |     `brutalist actions below 44px: ${JSON.stringify(tooSmall)}`
```