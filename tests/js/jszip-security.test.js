import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const bundlePath = new URL('../../js/epubjs/libs/zip.min.js', import.meta.url);
function LegacyBuffer(data, encoding) {
  return typeof data === 'number' ? Buffer.alloc(data) : Buffer.from(data, encoding);
}
LegacyBuffer.isBuffer = Buffer.isBuffer;

const context = vm.createContext({
  Buffer: LegacyBuffer,
  exports: {},
  module: { exports: {} },
});
vm.runInContext(fs.readFileSync(bundlePath, 'utf8'), context, { filename: bundlePath.pathname });
const JSZip = context.module.exports;

function storedArchive(entries) {
  const zip = new JSZip();
  for (const [name, contents] of entries) {
    zip.file(name, contents);
  }
  return zip.generate({ type: 'nodebuffer', compression: 'STORE' });
}

function replaceAscii(buffer, from, to) {
  assert.equal(from.length, to.length, 'replacement names must have equal byte lengths');
  const result = Buffer.from(buffer);
  const fromBytes = Buffer.from(from, 'ascii');
  const toBytes = Buffer.from(to, 'ascii');
  let offset = 0;
  let replacements = 0;

  while ((offset = result.indexOf(fromBytes, offset)) !== -1) {
    toBytes.copy(result, offset);
    offset += toBytes.length;
    replacements++;
  }

  assert.equal(replacements, 2, 'the local and central directory names should both be replaced');
  return result;
}

test('loads Object prototype filenames without prototype pollution', () => {
  const archive = replaceAscii(storedArchive([['safe_name', 'prototype-safe']]), 'safe_name', '__proto__');
  const loaded = new JSZip(archive);

  assert.equal(Object.getPrototypeOf(loaded.files), null);
  assert.equal(loaded.file('__proto__').asText(), 'prototype-safe');
  assert.equal(loaded.file('__proto__').unsafeOriginalName, '__proto__');
  assert.equal(loaded.files.polluted, undefined);
});

test('sanitizes relative entry names while retaining synchronous access', () => {
  const archive = storedArchive([
    ['../../escape.txt', 'escape'],
    ['OPS/images/../chapter.xhtml', 'chapter'],
    ['OPS//nested/./page.xhtml', 'page'],
    ['OPS/normal.xhtml', 'normal'],
  ]);
  const loaded = new JSZip(archive);

  assert.equal(loaded.file('escape.txt').asText(), 'escape');
  assert.equal(loaded.file('escape.txt').unsafeOriginalName, '../../escape.txt');
  assert.equal(loaded.file('OPS/chapter.xhtml').asText(), 'chapter');
  assert.equal(loaded.file('OPS/chapter.xhtml').unsafeOriginalName, 'OPS/images/../chapter.xhtml');
  assert.equal(loaded.file('OPS/nested/page.xhtml').asText(), 'page');
  assert.equal(loaded.file('OPS/normal.xhtml').asText(), 'normal');
  assert.equal(loaded.file('../../escape.txt'), null);
  assert.equal(loaded.file('OPS/images/../chapter.xhtml'), null);

  // JSZip 2.x remains synchronous: no promise or async extraction API was introduced.
  assert.equal(typeof loaded.then, 'undefined');
  assert.equal(typeof loaded.file('OPS/normal.xhtml').asText(), 'string');
});
