# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: tests/Browser/admin-dashboard-responsive.spec.js >> no-JavaScript owner tabs expose server content and validation at 320px
- Location: tests/Browser/admin-dashboard-responsive.spec.js:3516:5

# Error details

```
Error: page.evaluate: Execution context was destroyed, most likely because of a navigation.
```

# Test source

```ts
  6492 | 
  6493 |     await control.evaluate((element) =>
  6494 |       element.scrollIntoView({
  6495 |         block: "center",
  6496 |         inline: "nearest",
  6497 |         behavior: "instant",
  6498 |       })
  6499 |     );
  6500 |     await control.focus();
  6501 |     await expect(control).toBeFocused();
  6502 |     reports.push(
  6503 |       await control.evaluate((element) => {
  6504 |         const style = window.getComputedStyle(element);
  6505 |         const rect = element.getBoundingClientRect();
  6506 |         const outlineWidth = Number.parseFloat(style.outlineWidth) || 0;
  6507 |         const outlineOffset = Number.parseFloat(style.outlineOffset) || 0;
  6508 |         const ringPadding = Math.max(0, outlineWidth + outlineOffset);
  6509 |         const ring = {
  6510 |           left: rect.left - ringPadding,
  6511 |           right: rect.right + ringPadding,
  6512 |           top: rect.top - ringPadding,
  6513 |           bottom: rect.bottom + ringPadding,
  6514 |         };
  6515 |         const clippedBy = [];
  6516 | 
  6517 |         for (
  6518 |           let ancestor = element.parentElement;
  6519 |           ancestor;
  6520 |           ancestor = ancestor.parentElement
  6521 |         ) {
  6522 |           const ancestorStyle = window.getComputedStyle(ancestor);
  6523 |           const clipsX = ["hidden", "clip", "auto", "scroll"].includes(
  6524 |             ancestorStyle.overflowX
  6525 |           );
  6526 |           const clipsY = ["hidden", "clip", "auto", "scroll"].includes(
  6527 |             ancestorStyle.overflowY
  6528 |           );
  6529 |           if (!clipsX && !clipsY) {
  6530 |             continue;
  6531 |           }
  6532 | 
  6533 |           const ancestorRect = ancestor.getBoundingClientRect();
  6534 |           if (
  6535 |             (clipsX &&
  6536 |               (ring.left < ancestorRect.left - 1 ||
  6537 |                 ring.right > ancestorRect.right + 1)) ||
  6538 |             (clipsY &&
  6539 |               (ring.top < ancestorRect.top - 1 ||
  6540 |                 ring.bottom > ancestorRect.bottom + 1))
  6541 |           ) {
  6542 |             clippedBy.push(
  6543 |               ancestor.id || ancestor.className || ancestor.tagName
  6544 |             );
  6545 |           }
  6546 |         }
  6547 | 
  6548 |         return {
  6549 |           label: (
  6550 |             element.textContent ||
  6551 |             element.getAttribute("aria-label") ||
  6552 |             element.id ||
  6553 |             element.tagName
  6554 |           ).trim(),
  6555 |           focusVisible: element.matches(":focus-visible"),
  6556 |           outlineStyle: style.outlineStyle,
  6557 |           outlineWidth,
  6558 |           ring,
  6559 |           viewport: {
  6560 |             left: 0,
  6561 |             right: window.innerWidth,
  6562 |             top: 0,
  6563 |             bottom: window.innerHeight,
  6564 |           },
  6565 |           clippedBy,
  6566 |         };
  6567 |       })
  6568 |     );
  6569 |   }
  6570 | 
  6571 |   const invalid = reports.filter(
  6572 |     ({ focusVisible, outlineStyle, outlineWidth, ring, viewport, clippedBy }) =>
  6573 |       !focusVisible ||
  6574 |       outlineStyle === "none" ||
  6575 |       outlineWidth <= 0 ||
  6576 |       ring.left < viewport.left - 1 ||
  6577 |       ring.right > viewport.right + 1 ||
  6578 |       ring.top < viewport.top - 1 ||
  6579 |       ring.bottom > viewport.bottom + 1 ||
  6580 |       clippedBy.length > 0
  6581 |   );
  6582 | 
  6583 |   expect(
  6584 |     invalid,
  6585 |     `focused checkout controls have clipped or missing focus indicators: ${JSON.stringify(
  6586 |       invalid
  6587 |     )}`
  6588 |   ).toEqual([]);
  6589 | }
  6590 | 
  6591 | async function settleSmoothScroll(page) {
> 6592 |   await page.evaluate(
       |              ^ Error: page.evaluate: Execution context was destroyed, most likely because of a navigation.
  6593 |     () =>
  6594 |       new Promise((resolve) => {
  6595 |         let previous = window.scrollY;
  6596 |         let stableFrames = 0;
  6597 | 
  6598 |         const tick = () => {
  6599 |           if (window.scrollY === previous) {
  6600 |             stableFrames += 1;
  6601 |           } else {
  6602 |             stableFrames = 0;
  6603 |             previous = window.scrollY;
  6604 |           }
  6605 | 
  6606 |           if (stableFrames >= 5) {
  6607 |             resolve();
  6608 |             return;
  6609 |           }
  6610 | 
  6611 |           requestAnimationFrame(tick);
  6612 |         };
  6613 | 
  6614 |         requestAnimationFrame(tick);
  6615 |       })
  6616 |   );
  6617 | }
  6618 | 
  6619 | async function startBrowserServer() {
  6620 |   serverProcess = spawn(
  6621 |     "php",
  6622 |     [
  6623 |       "-S",
  6624 |       `127.0.0.1:${new URL(baseUrl).port}`,
  6625 |       "-t",
  6626 |       path.join(appDir, "public"),
  6627 |       path.join(__dirname, "laravel-browser-router.php"),
  6628 |     ],
  6629 |     {
  6630 |       cwd: appDir,
  6631 |       env: appEnv(),
  6632 |       stdio: ["ignore", "ignore", "ignore"],
  6633 |     }
  6634 |   );
  6635 | 
  6636 |   await waitForServer(`${baseUrl}/admin/login`);
  6637 | }
  6638 | 
  6639 | async function stopBrowserServer() {
  6640 |   const processToStop = serverProcess;
  6641 |   serverProcess = null;
  6642 | 
  6643 |   if (!processToStop || processToStop.exitCode !== null) {
  6644 |     return;
  6645 |   }
  6646 | 
  6647 |   await new Promise((resolve) => {
  6648 |     processToStop.once("exit", resolve);
  6649 |     processToStop.kill();
  6650 |   });
  6651 | }
  6652 | 
  6653 | function resetResponsiveFixtures() {
  6654 |   runArtisan(["migrate:fresh", "--force"]);
  6655 |   runArtisan(["credentials:backfill-identity-registry"]);
  6656 |   invoiceFixtures = JSON.parse(
  6657 |     runArtisan(["tinker", "--execute", responsiveSeedScript])
  6658 |   );
  6659 | }
  6660 | 
  6661 | function runArtisan(args) {
  6662 |   const result = spawnSync("php", ["artisan", ...args], {
  6663 |     cwd: appDir,
  6664 |     env: appEnv(),
  6665 |     encoding: "utf8",
  6666 |   });
  6667 | 
  6668 |   if (result.status !== 0) {
  6669 |     throw new Error(
  6670 |       `php artisan ${args.join(" ")} failed\n${result.stdout}\n${result.stderr}`
  6671 |     );
  6672 |   }
  6673 | 
  6674 |   return result.stdout.trim();
  6675 | }
  6676 | 
  6677 | function appEnv() {
  6678 |   return {
  6679 |     ...process.env,
  6680 |     APP_ENV: "testing",
  6681 |     APP_DEBUG: "true",
  6682 |     APP_URL: baseUrl ?? "http://127.0.0.1",
  6683 |     DB_CONNECTION: "sqlite",
  6684 |     DB_DATABASE: dbPath,
  6685 |     CREDENTIAL_IDENTITY_FINGERPRINT_KEY: "browser-only-fingerprint-key",
  6686 |     SESSION_DRIVER: "file",
  6687 |     CACHE_STORE: "array",
  6688 |     QUEUE_CONNECTION: "database",
  6689 |     MAIL_MAILER: "log",
  6690 |     // prettier-ignore
  6691 |     DOGFOOD_FAKE_TELEGRAM_OUTBOUND: 'true',
  6692 |     TELEGRAM_ADMIN_CHAT_ID: "998877",
```