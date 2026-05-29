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

function looksLikeLogin(url, title) {
  return /login|signin|sign-in|auth|oauth|sso|ap\/signin|password|otp|verification/i.test(`${url} ${title}`);
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

function pageScore(page, url) {
  const current = page.url();
  if (samePortal(current, url)) return 3;
  if (current === 'about:blank') return 1;
  return 0;
}

async function portalPage(context, url) {
  const pages = context.pages();
  const existing = pages
    .map((page) => ({ page, score: pageScore(page, url) }))
    .filter((entry) => entry.score > 0)
    .sort((a, b) => b.score - a.score)[0]?.page;
  if (existing) {
    if (existing.url() === 'about:blank') {
      await existing.goto(url, { waitUntil: 'domcontentloaded', timeout: 8000 }).catch(() => {});
    }
    await existing.bringToFront().catch(() => {});
    return { page: existing, created: false };
  }
  const page = await context.newPage();
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 8000 }).catch(() => {});
  await page.bringToFront().catch(() => {});
  return { page, created: true };
}

async function pageLoginState(page) {
  return page.evaluate(() => {
    const visible = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);
      return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && style.display !== 'none';
    };
    const fields = Array.from(document.querySelectorAll('input, button, a')).filter(visible);
    const passwordFields = fields.filter((element) => element.matches('input[type="password"]')).length;
    const otpFields = fields.filter((element) => {
      const text = `${element.getAttribute('name') || ''} ${element.getAttribute('placeholder') || ''} ${element.getAttribute('aria-label') || ''}`.toLowerCase();
      return /otp|verification code|one time|2fa|mfa/.test(text);
    }).length;
    const loginActions = fields.filter((element) => {
      const text = `${element.textContent || ''} ${element.getAttribute('value') || ''} ${element.getAttribute('aria-label') || ''}`.toLowerCase();
      return /log in|login|sign in|signin|continue|verify/.test(text);
    }).length;
    const appSignals = Array.from(document.querySelectorAll('nav, aside, [role="navigation"], [class*="dashboard" i], [href*="logout" i], [href*="signout" i]')).filter(visible).length;
    const bodyText = (document.body?.innerText || '').slice(0, 5000).toLowerCase();
    const bodyLoginText = /enter password|forgot password|sign in to|login to|verification code|enter otp|one time password/.test(bodyText);
    const blockedText = /access denied|session expired|please login|please log in|unauthorized|not authorized/.test(bodyText);
    return {
      passwordFields,
      otpFields,
      loginActions,
      appSignals,
      bodyLoginText,
      blockedText,
      readyState: document.readyState,
      bodyLength: bodyText.length,
    };
  }).catch(() => ({
    passwordFields: 0,
    otpFields: 0,
    loginActions: 0,
    appSignals: 0,
    bodyLoginText: false,
    blockedText: false,
    readyState: 'unknown',
    bodyLength: 0,
  }));
}

async function main() {
  const overallTimer = setTimeout(() => {
    console.log(JSON.stringify({
      connected: false,
      message: 'Connection check timed out. Keep the portal open, finish login, then verify again.',
    }));
    process.exit(0);
  }, 15000);

  const url = argValue('--url');
  const profileDir = argValue('--profile');
  let port = Number(argValue('--port') || '9333');
  const existingPort = existingDebugPortForProfile(profileDir);
  if (existingPort) {
    port = existingPort;
  }
  const openIfMissing = process.argv.includes('--open-if-missing');
  if (!url || !profileDir) {
    throw new Error('Missing --url or --profile.');
  }
  fs.mkdirSync(profileDir, { recursive: true });

  const { chromium } = loadPlaywright();
  if (!(await cdpReady(port))) {
    if (!openIfMissing) {
      clearTimeout(overallTimer);
      console.log(JSON.stringify({
        connected: false,
        message: 'App browser is not open. Use Connect first, then complete login and click Check again.',
      }));
      return;
    }
    openVisibleChromeWindow(profileDir, port, url);
    await waitForCdp(port, 8000);
  }
  const browser = await chromium.connectOverCDP(`http://127.0.0.1:${port}`);
  const context = browser.contexts()[0] || await browser.newContext();
  const { page, created } = await portalPage(context, url);
  if (!samePortal(page.url(), url)) {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 8000 }).catch(() => {});
  }
  await page.waitForLoadState('networkidle', { timeout: 2000 }).catch(() => {});
  await page.bringToFront().catch(() => {});

  const currentUrl = page.url();
  const title = await page.title().catch(() => '');
  const loginState = await pageLoginState(page);
  const urlLooksLikeLogin = looksLikeLogin(currentUrl, title);
  const hasBlockingLoginForm = loginState.passwordFields > 0
    || loginState.otpFields > 0
    || loginState.bodyLoginText
    || loginState.blockedText
    || (urlLooksLikeLogin && loginState.loginActions > 0 && loginState.appSignals === 0);
  const connected = !hasBlockingLoginForm && (loginState.appSignals > 0 || loginState.bodyLength > 120);
  if (created && !connected && page.url() === 'about:blank') {
    await page.close().catch(() => {});
  }
  clearTimeout(overallTimer);
  browser.disconnect?.();
  console.log(JSON.stringify({
    connected,
    url: currentUrl,
    title,
    message: connected ? 'Session is connected to the app browser profile.' : 'Portal is still showing login or verification in the app browser profile.',
    state: loginState,
  }));
  process.exit(0);
}

main().catch((error) => {
  console.log(JSON.stringify({
    connected: false,
    message: error.message,
  }));
  process.exitCode = 1;
});
