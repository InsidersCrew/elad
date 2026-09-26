#!/usr/bin/env node
/**
 * Production build: bundles src/main.js (+ three.js + data) with esbuild and
 * inlines script and styles into a single self-contained dist/index.html.
 * Also writes dist/app.js + dist/styles.css for hosts that prefer separate files.
 */
import { build } from 'esbuild';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const dist = path.join(root, 'dist');
mkdirSync(dist, { recursive: true });

const js = await build({
  entryPoints: [path.join(root, 'src/main.js')],
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2020'],
  write: false,
  loader: { '.json': 'json' },
  define: { 'process.env.NODE_ENV': '"production"' },
  legalComments: 'none',
});
const css = await build({
  entryPoints: [path.join(root, 'src/styles.css')],
  bundle: true,
  minify: true,
  write: false,
});
const script = js.outputFiles[0].text;
const style = css.outputFiles[0].text;
writeFileSync(path.join(dist, 'app.js'), script);
writeFileSync(path.join(dist, 'styles.css'), style);

const template = readFileSync(path.join(root, 'src/index.html'), 'utf8');
const html = template
  .replace('<!-- STYLES -->', () => `<style>${style}</style>`)
  .replace('<!-- SCRIPT -->', () => `<script>${script.replace(/<\/script>/g, '<\\/script>')}</script>`);
writeFileSync(path.join(dist, 'index.html'), html);

// Artifact variant: the claude.ai artifact host wraps the file in its own <html>/<head>/<body>,
// so ship only <title> + font link + <style> + body content + <script>.
const bodyInner = html.match(/<body>([\s\S]*)<\/body>/)[1].replace(/<script>[\s\S]*<\/script>\s*$/, '');
const fontLink = html.match(/<link href="https:\/\/fonts\.googleapis\.com[^>]*>/)?.[0] ?? '';
const artifact = `<title>INSIDERS CityOS</title>\n${fontLink}\n<style>${style}</style>\n${bodyInner}\n<script>${script.replace(/<\/script>/g, '<\\/script>')}</script>\n`;
writeFileSync(path.join(dist, 'artifact.html'), artifact);

const kb = (n) => (n / 1024).toFixed(0) + ' KB';
console.log(`built dist/index.html (${kb(Buffer.byteLength(html))}) · app.js ${kb(Buffer.byteLength(script))} · styles.css ${kb(Buffer.byteLength(style))}`);
