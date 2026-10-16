import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const loaderUrl = new URL('../../js/cbrjs/bitjs.js', import.meta.url);
const adapterUrl = new URL('../../src/rarUnarchiver.js', import.meta.url);
const viteConfigUrl = new URL('../../vite.config.ts', import.meta.url);

test('versions the generated archive module with the template revision query', async () => {
  const source = await readFile(loaderUrl, 'utf8');

  assert.doesNotMatch(
    source,
    /import\s*\{[^}]*createRarUrlUnarchiver[^}]*\}\s*from\s*['"]\.\.\/epubviewer-rar\.mjs['"]/,
  );
  assert.match(source, /new URL\(['"]\.\.\/epubviewer-rar\.mjs['"], import\.meta\.url\)/);
  assert.match(source, /archiveModuleUrl\.search\s*=\s*new URL\(import\.meta\.url\)\.search/);
  assert.doesNotMatch(source, /await import\(archiveModuleUrl\.href\)/);
  assert.match(source, /window\.CBRJS\.archiveReady\s*=\s*import\(archiveModuleUrl\.href\)\.then/);
});

test('emits a hashed worker through Vite static worker discovery', async () => {
  const [adapter, viteConfig] = await Promise.all([
    readFile(adapterUrl, 'utf8'),
    readFile(viteConfigUrl, 'utf8'),
  ]);

  assert.match(
    adapter,
    /new Worker\(new URL\(['"]\.\/rarWorker\.ts['"], import\.meta\.url\),\s*\{/,
  );
  assert.match(
    viteConfig,
    /entryFileNames:\s*['"]js\/epubviewer-rar-worker-\[hash\]\.mjs['"]/,
  );
});
