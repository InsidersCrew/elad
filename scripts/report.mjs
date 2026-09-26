#!/usr/bin/env node
/** Usage: npm run report -- daily|weekly|monthly */
import { loadData } from '../src/node/load.js';
import { buildCity } from '../src/engine/index.js';
import { dailyBrief, weeklyReview, monthlyStructural } from '../src/engine/reports.js';

const kind = process.argv[2] ?? 'daily';
const city = buildCity(loadData());
const gen = { daily: dailyBrief, weekly: weeklyReview, monthly: monthlyStructural }[kind];
if (!gen) {
  console.error('unknown report: ' + kind + ' (daily|weekly|monthly)');
  process.exit(1);
}
process.stdout.write(gen(city));
