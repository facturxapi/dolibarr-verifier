// Copyright (C) 2026 FacturX API - GPL-3.0-or-later
// Takes the README screenshots on a local Dolibarr wired to the FacturX API test double.
// Usage: DOLI_URL=http://127.0.0.1:8080 BAD_ID=1 GOOD_ID=2 LANG=fr_FR node dev/screenshots.js docs/screenshots
const { chromium } = require('playwright');
const out = process.argv[2] || 'docs/screenshots';
const url = process.env.DOLI_URL || 'http://127.0.0.1:8080';
const bad = process.env.BAD_ID || '1';
const good = process.env.GOOD_ID || '2';
const suffix = (process.env.SHOT_LANG || 'fr');
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 860 }, deviceScaleFactor: 2 });
  await page.goto(url + '/index.php');
  await page.fill('#username', 'admin');
  await page.fill('#password', process.env.ADMIN_PASS || 'admin1234');
  await Promise.all([page.waitForNavigation(), page.click('input[type=submit]')]);
  if (process.env.USER_LANG) {
    // nothing: language is the instance default
  }
  const shot = async (name, sel) => {
    const el = sel ? page.locator(sel).first() : page;
    await el.screenshot({ path: `${out}/${name}-${suffix}.png`, ...(sel ? {} : { fullPage: false }) });
    console.log('saved', `${out}/${name}-${suffix}.png`);
  };
  await page.goto(url + '/custom/facturxverify/admin/setup.php');
  await shot('1-setup', '#id-right');
  await page.goto(url + '/compta/facture/card.php?facid=' + good);
  await page.locator('#facturxverify-card-check').scrollIntoViewIfNeeded();
  await shot('2-invoice-card-button', '.tabsAction');
  await Promise.all([page.waitForNavigation(), page.click('#facturxverify-card-check')]);
  await shot('3-check-ready', '#id-right');
  await page.goto(url + '/custom/facturxverify/tab.php?id=' + bad);
  await Promise.all([page.waitForNavigation(), page.click('#facturxverify-check')]);
  await shot('4-check-blocking', '#id-right');
  await Promise.all([page.waitForNavigation(), page.click('#facturxverify-repair')]);
  await shot('5-repair-preview', '#id-right');
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
