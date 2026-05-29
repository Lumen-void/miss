#!/usr/bin/env node

const fs = require('fs');
const http = require('http');
const { execFile, execFileSync, spawn } = require('child_process');

function argValue(name) {
  const index = process.argv.indexOf(name);
  return index === -1 ? '' : process.argv[index + 1] || '';
}

function loadPlaywright() {
  try {
    return require('playwright-core');
  } catch (error) {
    try {
      return require('playwright');
    } catch {
      throw error;
    }
  }
}

function chromePath() {
  const candidates = [
    process.env.MIS_CHROME_BIN,
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
  ].filter(Boolean);
  return candidates.find((candidate) => fs.existsSync(candidate)) || undefined;
}

function openVisibleChromeWindow(profileDir, port, url) {
  const executable = chromePath();
  const browserArgs = [
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${profileDir}`,
    '--no-first-run',
    '--no-default-browser-check',
    '--new-window',
    '--window-position=60,80',
    '--window-size=1440,920',
    url,
  ];
  if (executable) {
    const child = spawn(executable, browserArgs, { detached: true, stdio: 'ignore' });
    child.unref();
    return;
  }
  execFile('open', ['-na', 'Google Chrome', '--args', ...browserArgs], () => {});
}

function waitForCdp(port, timeoutMs = 20000) {
  const startedAt = Date.now();
  return new Promise((resolve, reject) => {
    const tick = () => {
      const req = http.get(`http://127.0.0.1:${port}/json/version`, (res) => {
        res.resume();
        if (res.statusCode && res.statusCode >= 200 && res.statusCode < 300) {
          resolve();
          return;
        }
        retry();
      });
      req.on('error', retry);
      req.setTimeout(1000, () => {
        req.destroy();
        retry();
      });
    };
    const retry = () => {
      if (Date.now() - startedAt > timeoutMs) {
        reject(new Error('Timed out waiting for Chrome debug port.'));
        return;
      }
      setTimeout(tick, 500);
    };
    tick();
  });
}

function cdpReady(port, timeoutMs = 800) {
  return new Promise((resolve) => {
    const req = http.get(`http://127.0.0.1:${port}/json/version`, (res) => {
      res.resume();
      resolve(Boolean(res.statusCode && res.statusCode >= 200 && res.statusCode < 300));
    });
    req.on('error', () => resolve(false));
    req.setTimeout(timeoutMs, () => {
      req.destroy();
      resolve(false);
    });
  });
}

function existingDebugPortForProfile(profileDir) {
  let resolved = profileDir;
  try {
    resolved = fs.realpathSync(profileDir);
  } catch {}
  let output = '';
  try {
    output = execFileSync('ps', ['axo', 'command'], { encoding: 'utf8', maxBuffer: 1024 * 1024 * 4 });
  } catch {
    return 0;
  }
  const escaped = resolved.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const profilePattern = new RegExp(`--user-data-dir=(?:"${escaped}"|'${escaped}'|${escaped})(?:\\s|$)`);
  for (const line of output.split('\n')) {
    if (!line.includes('--remote-debugging-port=') || !profilePattern.test(line)) continue;
    const match = line.match(/--remote-debugging-port=(\d+)/);
    if (match) return Number(match[1]);
  }
  return 0;
}

function samePortal(urlA, urlB) {
  try {
    const a = new URL(urlA);
    const b = new URL(urlB);
    return a.hostname === b.hostname || a.hostname.endsWith(`.${b.hostname}`) || b.hostname.endsWith(`.${a.hostname}`);
  } catch {
    return false;
  }
}

async function openPortalTab(context, url) {
  const pages = context.pages();
  const existing = pages.find((page) => samePortal(page.url(), url))
    || pages.find((page) => page.url() === 'about:blank')
    || null;
  const page = existing || await context.newPage();
  await page.bringToFront().catch(() => {});
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 12000 }).catch(() => {});
  await page.bringToFront().catch(() => {});
  return page;
}

async function main() {
  const overallTimer = setTimeout(() => process.exit(0), 20000);
  const url = argValue('--url');
  const profileDir = argValue('--profile');
  let port = Number(argValue('--port') || '9333');
  if (!url || !profileDir) {
    throw new Error('Missing --url or --profile.');
  }
  fs.mkdirSync(profileDir, { recursive: true });
  const existingPort = existingDebugPortForProfile(profileDir);
  if (existingPort) {
    port = existingPort;
  }
  if (!(await cdpReady(port))) {
    openVisibleChromeWindow(profileDir, port, url);
    await waitForCdp(port, 12000);
  }
  const { chromium } = loadPlaywright();
  const browser = await chromium.connectOverCDP(`http://127.0.0.1:${port}`);
  const context = browser.contexts()[0] || await browser.newContext();
  await openPortalTab(context, url);
  browser.disconnect?.();
  clearTimeout(overallTimer);
  process.exit(0);
}

main().catch((error) => {
  console.error(error.message);
  process.exit(1);
});
