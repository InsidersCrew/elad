#!/usr/bin/env node
/**
 * Visual smoke test: builds, opens dist/index.html in headless Chromium and
 * saves screenshots of the main views to out/. Requires playwright
 * (globally installed is fine: NODE_PATH=$(npm root -g)).
 */
import { mkdirSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const out = path.join(root, 'out');
mkdirSync(out, { recursive: true });
execSync('node scripts/build.mjs', { cwd: root, stdio: 'inherit' });

const require = createRequire(import.meta.url);
let chromium;
try {
  ({ chromium } = require('playwright'));
} catch {
  ({ chromium } = require(path.join(execSync('npm root -g').toString().trim(), 'playwright')));
}
const exe = process.env.CHROMIUM_PATH ?? (existsSync('/opt/pw-browsers/chromium') ? undefined : undefined);
const browser = await chromium.launch({
  executablePath: exe,
  args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'],
});
const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
await page.goto('file://' + path.join(root, 'dist/index.html'));
await page.waitForFunction(() => window.__cityos?.ready === true, null, { timeout: 30000 });
await page.waitForTimeout(2500);
await page.screenshot({ path: path.join(out, 'city-overview.png') });

const shots = [
  ['lens-bottleneck', () => window.__cityos.setLens('bottleneck')],
  ['lens-profit', () => window.__cityos.setLens('profit')],
  ['detail-fitcall', () => { window.__cityos.setLens('health'); window.__cityos.select('fit_conversion'); }],
  ['scenario', () => { window.__cityos.openScenario(); window.__cityos.setScenario({ fit_conv: 0.36, call_duration_min: 13 }); }],
  ['reports', () => window.__cityos.openReports('daily')],
];
for (const [name, fn] of shots) {
  await page.evaluate(fn);
  await page.waitForTimeout(1200);
  await page.screenshot({ path: path.join(out, `${name}.png`) });
}
await page.evaluate(() => { window.__cityos.closeAll(); window.__cityos.resetScenario(); window.__cityos.resetView(); });
await page.setViewportSize({ width: 390, height: 844 });
await page.waitForTimeout(1500);
await page.screenshot({ path: path.join(out, 'mobile.png') });
await page.evaluate(() => { window.__cityos.select('fit_conversion'); });
await page.waitForTimeout(1200);
await page.screenshot({ path: path.join(out, 'mobile-detail.png') });
await browser.close();
if (errors.length) {
  console.error('page errors:\n' + errors.join('\n'));
  process.exit(1);
}
console.log('screenshots written to out/');
