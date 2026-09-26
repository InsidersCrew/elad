#!/usr/bin/env node
/** Dev server with rebuild on change: http://localhost:5173 */
import * as esbuild from 'esbuild';
import { mkdirSync, readFileSync, writeFileSync, watch } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const dist = path.join(root, 'dist');
mkdirSync(dist, { recursive: true });

function writeHtml() {
  const template = readFileSync(path.join(root, 'src/index.html'), 'utf8');
  const html = template
    .replace('<!-- STYLES -->', () => '<link rel="stylesheet" href="./styles.css">')
    .replace('<!-- SCRIPT -->', () => '<script src="./app.js"></script>');
  writeFileSync(path.join(dist, 'index.html'), html);
}
writeHtml();
watch(path.join(root, 'src/index.html'), writeHtml);

const ctx = await esbuild.context({
  entryPoints: [path.join(root, 'src/main.js'), path.join(root, 'src/styles.css')],
  bundle: true,
  sourcemap: true,
  format: 'iife',
  target: ['es2020'],
  outdir: dist,
  entryNames: '[name]',
  loader: { '.json': 'json' },
  logLevel: 'info',
});
await ctx.watch();
const { host, port } = await ctx.serve({ servedir: dist, port: Number(process.env.PORT) || 5173 });
console.log(`INSIDERS CityOS dev server → http://${host === '0.0.0.0' ? 'localhost' : host}:${port}`);
