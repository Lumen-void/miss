#!/usr/bin/env node

const fs = require('fs');
const http = require('http');
const path = require('path');
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

function writeJsonLine(file, payload) {
  fs.appendFileSync(file, `${JSON.stringify({
    level: payload.level || 'info',
    sourceType: payload.sourceType || '',
    message: payload.message,
    context: payload.context || {},
    createdAt: new Date().toISOString(),
  })}\n`);
}

function activateChrome() {
  execFile('open', ['-a', 'Google Chrome'], () => {});
}

function openVisibleChromeWindow(profileDir, url = 'about:blank') {
  const port = process.env.MIS_CHROME_DEBUG_PORT || '9333';
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
        reject(new Error('Timed out waiting for visible Chrome debug port.'));
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

function downloadFiles(downloadDir) {
  if (!fs.existsSync(downloadDir)) return [];
  return fs.readdirSync(downloadDir)
    .filter((name) => !name.endsWith('.crdownload') && !name.endsWith('.tmp'))
    .filter((name) => /\.(csv|xlsx|xls|pdf|docx|doc|zip|txt|html|htm)$/i.test(name))
    .map((name) => path.join(downloadDir, name))
    .filter((file) => fs.statSync(file).isFile());
}

async function waitForNewDownloadFile(downloadDir, before, timeoutMs) {
  const startedAt = Date.now();
  const beforeSet = new Set(before);
  while (Date.now() - startedAt < timeoutMs) {
    const current = downloadFiles(downloadDir).filter((file) => !beforeSet.has(file));
    if (current.length) {
      await new Promise((resolve) => setTimeout(resolve, 700));
      return current[0];
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  return null;
}

async function makePageVisible(page, eventsPath, sourceType = '') {
  try {
    await page.bringToFront();
  } catch {}
  try {
    await page.evaluate(() => {
      window.focus();
      window.moveTo(60, 80);
      window.resizeTo(1440, 920);
    });
  } catch {}
  activateChrome();
  writeJsonLine(eventsPath, {
    sourceType,
    message: 'Brought browser window to the front.',
    context: {},
  });
}

function sanitizeFileName(name) {
  return name.replace(/[^a-zA-Z0-9._-]+/g, '_').replace(/^_+|_+$/g, '') || 'report';
}

function looksLikeLogin(url, title) {
  return /login|signin|auth|account|oauth|sso/i.test(`${url} ${title}`);
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
    return {
      passwordFields,
      otpFields,
      loginActions,
      appSignals,
      bodyLoginText,
      bodyLength: bodyText.length,
    };
  }).catch(() => ({
    passwordFields: 0,
    otpFields: 0,
    loginActions: 0,
    appSignals: 0,
    bodyLoginText: false,
    bodyLength: 0,
  }));
}

function hasBlockingLogin(pageState, url, title) {
  return pageState.passwordFields > 0
    || pageState.otpFields > 0
    || pageState.bodyLoginText
    || (looksLikeLogin(url, title) && pageState.loginActions > 0 && pageState.appSignals === 0);
}

async function waitForLoginCompletion(page, timeoutMs) {
  const startedAt = Date.now();
  while (Date.now() - startedAt < timeoutMs) {
    await page.waitForTimeout(2000);
    const title = await page.title().catch(() => '');
    const state = await pageLoginState(page);
    if (!hasBlockingLogin(state, page.url(), title)) {
      await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
      return true;
    }
  }
  return false;
}

async function saveDownload(download, sourceType, downloadDir, eventsPath) {
  const suggested = sanitizeFileName(download.suggestedFilename() || `${sourceType}-report.xlsx`);
  const destination = path.join(downloadDir, `${sourceType}_${Date.now()}_${suggested}`);
  await download.saveAs(destination);
  writeJsonLine(eventsPath, {
    level: 'success',
    sourceType,
    message: 'Captured report file for in-app import.',
    context: { file: path.basename(destination) },
  });
  return destination;
}

async function captureDownloadAfterClick(page, locator, sourceType, downloadDir, eventsPath, timeoutMs = 20000) {
  const before = downloadFiles(downloadDir);
  const downloadPromise = page.waitForEvent('download', { timeout: timeoutMs }).catch(() => null);
  await locator.click({ timeout: 5000, force: true });
  const download = await downloadPromise;
  if (download) {
    return saveDownload(download, sourceType, downloadDir, eventsPath);
  }
  const file = await waitForNewDownloadFile(downloadDir, before, timeoutMs);
  if (file) {
    writeJsonLine(eventsPath, {
      level: 'success',
      sourceType,
      message: 'Captured report file for in-app import.',
      context: { file: path.basename(file) },
    });
  }
  return file;
}

async function waitForManualDownload(page, sourceType, downloadDir, eventsPath, timeoutMs) {
  const before = downloadFiles(downloadDir);
  return new Promise((resolve) => {
    const startedAt = Date.now();
    const poll = setInterval(async () => {
      const file = await waitForNewDownloadFile(downloadDir, before, 500);
      if (file) {
        clearTimeout(timer);
        clearInterval(poll);
        writeJsonLine(eventsPath, {
          level: 'success',
          sourceType,
          message: 'Captured report file from the portal window.',
          context: { file: path.basename(file) },
        });
        resolve(file);
      }
      if (Date.now() - startedAt > timeoutMs) {
        clearInterval(poll);
      }
    }, 1000);
    const timer = setTimeout(() => {
      page.off('download', onDownload);
      clearInterval(poll);
      resolve(null);
    }, timeoutMs);
    const onDownload = async (download) => {
      clearTimeout(timer);
      page.off('download', onDownload);
      try {
        resolve(await saveDownload(download, sourceType, downloadDir, eventsPath));
      } catch (error) {
        writeJsonLine(eventsPath, {
          level: 'error',
          sourceType,
          message: 'Manual download was detected but could not be saved.',
          context: { error: error.message },
        });
        resolve(null);
      }
    };
    page.on('download', onDownload);
  });
}

async function tryClickDownload(page, sourceType, downloadDir, eventsPath) {
  const files = [];
  const seen = new Set();
  const names = [
    /download/i,
    /export/i,
    /excel/i,
    /xlsx/i,
    /xls/i,
    /csv/i,
    /pdf/i,
    /docx/i,
    /doc/i,
    /zip/i,
    /txt/i,
    /html/i,
    /report/i,
    /generate/i,
  ];
  const candidates = [];
  for (const name of names) {
    candidates.push(page.getByRole('button', { name }).first());
    candidates.push(page.getByRole('link', { name }).first());
  }

  for (const locator of candidates) {
    try {
      if ((await locator.count()) === 0 || !(await locator.isVisible({ timeout: 800 }).catch(() => false))) {
        continue;
      }
      const file = await captureDownloadAfterClick(page, locator, sourceType, downloadDir, eventsPath);
      if (file && !seen.has(file)) {
        seen.add(file);
        files.push(file);
        return files;
      }
    } catch {
      // Keep trying less-specific export controls because portal UIs change often.
    }
  }

  const iconFiles = await clickIconDownloadButtons(page, sourceType, downloadDir, eventsPath, seen);
  files.push(...iconFiles);
  return files;
}

async function clickIconDownloadButtons(page, sourceType, downloadDir, eventsPath, seen) {
  const files = [];
  const handles = await page.evaluateHandle(() => {
    const selectors = [
      '[aria-label*="download" i]',
      '[title*="download" i]',
      '[data-testid*="download" i]',
      '[data-test*="download" i]',
      '[class*="download" i]',
      'button:has(svg)',
      'a:has(svg)',
      '[role="button"]:has(svg)',
      'button:has(i)',
      'a:has(i)',
      '[role="button"]:has(i)',
    ];
    const elements = [];
    const seenElements = new Set();
    for (const selector of selectors) {
      document.querySelectorAll(selector).forEach((element) => {
        if (seenElements.has(element)) return;
        const rect = element.getBoundingClientRect();
        if (rect.width < 10 || rect.height < 10) return;
        const style = window.getComputedStyle(element);
        if (style.visibility === 'hidden' || style.display === 'none' || Number(style.opacity) === 0) return;
        const text = (element.innerText || element.textContent || '').toLowerCase();
        const attrs = ['aria-label', 'title', 'data-testid', 'data-test', 'class', 'href']
          .map((name) => element.getAttribute(name) || '')
          .join(' ')
          .toLowerCase();
        const svgText = Array.from(element.querySelectorAll('svg title, svg desc, i, span'))
          .map((node) => node.textContent || node.getAttribute?.('class') || '')
          .join(' ')
          .toLowerCase();
        const isExplicit = /download|export|xlsx|xls|excel|csv|pdf|docx|doc|zip|txt|html|report/.test(`${text} ${attrs} ${svgText}`);
        const isIconOnly = text.trim().length <= 3 && (element.querySelector('svg') || element.querySelector('i'));
        const isRightSide = rect.left > window.innerWidth * 0.55;
        if (isExplicit || (isIconOnly && isRightSide)) {
          seenElements.add(element);
          elements.push(element);
        }
      });
    }
    return elements.slice(0, 40);
  });
  const properties = await handles.getProperties();
  const locators = [];
  for (const property of properties.values()) {
    locators.push(property.asElement());
  }

  writeJsonLine(eventsPath, {
    sourceType,
    message: `Found ${locators.length} icon-style download candidate(s).`,
    context: {},
  });

  for (const element of locators) {
    if (!element) continue;
    try {
      const file = await captureDownloadAfterClick(page, element, sourceType, downloadDir, eventsPath, 15000);
      if (file && !seen.has(file)) {
        seen.add(file);
        files.push(file);
        return files;
      }
      await page.waitForTimeout(900);
    } catch {
      // Many icon candidates are menus or non-download controls; keep scanning.
    }
  }
  return files;
}

async function runSource(context, source, config, manifest) {
  const page = await context.newPage();
  try {
    const session = await context.newCDPSession(page);
    await session.send('Browser.setDownloadBehavior', {
      behavior: 'allow',
      downloadPath: config.downloadDir,
      eventsEnabled: true,
    });
  } catch {}
  const { sourceType, label, url } = source;
  await makePageVisible(page, config.eventsPath, sourceType);
  writeJsonLine(config.eventsPath, {
    sourceType,
    message: `Opening ${label}.`,
    context: { url },
  });

  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 });
    await makePageVisible(page, config.eventsPath, sourceType);
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    const title = await page.title().catch(() => '');
    const loginState = await pageLoginState(page);
    if (hasBlockingLogin(loginState, page.url(), title)) {
      writeJsonLine(config.eventsPath, {
        level: 'warning',
        sourceType,
        message: 'Login or OTP is required. Complete it in the opened browser window.',
        context: { currentUrl: page.url(), title, state: loginState },
      });
      const loggedIn = await waitForLoginCompletion(page, config.manualWaitMs);
      if (!loggedIn) {
        writeJsonLine(config.eventsPath, {
          level: 'warning',
          sourceType,
          message: 'Login wait timed out. Continuing in case the report page is already available.',
          context: {},
        });
      }
    }

    const clickedFiles = await tryClickDownload(page, sourceType, config.downloadDir, config.eventsPath);
    if (clickedFiles.length) {
      clickedFiles.forEach((file) => {
        manifest.files.push({ sourceType, path: file, originalName: path.basename(file), mode: 'downloaded' });
      });
      return;
    }

    writeJsonLine(config.eventsPath, {
      level: 'warning',
      sourceType,
      message: 'No reliable export button was found. Use the portal export button in the browser window; the app will capture and import the file.',
      context: { waitSeconds: Math.round(config.manualWaitMs / 1000) },
    });

    const manualFile = await waitForManualDownload(page, sourceType, config.downloadDir, config.eventsPath, config.manualWaitMs);
    if (manualFile) {
      manifest.files.push({ sourceType, path: manualFile, originalName: path.basename(manualFile), mode: 'manual_download' });
    } else {
      manifest.attention.push({ sourceType, status: 'manual_download_needed' });
      writeJsonLine(config.eventsPath, {
        level: 'warning',
        sourceType,
        message: 'No report was captured for this source during the wait window.',
        context: {},
      });
    }
  } catch (error) {
    manifest.attention.push({ sourceType, status: 'failed', error: error.message });
    writeJsonLine(config.eventsPath, {
      level: 'error',
      sourceType,
      message: 'Portal automation failed for this source.',
      context: { error: error.message },
    });
  } finally {
    // Keep portal tabs open while the job runs. Closing the last tab in a
    // persistent Chrome profile can close the context before the next portal.
  }
}

async function main() {
  const configPath = argValue('--config');
  if (!configPath) {
    throw new Error('Missing --config path.');
  }
  const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
  fs.mkdirSync(config.downloadDir, { recursive: true });
  fs.mkdirSync(config.profileDir, { recursive: true });
  fs.mkdirSync(path.dirname(config.eventsPath), { recursive: true });

  const manifest = {
    jobId: config.jobId,
    runId: config.runId,
    files: [],
    attention: [],
    completedAt: null,
  };

  const { chromium } = loadPlaywright();
  writeJsonLine(config.eventsPath, {
  message: 'Launching visible Chrome browser profile.',
    context: { profileDir: config.profileDir, downloadDir: config.downloadDir },
  });
  let debugPort = Number(process.env.MIS_CHROME_DEBUG_PORT || config.debugPort || 9333);
  const existingPort = existingDebugPortForProfile(config.profileDir);
  if (existingPort) {
    debugPort = existingPort;
  }
  process.env.MIS_CHROME_DEBUG_PORT = String(debugPort);
  if (!(await cdpReady(debugPort))) {
    const firstSourceUrl = (config.sources || []).find((source) => source && source.url)?.url || 'about:blank';
    openVisibleChromeWindow(config.profileDir, firstSourceUrl);
    await waitForCdp(debugPort);
  }
  const browser = await chromium.connectOverCDP(`http://127.0.0.1:${debugPort}`);
  const context = browser.contexts()[0];
  if (!context) {
    throw new Error('Visible Chrome opened, but no browser context was available.');
  }
  activateChrome();

  try {
    for (const source of config.sources || []) {
      await runSource(context, source, config, manifest);
    }
  } finally {
    manifest.completedAt = new Date().toISOString();
    fs.writeFileSync(config.manifestPath, JSON.stringify(manifest, null, 2));
    writeJsonLine(config.eventsPath, {
      level: manifest.files.length ? 'success' : 'warning',
      message: 'Browser automation finished.',
      context: { files: manifest.files.length, attention: manifest.attention.length },
    });
    await browser.disconnect?.().catch(() => {});
  }
  process.exit(0);
}

main().catch((error) => {
  const configPath = argValue('--config');
  if (configPath && fs.existsSync(configPath)) {
    const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    writeJsonLine(config.eventsPath, {
      level: 'error',
      message: 'Browser runner crashed.',
      context: { error: error.message },
    });
    fs.writeFileSync(config.manifestPath, JSON.stringify({ files: [], attention: [{ status: 'runner_crashed', error: error.message }] }, null, 2));
  }
  console.error(error);
  process.exit(1);
});
