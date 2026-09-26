#!/usr/bin/env node
/**
 * Prints the Daily Mayor Brief (Markdown) for the current data.
 * Usage: npm run mayor [-- --set fit_conv=0.36 --set call_duration_min=13]
 */
import { loadData } from '../src/node/load.js';
import { buildCity } from '../src/engine/index.js';
import { dailyBrief, whatIfReport } from '../src/engine/reports.js';

const args = process.argv.slice(2);
const overrides = {};
for (let i = 0; i < args.length; i++) {
  if (args[i] === '--set' && args[i + 1]) {
    const [k, v] = args[++i].split('=');
    overrides[k] = Number(v);
  }
}
const data = loadData();
const base = buildCity(data);
if (Object.keys(overrides).length) {
  const alt = buildCity(data, overrides);
  process.stdout.write(whatIfReport(base, alt, overrides));
  process.stdout.write('\n\n---\n\n');
  process.stdout.write(dailyBrief(alt));
} else {
  process.stdout.write(dailyBrief(base));
}
