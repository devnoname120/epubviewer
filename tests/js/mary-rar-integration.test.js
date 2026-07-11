import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';

const supportsExplicitResourceManagement = Number.parseInt(process.versions.node, 10) >= 24;

async function extractFixture(path) {
  const { fromUint8Array, unrar } = await import('@mary/rar');
  const archive = await readFile(new URL(path, import.meta.url));
  const entries = [];

  for await (const entry of unrar(fromUint8Array(new Uint8Array(archive)))) {
    if (!entry.isDirectory && !entry.isSymlink) {
      entries.push({
        filename: entry.filename,
        bytes: await entry.bytes(),
      });
    }
  }

  return entries;
}

test('extracts a solid RAR 5 comic fixture with @mary/rar', {
  skip: !supportsExplicitResourceManagement,
}, async () => {
  const entries = await extractFixture('../fixtures/rar/sample-rar5.rar');
  const expectedBook = await readFile(new URL('../../img/book.png', import.meta.url));
  const expectedLoading = await readFile(new URL('../../img/loading.gif', import.meta.url));
  const byName = new Map(entries.map((entry) => [entry.filename, entry.bytes]));

  assert.deepEqual([...byName.keys()].sort(), ['img/book.png', 'img/loading.gif']);
  assert.deepEqual(byName.get('img/book.png'), new Uint8Array(expectedBook));
  assert.deepEqual(byName.get('img/loading.gif'), new Uint8Array(expectedLoading));
});

test('extracts a RAR 4 fixture with @mary/rar', {
  skip: !supportsExplicitResourceManagement,
}, async () => {
  const entries = await extractFixture('../fixtures/rar/sample-rar4.rar');

  assert.deepEqual(entries.map((entry) => entry.filename), [
    'Folder1/Folder Space/long.txt',
    'Folder1/Folder 中文/2中文.txt',
  ]);
  assert.equal(entries.reduce((total, entry) => total + entry.bytes.byteLength, 0), 1_049_091);
});
