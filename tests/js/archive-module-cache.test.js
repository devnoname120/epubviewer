import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const loaderUrl = new URL('../../js/cbrjs/bitjs.js', import.meta.url);
const adapterUrl = new URL('../../src/rarUnarchiver.js', import.meta.url);
const epubTemplateUrl = new URL('../../templates/epubviewer.php', import.meta.url);
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

test('loads the generated EPUB highlight controller before reader startup', async () => {
  const [template, viteConfig] = await Promise.all([
    readFile(epubTemplateUrl, 'utf8'),
    readFile(viteConfigUrl, 'utf8'),
  ]);

  assert.match(
    viteConfig,
    /['"]epub-highlights['"]:\s*path\.resolve\(import\.meta\.dirname,\s*['"]src\/epubHighlights\/index\.js['"]\)/,
  );
  const reader = template.indexOf('js/epubjs/reader.min.js');
  const highlights = template.indexOf('js/epubviewer-epub-highlights.mjs');
  const startup = template.indexOf('js/epubviewer-ready.mjs');
  assert.ok(reader >= 0 && highlights > reader && startup > highlights);
  assert.match(template, /id="show-Highlights"[^>]*data-view="Highlights"/);
  assert.match(template, /id="highlightsView"[^>]*class="highlights-view view"/);
  assert.match(template, /id="epub-highlight-toolbar"[^>]*role="toolbar"[^>]*hidden/);
  assert.deepEqual(
    [...template.matchAll(/data-highlight-color="([a-z]+)"/g)].map((match) => match[1]),
    ['yellow', 'green', 'blue', 'pink', 'orange'],
  );
});
