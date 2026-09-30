#!/usr/bin/env node
/**
 * Monthly data entry for data/model.json (the repo's source of truth).
 *
 *   npm run period -- list
 *   npm run period -- add "אוק׳ 26"                      # new month, values carried forward
 *   npm run period -- add "אוק׳ 26" fit_conv=0.31 leads_paid=1050
 *   npm run period -- set "ספט׳ 26" fit_conv=0.29 open_rate=0.53   # edit any month
 *   npm run period -- set last call_duration_min=16
 *   npm run period -- show "ספט׳ 26"
 *   npm run period -- remove-last
 *
 * Percent levers take fractions (0.31) or percents with a % sign (31%).
 */
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { root } from '../src/node/load.js';
import { LEVERS } from '../src/engine/levers.js';
import { fmtValue } from '../src/engine/format.js';
import { addPeriod, setValues, removeLastPeriod, valuesAt, normalizeModel, changedLevers, nextPeriodLabel } from '../src/engine/periods.js';

const file = path.join(root, 'data', 'model.json');
const model = normalizeModel(JSON.parse(readFileSync(file, 'utf8')));
const [cmd, ...rest] = process.argv.slice(2);

function save(m) {
  writeFileSync(file, JSON.stringify(m, null, 2) + '\n');
  console.log(`נשמר: data/model.json (${m.period.labels.length} תקופות, נוכחית: ${m.period.labels.at(-1)})`);
}

function parseAssignments(args) {
  const out = {};
  for (const a of args) {
    const [k, raw] = a.split('=');
    if (!LEVERS[k]) throw new Error(`ידית לא מוכרת: ${k}`);
    let v = raw?.trim();
    if (v === undefined) throw new Error(`חסר ערך ל-${k}`);
    if (v.endsWith('%')) v = Number(v.slice(0, -1)) / 100;
    else v = Number(v);
    if (Number.isNaN(v)) throw new Error(`ערך לא מספרי ל-${k}: ${raw}`);
    out[k] = v;
  }
  return out;
}

function periodIndex(label) {
  if (label === 'last' || label === undefined) return model.period.labels.length - 1;
  const i = model.period.labels.indexOf(label);
  if (i < 0) throw new Error(`תקופה לא קיימת: ${label}. קיימות: ${model.period.labels.join(' · ')}`);
  return i;
}

try {
  switch (cmd) {
    case 'list': {
      model.period.labels.forEach((l, i) => {
        const ch = changedLevers(model, i);
        console.log(`${i === model.period.labels.length - 1 ? '▶' : ' '} ${l}${ch.length ? `  (${ch.length} ידיות השתנו)` : ''}`);
      });
      break;
    }
    case 'show': {
      const i = periodIndex(rest[0]);
      const vals = valuesAt(model, i);
      console.log(`# ${model.period.labels[i]}`);
      for (const [k, def] of Object.entries(LEVERS)) console.log(`${k.padEnd(30)} ${fmtValue(vals[k], def.fmt).padStart(14)}   ${def.label}`);
      break;
    }
    case 'add': {
      const label = rest[0] && !rest[0].includes('=') ? rest[0] : nextPeriodLabel(model.period.labels.at(-1));
      const assigns = parseAssignments(rest.filter((a) => a.includes('=')));
      save(addPeriod(model, label, assigns));
      break;
    }
    case 'set': {
      const i = periodIndex(rest[0] && !rest[0].includes('=') ? rest[0] : 'last');
      const assigns = parseAssignments(rest.filter((a) => a.includes('=')));
      if (!Object.keys(assigns).length) throw new Error('לא הועברו ערכים (lever=value)');
      save(setValues(model, i, assigns));
      break;
    }
    case 'remove-last': {
      save(removeLastPeriod(model));
      break;
    }
    default:
      console.log(readFileSync(new URL(import.meta.url), 'utf8').split('*/')[0].replace(/^\/\*\*?\s?/, '').replace(/^ \* ?/gm, ''));
  }
} catch (e) {
  console.error('שגיאה: ' + e.message);
  process.exit(1);
}
