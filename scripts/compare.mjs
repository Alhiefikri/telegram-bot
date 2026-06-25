import { chromium } from 'playwright';
import { writeFileSync } from 'fs';

const VIEWPORT = { width: 1440, height: 900 };
const CHROMIUM_PATH = '/home/alhiefikri/.npm/_npx/e41f203b7505f1fb/node_modules/playwright-core/.local-browsers/chromium-1228/chrome-linux64/chrome';

async function capture(browser, url, label) {
  const page = await browser.newPage({ viewport: VIEWPORT });
  await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });

  // Full page screenshot
  await page.screenshot({ path: `/tmp/${label}-full.png`, fullPage: true });

  // Scroll to #products section
  try {
    await page.locator('#products').scrollIntoViewIfNeeded({ timeout: 5000 });
    await page.waitForTimeout(500);
    await page.screenshot({ path: `/tmp/${label}-products.png`, fullPage: false });
  } catch {
    console.log(`${label}: no #products section found`);
  }

  // Viewport screenshot (hero area)
  await page.screenshot({ path: `/tmp/${label}-hero.png`, fullPage: false });

  // Capture the HTML + computed styles for key sections
  const productHTML = await page.evaluate(() => {
    const products = document.querySelector('#products');
    return products ? products.outerHTML.substring(0, 5000) : 'not found';
  });

  writeFileSync(`/tmp/${label}-products-html.txt`, productHTML);
  console.log(`${label}: done`);
}

const browser = await chromium.launch({ headless: true, executablePath: CHROMIUM_PATH });

await capture(browser, 'http://localhost:8181/', 'local');
await capture(browser, 'https://warungai.web.id/#products', 'warungai');

await browser.close();
console.log('All screenshots captured.');
