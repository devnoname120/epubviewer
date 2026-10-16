import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { inflate } from '../../js/bitjs/v1.2.6/archive/inflate.js';
import { deflateRawSync, gzipSync } from 'node:zlib';
import { getUnarchiver } from '../../js/bitjs/v1.2.6/archive/decompress.js';

const limits = globalThis.ReaderLimits;
const fixture = new URL('../fixtures/epub/epub2-metadata-cover.epub', import.meta.url);
const buffer = (data) => data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength);

test('rejects over-budget entry sizes, cumulative bytes, and compression ratios', () => {
  assert.throws(() => limits.entry(limits.policy.entryBytes + 1, 1, 0), /output limit/);
  assert.throws(() => limits.entry(2, 2, limits.policy.totalBytes - 1), /cumulative/);
  assert.throws(() => limits.entry(limits.policy.ratioMinimum, 1, 0), /compression ratio/);
  assert.throws(() => limits.entry(NaN, 1, 0));
  assert.equal(limits.entry(1024, 10, 0), 1024);
});

test('RAR range budget covers one archive scan and one bounded fallback entry', () => {
  assert.ok(
    limits.policy.rarFetchBytes
      >= limits.policy.rarArchiveBytes + limits.policy.directoryBytes + limits.policy.entryBytes,
  );
});

test('checks ZIP metadata before materializing entries and does not trust a false entry count', () => {
  const data = readFileSync(fixture);
  assert.ok(limits.inspectZip(data).size > 1);
  const end = data.lastIndexOf(Buffer.from('504b0506', 'hex'));
  const aboveCount = Buffer.from(data);
  aboveCount.writeUInt16LE(limits.policy.entries + 1, end + 8);
  aboveCount.writeUInt16LE(limits.policy.entries + 1, end + 10);
  assert.throws(() => limits.inspectZip(aboveCount), /central directory exceeds limits/);
  const underCount = Buffer.from(data);
  underCount.writeUInt16LE(0, end + 8);
  underCount.writeUInt16LE(0, end + 10);
  assert.throws(() => limits.inspectZip(underCount), /entry count/);
});

test('cancels a stream immediately after the byte limit is crossed', async () => {
  let cancelled = false;
  const stream = new ReadableStream({
    start(controller) {
      controller.enqueue(new Uint8Array([1, 2, 3, 4]));
    },
    cancel() {
      cancelled = true;
    },
  });
  await assert.rejects(limits.readStream(stream, 3), /byte limit/);
  assert.equal(cancelled, true);
});

test('aborts a pending publication stream without waiting for more bytes', async () => {
  const controller = new AbortController();
  let cancelled = false;
  const stream = new ReadableStream({
    cancel() {
      cancelled = true;
    },
  });
  const result = limits.readStream(stream, 100, controller.signal);
  controller.abort();
  await assert.rejects(result, /cancelled/);
  assert.equal(cancelled, true);
});

test('bounded inflate preserves valid compressed data and refuses inconsistent output sizes', async () => {
  const text = Buffer.from('normal publication text');
  const compressed = deflateRawSync(text);
  assert.deepEqual(Buffer.from(await inflate(compressed, text.length)), text);
  await assert.rejects(inflate(compressed, text.length - 1), /byte limit|output size|buffer is full/);
});

test('GZIP still extracts a normal publication member through the bounded stream', async () => {
  const content = Buffer.from('normal gzip member');
  const archive = getUnarchiver(buffer(gzipSync(content)));
  let output;
  archive.onExtract((event) => {
    output = Buffer.from(event.unarchivedFile.fileData);
  });
  await archive.start();
  assert.deepEqual(output, content);
});

test('validates image headers before decode and rejects unknown and animated images', () => {
  const png = readFileSync(new URL('../../img/book.png', import.meta.url));
  assert.ok(limits.image(png) > 0);
  const larger = Buffer.from(png);
  larger.writeUInt32BE(limits.policy.imageDimension + 1, 16);
  assert.throws(() => limits.image(larger), /pixel limit/);
  assert.throws(() => limits.image(new Uint8Array(100)), /pixel limit/);
  assert.throws(() => limits.image(readFileSync(new URL('../../img/loading.gif', import.meta.url))), /Animated/);
});

test('PDF page-count limits accept the boundary and refuse an oversized document', () => {
  limits.documentPages(limits.policy.pages);
  assert.throws(() => limits.documentPages(limits.policy.pages + 1), /page-count/);
  assert.throws(() => limits.documentPages(0), /page-count/);
});
