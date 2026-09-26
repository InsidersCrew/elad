#!/usr/bin/env node
/**
 * Exports a compact JSON snapshot of the computed city (facts, scores, root
 * causes, mayor brief) to out/city-snapshot.json — the input for the skills.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { loadData, root } from '../src/node/load.js';
import { buildCity } from '../src/engine/index.js';
import { snapshotOf } from '../src/engine/snapshot.js';

const city = buildCity(loadData());
const out = path.join(root, 'out');
mkdirSync(out, { recursive: true });
const file = path.join(out, 'city-snapshot.json');
writeFileSync(file, JSON.stringify(snapshotOf(city), null, 2));
console.log('wrote ' + path.relative(root, file));
