import { chromium } from 'playwright';
import { writeFileSync } from 'fs';

const VIEWPORT = { width: 1440, height: 900 };
const CHROMIUM_PATH = '/home/alhiefikri/.npm/_npx/e41f203b7505f1fb/node_modules/playwright-core/.local-browsers/chromium-1228/chrome-linux64/chrome';

async function main() {
  const browser = await chromium.launch({ headless: true, executablePath: CHROMIUM_PATH });

  // Extract CSS from local site
  const lPage = await browser.newPage({ viewport: VIEWPORT });
  await lPage.goto('http://localhost:8181/', { waitUntil: 'networkidle', timeout: 30000 });

  // Get all computed styles for product cards
  const localStyles = await lPage.evaluate(() => {
    const cards = document.querySelectorAll('article');
    const info = [];
    cards.forEach((card, i) => {
      const s = window.getComputedStyle(card);
      const img = card.querySelector('img');
      const title = card.querySelector('h4')?.textContent?.trim();
      const link = card.querySelector('a');
      info.push({
        title,
        card: {
          borderRadius: s.borderRadius,
          bgColor: s.backgroundColor,
          boxShadow: s.boxShadow,
          border: s.border,
          padding: s.padding,
        },
        paddingInner: card.querySelector('div:last-child') ? window.getComputedStyle(card.querySelector('div:last-child')).padding : null,
        price: card.querySelector('p:last-of-type')?.textContent,
        linkText: link?.textContent?.trim(),
        linkStyles: link ? {
          bgColor: window.getComputedStyle(link).backgroundColor,
          borderRadius: window.getComputedStyle(link).borderRadius,
          padding: window.getComputedStyle(link).padding,
          fontSize: window.getComputedStyle(link).fontSize,
          fontWeight: window.getComputedStyle(link).fontWeight,
          color: window.getComputedStyle(link).color,
        } : null,
      });
    });

    // Get section styles
    const productsSection = document.querySelector('#products');
    const sectionStyles = productsSection ? {
      bgColor: window.getComputedStyle(productsSection).backgroundColor,
      padding: window.getComputedStyle(productsSection).padding,
    } : null;

    // Get font info
    const bodyStyles = window.getComputedStyle(document.body);

    return {
      sectionStyles,
      body: {
        fontFamily: bodyStyles.fontFamily,
        bgColor: bodyStyles.backgroundColor,
        color: bodyStyles.color,
      },
      cards: info,
    };
  });

  writeFileSync('/tmp/local-styles.json', JSON.stringify(localStyles, null, 2));
  console.log('Local styles extracted:', localStyles.cards.length, 'cards');
  console.log('Sample card:', JSON.stringify(localStyles.cards[0], null, 2));

  await browser.close();
}

main().catch(e => { console.error(e); process.exit(1); });
