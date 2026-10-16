import assert from 'node:assert/strict';
import { deflateRawSync } from 'node:zlib';
import { describe, it } from 'node:test';

import '../../js/reader-limits.js';
import { extractZipRange } from '../../src/zipRangeExtractor.js';

const limits = globalThis.ReaderLimits;
const DATA_DESCRIPTOR_FLAG = 1 << 3;
const ENCRYPTED_FLAG = 1;
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

function makeZip(entries, options = {}) {
  const localParts = [];
  const centralParts = [];
  let localOffset = 0;

  for (const entry of entries) {
    const name = Buffer.from(entry.name, 'utf8');
    const contents = Buffer.from(entry.contents ?? []);
    const method = entry.method ?? 8;
    const compressed = entry.compressed ?? (method === 8 ? deflateRawSync(contents) : contents);
    const checksum = entry.crc ?? crc32(contents);
    const flags = (entry.descriptor ? DATA_DESCRIPTOR_FLAG : 0)
      | (entry.encrypted ? ENCRYPTED_FLAG : 0)
      | (entry.utf8 === false ? 0 : UTF8_FLAG);
    const localFlags = entry.localFlags ?? flags;
    const localMethod = entry.localMethod ?? method;

    const localHeader = Buffer.alloc(30);
    localHeader.writeUInt32LE(0x04034b50, 0);
    localHeader.writeUInt16LE(20, 4);
    localHeader.writeUInt16LE(localFlags, 6);
    localHeader.writeUInt16LE(localMethod, 8);
    if (!entry.descriptor) {
      localHeader.writeUInt32LE(checksum, 14);
      localHeader.writeUInt32LE(compressed.length, 18);
      localHeader.writeUInt32LE(contents.length, 22);
    }
    localHeader.writeUInt16LE(name.length, 26);
    localHeader.writeUInt16LE(0, 28);

    const descriptor = entry.descriptor ? Buffer.alloc(entry.signedDescriptor === false ? 12 : 16) : Buffer.alloc(0);
    if (entry.descriptor) {
      let descriptorOffset = 0;
      if (entry.signedDescriptor !== false) {
        descriptor.writeUInt32LE(0x08074b50, descriptorOffset);
        descriptorOffset += 4;
      }
      descriptor.writeUInt32LE(entry.descriptorCrc ?? checksum, descriptorOffset);
      descriptor.writeUInt32LE(compressed.length, descriptorOffset + 4);
      descriptor.writeUInt32LE(contents.length, descriptorOffset + 8);
    }

    localParts.push(localHeader, name, compressed, descriptor);

    const centralHeader = Buffer.alloc(46);
    centralHeader.writeUInt32LE(0x02014b50, 0);
    centralHeader.writeUInt16LE(((entry.hostOs ?? 0) << 8) | 20, 4);
    centralHeader.writeUInt16LE(20, 6);
    centralHeader.writeUInt16LE(flags, 8);
    centralHeader.writeUInt16LE(method, 10);
    centralHeader.writeUInt32LE(checksum, 16);
    centralHeader.writeUInt32LE(compressed.length, 20);
    centralHeader.writeUInt32LE(contents.length, 24);
    centralHeader.writeUInt16LE(name.length, 28);
    centralHeader.writeUInt16LE(0, 30);
    centralHeader.writeUInt16LE(0, 32);
    centralHeader.writeUInt16LE(entry.diskStart ?? 0, 34);
    centralHeader.writeUInt32LE(entry.externalAttributes ?? 0, 38);
    centralHeader.writeUInt32LE(entry.centralLocalOffset ?? localOffset, 42);
    centralParts.push(centralHeader, name);

    localOffset += localHeader.length + name.length + compressed.length + descriptor.length;
  }

  const localArea = Buffer.concat(localParts);
  const centralDirectory = Buffer.concat(centralParts);
  const centralOffset = options.centralOffset ?? localArea.length;
  assert.ok(centralOffset >= localArea.length);

  const comment = Buffer.from(options.comment ?? []);
  const end = Buffer.alloc(22 + comment.length);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(options.disk ?? 0, 4);
  end.writeUInt16LE(options.directoryDisk ?? 0, 6);
  end.writeUInt16LE(options.diskEntries ?? entries.length, 8);
  end.writeUInt16LE(options.entries ?? entries.length, 10);
  end.writeUInt32LE(options.directorySize ?? centralDirectory.length, 12);
  end.writeUInt32LE(options.directoryOffset ?? centralOffset, 16);
  end.writeUInt16LE(comment.length, 20);
  comment.copy(end, 22);

  const archiveLength = centralOffset + centralDirectory.length + end.length;
  const tailStart = Math.max(0, archiveLength - 65_557);
  const tail = Buffer.alloc(archiveLength - tailStart);
  if (tailStart < localArea.length) {
    localArea.subarray(tailStart).copy(tail, 0);
  }
  centralDirectory.copy(tail, centralOffset - tailStart);
  end.copy(tail, centralOffset + centralDirectory.length - tailStart);

  return {
    archiveLength,
    localArea,
    centralDirectory,
    centralOffset,
    end,
    regions: [
      { bytes: localArea, offset: 0 },
      { bytes: tail, offset: tailStart },
    ],
  };
}

function sparseReader(length, regions) {
  const reads = [];
  return {
    length,
    reads,
    async read(offset, size = length - offset) {
      reads.push({ offset, size });
      const result = new Uint8Array(size);
      const covered = new Uint8Array(size);
      for (const region of regions) {
        const start = Math.max(offset, region.offset);
        const end = Math.min(offset + size, region.offset + region.bytes.length);
        if (start >= end) continue;
        const sourceStart = start - region.offset;
        const targetStart = start - offset;
        result.set(region.bytes.subarray(sourceStart, sourceStart + end - start), targetStart);
        covered.fill(1, targetStart, targetStart + end - start);
      }
      assert.equal(covered.every((value) => value === 1), true, `Unexpected sparse read ${offset}+${size}`);
      return new ReadableStream({
        start(controller) {
          controller.enqueue(result);
          controller.close();
        },
      });
    },
  };
}

async function extract(archive, customLimits = limits) {
  const source = sparseReader(archive.archiveLength, archive.regions);
  const files = [];
  const progress = [];
  const result = await extractZipRange(source, customLimits, {
    extract(entry) {
      files.push({ filename: entry.filename, fileData: Buffer.from(entry.fileData) });
    },
    progress(event) {
      progress.push(event);
    },
  });
  return { files, progress, result, source };
}

describe('bounded ZIP range extraction', () => {
  it('extracts stored and deflated entries from a sparse 278 MB archive', async () => {
    const archive = makeZip([
      { contents: 'stored page', method: 0, name: 'page-01.JPG' },
      { contents: 'deflated page', descriptor: true, name: 'päge-02.webp' },
      { contents: '', externalAttributes: 0x10, method: 0, name: 'folder/' },
      {
        contents: 'page-01.JPG',
        externalAttributes: (0xa000 | 0o777) * 0x10000,
        hostOs: 3,
        method: 0,
        name: 'latest.jpg',
      },
    ], { centralOffset: 278_391_476 });

    const { files, result, source } = await extract(archive);

    assert.deepEqual(files, [
      { filename: 'page-01.JPG', fileData: Buffer.from('stored page') },
      { filename: 'päge-02.webp', fileData: Buffer.from('deflated page') },
    ]);
    assert.deepEqual(result, {
      totalBytes: 24,
      totalFiles: 2,
    });
    assert.ok(source.reads.reduce((sum, read) => sum + read.size, 0) < 1024 * 1024);
    assert.ok(source.reads.every((read) => read.size <= 65_557));
  });

  it('validates signed and unsigned data descriptors', async () => {
    const signed = makeZip([{ contents: 'signed', descriptor: true, name: 'signed.jpg' }]);
    const unsigned = makeZip([{
      contents: 'unsigned',
      descriptor: true,
      name: 'unsigned.jpg',
      signedDescriptor: false,
    }]);

    assert.equal((await extract(signed)).files[0].fileData.toString(), 'signed');
    assert.equal((await extract(unsigned)).files[0].fileData.toString(), 'unsigned');

    const invalid = makeZip([{
      contents: 'invalid',
      descriptor: true,
      descriptorCrc: 0,
      name: 'invalid.jpg',
    }]);
    await assert.rejects(extract(invalid), /data descriptor/);
  });

  it('rejects encrypted, unsupported, duplicate, and inconsistent entries before output', async () => {
    const cases = [
      [makeZip([{ contents: 'x', encrypted: true, name: 'encrypted.jpg' }]), /Encrypted ZIP/],
      [makeZip([{ contents: 'x', method: 12, name: 'unsupported.jpg' }]), /compression method/],
      [makeZip([
        { contents: 'one', name: 'one.jpg' },
        { centralLocalOffset: 0, contents: 'two', name: 'two.jpg' },
      ]), /duplicate local/],
      [makeZip([{ contents: 'x', localMethod: 0, name: 'mismatch.jpg' }]), /local header/],
    ];

    for (const [archive, expected] of cases) {
      await assert.rejects(extract(archive), expected);
    }
  });

  it('rejects corrupt output and unsafe central-directory metadata', async () => {
    const corrupt = makeZip([{ contents: 'bad crc', crc: 0, name: 'bad.jpg' }]);
    await assert.rejects(extract(corrupt), /CRC32/);

    const metadataCases = [
      [makeZip([{ contents: 'x', name: 'x.jpg' }], { disk: 1 }), /Multi-disk ZIP/],
      [makeZip([{ contents: 'x', name: 'x.jpg' }], { entries: 0xffff }), /ZIP64/],
      [makeZip([{ contents: 'x', name: 'x.jpg' }], { directoryOffset: 0xffffffff }), /ZIP64/],
      [makeZip([{ contents: 'x', name: 'x.jpg' }], { directorySize: 0xffffffff }), /ZIP64/],
    ];
    for (const [archive, expected] of metadataCases) {
      await assert.rejects(extract(archive), expected);
    }
  });

  it('applies the entry, ratio, and aggregate output policy during preflight', async () => {
    const archive = makeZip([{ contents: '12345', name: 'page.jpg' }]);
    const customLimits = {
      ...limits,
      policy: { ...limits.policy, entries: 10, directoryBytes: 1024 },
      entry(size, compressed, total) {
        assert.equal(size, 5);
        assert.ok(compressed > 0);
        throw new Error('synthetic output policy rejection');
      },
    };

    await assert.rejects(extract(archive, customLimits), /synthetic output policy rejection/);
  });
});
