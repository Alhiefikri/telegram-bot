import { chromium } from 'playwright';
import { writeFileSync } from 'fs';

const VIEWPORT = { width: 1440, height: 900 };
const CHROMIUM_PATH = '/home/alhiefikri/.npm/_npx/e41f203b7505f1fb/node_modules/playwright-core/.local-browsers/chromium-1228/chrome-linux64/chrome';

async function main() {
  const browser = await chromium.launch({ headless: true, executablePath: CHROMIUM_PATH });

  // -- WARUNGAI SITE --
  const wPage = await browser.newPage({ viewport: VIEWPORT });
  console.log('Navigating to warungai...');
  await wPage.goto('https://warungai.web.id/', { waitUntil: 'networkidle', timeout: 45000 });
  console.log('Page loaded, title:', await wPage.title());

  // Scroll to where products should be
  await wPage.evaluate(() => {
    // Try to find product section by looking for common patterns
    const sections = document.querySelectorAll('section, div[id], div[class*="product"]');
    for (const s of sections) {
      if (s.textContent.toLowerCase().includes('katalog') || s.textContent.toLowerCase().includes('produk')) {
        s.scrollIntoView({ behavior: 'instant', block: 'start' });
        break;
      }
    }
  });
  await wPage.waitForTimeout(1000);

  // Full page
  await wPage.screenshot({ path: '/tmp/warungai-full.png', fullPage: true });
  console.log('Full page screenshot saved');

  // Viewport
  await wPage.screenshot({ path: '/tmp/warungai-viewport.png', fullPage: false });
  console.log('Viewport screenshot saved');

  // Get full HTML
  const html = await wPage.content();
  writeFileSync('/tmp/warungai-full.html', html);
  console.log('HTML saved, length:', html.length);

  // Get computed styles of product cards
  const styleInfo = await wPage.evaluate(() => {
    const cards = document.querySelectorAll('a:has(img), a[href*="/product/"]');
    const info = [];
    cards.forEach((card, i) => {
      const rect = card.getBoundingClientRect();
      const styles = window.getComputedStyle(card);
      info.push({
        index: i,
        tag: card.tagName,
        href: card.getAttribute('href'),
        rect: { top: rect.top, left: rect.left, width: rect.width, height: rect.height },
        borderRadius: styles.borderRadius,
        bgColor: styles.backgroundColor,
        boxShadow: styles.boxShadow,
        text: card.textContent.trim().substring(0, 100),
      });
    });
    return info;
  });
  writeFileSync('/tmp/warungai-styles.json', JSON.stringify(styleInfo, null, 2));
  console.log('Style info saved, found', styleInfo.length, 'product card links');

  // -- LOCAL SITE --
  const lPage = await browser.newPage({ viewport: VIEWPORT });
  console.log('\nNavigating to local...');
  await lPage.goto('http://localhost:8181/', { waitUntil: 'networkidle', timeout: 30000 });
  console.log('Local site title:', await lPage.title());

  await lPage.screenshot({ path: '/tmp/local-full-2.png', fullPage: true });
  console.log('Local full page saved');

  await lPage.screenshot({ path: '/tmp/local-viewport.png', fullPage: false });
  console.log('Local viewport saved');

  const localHTML = await lPage.content();
  writeFileSync('/tmp/local-full.html', localHTML);
  console.log('Local HTML saved, length:', localHTML.length);

  await browser.close();
  console.log('\nAll done!');
}

main().catch(e => { console.error(e); process.exit(1); });
