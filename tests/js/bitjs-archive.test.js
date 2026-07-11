import assert from 'node:assert/strict';
import test from 'node:test';
import { deflateRawSync } from 'node:zlib';

import { getUnarchiver } from '../../js/bitjs/v1.2.6/archive/decompress.js';

const DATA_DESCRIPTOR_FLAG = 1 << 3;
const UTF8_FLAG = 1 << 11;

function crc32(bytes) {
  let crc = 0xffffffff;

  for (const byte of bytes) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++) {
      crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
    }
  }

  return (crc ^ 0xffffffff) >>> 0;
}

function createDataDescriptorZip(entries) {
  const localParts = [];
  const centralParts = [];
  let localOffset = 0;

  for (const entry of entries) {
    const filename = Buffer.from(entry.filename, 'utf8');
    const contents = Buffer.from(entry.contents);
    const compressed = deflateRawSync(contents);
    const checksum = crc32(contents);
    const flags = DATA_DESCRIPTOR_FLAG | UTF8_FLAG;

    const localHeader = Buffer.alloc(30);
    localHeader.writeUInt32LE(0x04034b50, 0);
    localHeader.writeUInt16LE(20, 4);
    localHeader.writeUInt16LE(flags, 6);
    localHeader.writeUInt16LE(8, 8);
    localHeader.writeUInt16LE(filename.length, 26);

    const descriptor = Buffer.alloc(16);
    descriptor.writeUInt32LE(0x08074b50, 0);
    descriptor.writeUInt32LE(checksum, 4);
    descriptor.writeUInt32LE(compressed.length, 8);
    descriptor.writeUInt32LE(contents.length, 12);

    localParts.push(localHeader, filename, compressed, descriptor);

    const centralHeader = Buffer.alloc(46);
    centralHeader.writeUInt32LE(0x02014b50, 0);
    centralHeader.writeUInt16LE(20, 4);
    centralHeader.writeUInt16LE(20, 6);
    centralHeader.writeUInt16LE(flags, 8);
    centralHeader.writeUInt16LE(8, 10);
    centralHeader.writeUInt32LE(checksum, 16);
    centralHeader.writeUInt32LE(compressed.length, 20);
    centralHeader.writeUInt32LE(contents.length, 24);
    centralHeader.writeUInt16LE(filename.length, 28);
    centralHeader.writeUInt32LE(localOffset, 42);
    centralParts.push(centralHeader, filename);

    localOffset += localHeader.length + filename.length + compressed.length + descriptor.length;
  }

  const centralDirectory = Buffer.concat(centralParts);
  const endOfCentralDirectory = Buffer.alloc(22);
  endOfCentralDirectory.writeUInt32LE(0x06054b50, 0);
  endOfCentralDirectory.writeUInt16LE(entries.length, 8);
  endOfCentralDirectory.writeUInt16LE(entries.length, 10);
  endOfCentralDirectory.writeUInt32LE(centralDirectory.length, 12);
  endOfCentralDirectory.writeUInt32LE(localOffset, 16);

  return Buffer.concat([...localParts, centralDirectory, endOfCentralDirectory]);
}

function toArrayBuffer(buffer) {
  return buffer.buffer.slice(buffer.byteOffset, buffer.byteOffset + buffer.byteLength);
}

test('extracts data-descriptor CBZ entries and preserves UTF-8 filenames', async () => {
  const expected = new Map([
    ['päge 01.jpg', Buffer.from('first comic page')],
    ['ページ 02.WEBP', Buffer.from('second comic page')],
  ]);
  const archive = createDataDescriptorZip(
    Array.from(expected, ([filename, contents]) => ({ filename, contents })),
  );
  const extracted = new Map();
  const unarchiver = getUnarchiver(toArrayBuffer(archive));

  assert.ok(unarchiver, 'BitJS should recognize the generated CBZ as a ZIP archive');
  unarchiver.onExtract((event) => {
    extracted.set(event.unarchivedFile.filename, Buffer.from(event.unarchivedFile.fileData));
  });

  await unarchiver.start();

  assert.deepEqual(Array.from(extracted.keys()), Array.from(expected.keys()));
  for (const [filename, contents] of expected) {
    assert.deepEqual(extracted.get(filename), contents);
  }
});
