import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const loaderUrl = new URL('../../js/cbrjs/bitjs.js', import.meta.url);

test('versions the generated archive module with the template revision query', async () => {
  const source = await readFile(loaderUrl, 'utf8');

  assert.doesNotMatch(
    source,
    /import\s*\{[^}]*createRarUrlUnarchiver[^}]*\}\s*from\s*['"]\.\.\/epubviewer-rar\.mjs['"]/,
  );
  assert.match(source, /new URL\(['"]\.\.\/epubviewer-rar\.mjs['"], import\.meta\.url\)/);
  assert.match(source, /archiveModuleUrl\.search\s*=\s*new URL\(import\.meta\.url\)\.search/);
  assert.match(source, /await import\(archiveModuleUrl\.href\)/);
});
