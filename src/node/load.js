/** Node-only data loader (the browser bundle imports the JSON files directly). */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

export function loadData(dir = path.join(root, 'data')) {
  const read = (name) => JSON.parse(readFileSync(path.join(dir, name), 'utf8'));
  return {
    city: read('city.json'),
    model: read('model.json'),
    rules: read('rules.json'),
    rootcauses: read('rootcauses.json'),
    skills: read('skills.json'),
  };
}

export { root };
