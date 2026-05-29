#!/usr/bin/env node

const { chromium } = require('playwright-core');

const base = process.env.MIS_BASE_URL || 'http://127.0.0.1:8090';
const chrome = process.env.MIS_CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const routes = [
  '/',
  '/dashboard/close',
  '/dashboard/trends',
  '/auto-import',
  '/integrations',
  '/imports/activity',
  '/imports/manual',
  '/sales',
  '/sales/charts',
  '/sales/platforms',
  '/sales/records',
  '/inventory',
  '/inventory/stock',
  '/inventory/movements',
  '/inventory/setup',
  '/mis/preview',
  '/mis/charts',
  '/mis/profit-bridge',
  '/mis/platforms',
  '/mis/categories',
  '/mis/audit',
  '/reports',
  '/reports/executive',
  '/reports/pnl',
  '/reports/loss-watch',
  '/validation',
  '/adjustments',
  '/masters',
];

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: chrome });
  const desktop = await browser.newPage({ viewport: { width: 1440, height: 950 } });
  const failures = [];

  for (const route of routes) {
    const response = await desktop.goto(base + route, { waitUntil: 'networkidle', timeout: 30000 });
    const body = await desktop.locator('body').innerText().catch(() => '');
    const overflow = await desktop.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    if (!response || response.status() !== 200 || body.includes('Something went wrong') || overflow) {
      failures.push({ route, status: response && response.status(), overflow });
    }
  }

  const mobile = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true });
  await mobile.goto(base + '/mis/preview', { waitUntil: 'networkidle', timeout: 30000 });
  const mobileOverflow = await mobile.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
  if (mobileOverflow) {
    failures.push({ route: '/mis/preview mobile', status: 200, overflow: true });
  }

  await browser.close();

  if (failures.length) {
    console.error(JSON.stringify(failures, null, 2));
    process.exit(1);
  }
  console.log(`Smoke routes OK: ${routes.length} desktop routes + mobile MIS preview at ${base}`);
})();
