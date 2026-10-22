import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { describe, it } from 'node:test';

import { fromUint8Array, walkRar3Archive } from '@mary/rar';
import createComicArchive from '../../src/vendor/libarchive/comic_archive.mjs';
import { buildSingleEntryRar3, createRar3LibarchiveDecoder } from '../../src/rar3LibarchiveFallback.js';

const fixtureUrl = new URL('../fixtures/rar/comic-ppm-rar4.rar', import.meta.url);
const wasmUrl = new URL('../../src/vendor/libarchive/comic_archive.wasm', import.meta.url);

async function fixtureEntry() {
  const archive = new Uint8Array(await readFile(fixtureUrl));
  const source = fromUint8Array(archive);
  for await (const { block } of walkRar3Archive(source)) {
    if (block.kind !== 'file') {
      continue;
    }
    return {
      block,
      bytes: archive.slice(block.headerOffset, block.dataOffset + block.dataSize),
    };
  }
  throw new Error('RAR4 fixture has no file entry.');
}

describe('libarchive RAR3 fallback', () => {
  it('preserves Unicode RAR3 filenames while decoding the owned PPMd image', async () => {
    const wasmBinary = await readFile(wasmUrl);
    const decoder = createRar3LibarchiveDecoder(async () => createComicArchive({ wasmBinary }));
    const archive = new Uint8Array(
      await readFile(new URL('../fixtures/rar/comic-unicode-ppm-rar4.rar', import.meta.url)),
    );
    for await (const { block } of walkRar3Archive(fromUint8Array(archive))) {
      if (block.kind !== 'file') continue;
      const bytes = archive.slice(block.headerOffset, block.dataOffset + block.dataSize);
      const decoded = await decoder.decode(block, bytes);
      assert.equal(decoded.filename, 'pagë-01.PNG');
      assert.equal(
        createHash('sha256').update(decoded.fileData).digest('hex'),
        '146775fc99a489c2242ebe0e2bc2bde5681e0ea9ac552a83a1f351a09a6a77f1',
      );
      return;
    }
    assert.fail('Unicode fixture has no file entry.');
  });

  it('decodes the repository-owned PPMd RAR4 image exactly', async () => {
    const wasmBinary = await readFile(wasmUrl);
    const decoder = createRar3LibarchiveDecoder(async () => createComicArchive({ wasmBinary }));
    const { block, bytes } = await fixtureEntry();
    const decoded = await decoder.decode(block, bytes);

    assert.equal(decoded.filename, 'page-01.PNG');
    assert.equal(
      createHash('sha256').update(decoded.fileData).digest('hex'),
      '146775fc99a489c2242ebe0e2bc2bde5681e0ea9ac552a83a1f351a09a6a77f1',
    );
    assert.equal(decoder.version, 3_008_010);
  });

  it('builds a one-entry RAR4 with canonical boundaries', async () => {
    const { block, bytes } = await fixtureEntry();
    const synthetic = buildSingleEntryRar3(block, bytes);

    assert.deepEqual(synthetic.slice(0, 7), Uint8Array.from([0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00]));
    assert.deepEqual(synthetic.slice(-7), Uint8Array.from([0xc4, 0x3d, 0x7b, 0x00, 0x40, 0x07, 0x00]));
    assert.equal(synthetic.byteLength, 27 + block.headerSize + block.dataSize);
  });

  it('rejects metadata that does not match the fetched entry bytes', async () => {
    const { block, bytes } = await fixtureEntry();
    assert.throws(() => buildSingleEntryRar3(block, bytes.subarray(1)), /entry bytes/);
    assert.throws(() => buildSingleEntryRar3({ ...block, headerSize: 6 }, bytes), /header/);
  });

  it('rejects a decoded entry whose payload CRC differs from its header', async () => {
    const wasmBinary = await readFile(wasmUrl);
    const decoder = createRar3LibarchiveDecoder(async () => createComicArchive({ wasmBinary }));
    const { block, bytes } = await fixtureEntry();
    const corrupt = bytes.slice();
    corrupt[16] ^= 1; // Keep valid compressed bytes, but declare a different payload CRC.
    let headerCrc = 0xffffffff;
    for (const byte of corrupt.subarray(2, block.headerSize)) {
      headerCrc ^= byte;
      for (let bit = 0; bit < 8; bit++) headerCrc = (headerCrc >>> 1) ^ (headerCrc & 1 ? 0xedb88320 : 0);
    }
    headerCrc = (headerCrc ^ 0xffffffff) & 0xffff;
    corrupt[0] = headerCrc & 0xff;
    corrupt[1] = headerCrc >>> 8;
    await assert.rejects(decoder.decode(block, corrupt), /CRC|checksum/i);
  });

  it('normalizes Windows path separators returned by libarchive', async () => {
    const heap = new Uint8Array(1024);
    let allocation = 32;
    let nextCalls = 0;
    const module = {
      HEAPU8: heap,
      UTF8ToString: () => 'Folder\\page-01.jpg',
      _comic_archive_close() {},
      _comic_archive_entry_is_encrypted: () => 0,
      _comic_archive_entry_is_regular: () => 1,
      _comic_archive_entry_path: () => 1,
      _comic_archive_entry_size: () => 3,
      _comic_archive_last_error: () => 0,
      _comic_archive_next: () => (nextCalls++ === 0 ? 1 : 0),
      _comic_archive_open: () => 1,
      _comic_archive_read_exact(_handle, pointer) {
        heap.set([1, 2, 3], pointer);
        return 1;
      },
      _comic_archive_version_number: () => 3_008_010,
      _free() {},
      _malloc(bytes) {
        const pointer = allocation;
        allocation += bytes;
        return pointer;
      },
    };
    const decoder = createRar3LibarchiveDecoder(async () => module);
    const block = {
      dataSize: 1,
      headerSize: 7,
      name: 'Folder/page-01.jpg',
      unpackedSize: 3,
    };

    const decoded = await decoder.decode(block, Uint8Array.of(1, 2, 3, 4, 5, 6, 7, 8));

    assert.equal(decoded.filename, 'Folder/page-01.jpg');
    assert.deepEqual(decoded.fileData, Uint8Array.of(1, 2, 3));
  });
});
