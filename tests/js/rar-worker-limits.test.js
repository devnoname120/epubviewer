import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import createComicArchive from '../../src/vendor/libarchive/comic_archive.mjs';
import { createRar3LibarchiveDecoder } from '../../src/rar3LibarchiveFallback.js';
import '../../js/reader-limits.js';

const RAR3_MODE_SWITCH_ERROR = 'RAR3 in-stream table refresh switched to PPMd unexpectedly';
const RAR3_HUFFMAN_TABLE_ERROR = 'over-subscribed RAR3 Huffman table';
const RAR3_SIZE_MISMATCH_ERROR = 'RAR3 decompressed size mismatch: got 2 bytes, expected 3';
const RAR3_FALLBACK_ERRORS = [RAR3_MODE_SWITCH_ERROR, RAR3_HUFFMAN_TABLE_ERROR, RAR3_SIZE_MISMATCH_ERROR];

const workerSource = ts
  .transpileModule(fs.readFileSync(new URL('../../src/rarWorker.ts', import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.ES2022, target: ts.ScriptTarget.ES2022 },
  })
  .outputText.replace(/^import .*;\n/gm, '');

function readerFromBytes(bytes) {
  return {
    length: bytes.byteLength,
    async read(offset, length = bytes.byteLength - offset) {
      const data = bytes.slice(offset, offset + length);
      return new ReadableStream({
        start(controller) {
          controller.enqueue(data);
          controller.close();
        },
      });
    },
  };
}

function streamBytes(...bytes) {
  return new ReadableStream({
    start(controller) {
      controller.enqueue(Uint8Array.from(bytes));
      controller.close();
    },
  });
}

function failingStream(message) {
  return new ReadableStream({
    start(controller) {
      controller.error(new Error(message));
    },
  });
}

function rar3Block(overrides = {}) {
  return {
    kind: 'file',
    attributes: 0,
    dataOffset: 14,
    dataSize: 3,
    fileFlags: 0,
    headerOffset: 7,
    headerSize: 7,
    hostOs: 3,
    method: 0x35,
    name: 'page-01.jpg',
    unpackedSize: 3,
    ...overrides,
  };
}

function rar3Entry(block, body) {
  return {
    compressedSize: block.dataSize,
    filename: block.name,
    isDirectory: false,
    isEncrypted: false,
    isSolid: (block.fileFlags & 0x0010) !== 0,
    isSplit: false,
    isSymlink: false,
    size: block.unpackedSize,
    get body() {
      return body();
    },
  };
}

function rar3Backend(bytes, blocks, entries, decode) {
  return {
    fromUint8Array: () => readerFromBytes(bytes),
    walkRar3Archive: async function* () {
      for (const block of blocks) yield { block };
    },
    walkRar5Archive: async function* () {},
    unrar: async function* () {},
    unrar3: async function* () {
      for (const entry of entries) yield entry;
    },
    getRar3FallbackDecoder: () => ({ decode }),
  };
}

async function runWorker(input, backend) {
  let listener;
  const messages = [];
  let complete;
  const result = new Promise((resolve) => {
    complete = resolve;
  });
  const scope = {
    location: { origin: 'https://cloud.example.test' },
    postMessage(message) {
      messages.push(message);
      if (message.type === 'finish' || message.type === 'error') complete(messages);
    },
    addEventListener(type, callback) {
      listener = callback;
    },
  };
  vm.runInNewContext(workerSource, {
    ...backend,
    createArchiveRangeReader:
      backend.createArchiveRangeReader ??
      backend.createRarRangeReader ??
      (() => {
        throw new Error('Unexpected URL source');
      }),
    createRarRangeReader:
      backend.createRarRangeReader ??
      (() => {
        throw new Error('Unexpected URL source');
      }),
    extractZipRange:
      backend.extractZipRange ??
      (() => {
        throw new Error('Unexpected ZIP extraction');
      }),
    getRar3FallbackDecoder:
      backend.getRar3FallbackDecoder ??
      (() => ({
        decode() {
          throw new Error('Unexpected RAR3 fallback');
        },
      })),
    self: scope,
    ReaderLimits: globalThis.ReaderLimits,
    ArrayBuffer,
    Uint8Array,
    ReadableStream,
    Error,
    console,
  });
  listener({
    data:
      input instanceof Uint8Array
        ? { type: 'extract', arrayBuffer: input.buffer.slice(input.byteOffset, input.byteOffset + input.byteLength) }
        : input,
  });
  return result;
}

function metadataBackend(block) {
  let extractionStarted = false;
  const metadata = {
    dataOffset: 7,
    headerOffset: 0,
    headerSize: 7,
    ...block,
  };
  const walk = async function* () {
    yield { block: metadata };
  };
  return {
    fromUint8Array: readerFromBytes,
    walkRar3Archive: walk,
    walkRar5Archive: walk,
    unrar3: async function* () {
      extractionStarted = true;
    },
    unrar: async function* () {
      extractionStarted = true;
    },
    wasExtracted: () => extractionStarted,
  };
}

test('RAR worker refuses excessive declared output before starting the decoder', async () => {
  const backend = metadataBackend({
    kind: 'file',
    unpackedSize: globalThis.ReaderLimits.policy.entryBytes + 1,
    dataSize: 100,
  });
  const messages = await runWorker(Buffer.from('526172211a070000', 'hex'), backend);
  assert.equal(messages.at(-1).type, 'error');
  assert.match(messages.at(-1).msg, /output limit/);
  assert.equal(backend.wasExtracted(), false);
});

test('RAR worker refuses an oversized decoder dictionary before allocating it', async () => {
  const backend = metadataBackend({ kind: 'file', unpackedSize: 10, dataSize: 10, compressionInfo: 10 << 10 });
  const messages = await runWorker(Buffer.from('526172211a070100', 'hex'), backend);
  assert.equal(messages.at(-1).type, 'error');
  assert.match(messages.at(-1).msg, /dictionary memory limit/);
  assert.equal(backend.wasExtracted(), false);
});

test(
  'RAR worker extracts the normal solid RAR 5 fixture with its resource policy',
  {
    skip: Number.parseInt(process.versions.node, 10) < 24,
  },
  async () => {
    const backend = await import('@mary/rar');
    const data = fs.readFileSync(new URL('../fixtures/rar/sample-rar5.rar', import.meta.url));
    const messages = await runWorker(data, backend);
    assert.equal(messages.at(-1).type, 'finish', messages.at(-1).msg);
    assert.deepEqual(
      messages
        .filter((message) => message.type === 'extract')
        .map((message) => message.unarchivedFile.filename)
        .sort(),
      ['img/book.png', 'img/loading.gif'],
    );
  },
);

test(
  'RAR worker falls back for a valid non-solid PPMd entry rejected by the primary decoder',
  {
    skip: Number.parseInt(process.versions.node, 10) < 24,
  },
  async () => {
    const backend = await import('@mary/rar');
    const wasmBinary = fs.readFileSync(new URL('../../src/vendor/libarchive/comic_archive.wasm', import.meta.url));
    const decoder = createRar3LibarchiveDecoder(async () => createComicArchive({ wasmBinary }));
    const data = fs.readFileSync(new URL('../fixtures/rar/comic-ppm-rar4.rar', import.meta.url));
    const messages = await runWorker(data, {
      ...backend,
      getRar3FallbackDecoder: () => decoder,
    });

    assert.equal(messages.at(-1).type, 'finish', messages.at(-1).msg);
    assert.equal(messages.at(-1).metadata.backend, '@mary/rar + libarchive');
    const page = messages.find((message) => message.type === 'extract').unarchivedFile;
    assert.equal(page.filename, 'page-01.PNG');
    assert.equal(
      createHash('sha256').update(page.fileData).digest('hex'),
      '146775fc99a489c2242ebe0e2bc2bde5681e0ea9ac552a83a1f351a09a6a77f1',
    );
  },
);

test('RAR worker opens large URL inputs through the bounded range reader', async () => {
  const signature = Uint8Array.from([0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x01, 0x00]);
  const source = readerFromBytes(signature);
  let opened = null;
  const backend = {
    createRarRangeReader: async (url, options) => {
      opened = { options, url };
      return source;
    },
    walkRar3Archive: async function* () {},
    walkRar5Archive: async function* () {},
    unrar: async function* () {},
    unrar3: async function* () {},
  };

  const messages = await runWorker({ type: 'extract-url', url: 'https://cloud.example.test/large.cbr' }, backend);

  assert.equal(messages.at(-1).type, 'finish', messages.at(-1).msg);
  assert.equal(opened.url, 'https://cloud.example.test/large.cbr');
  assert.equal(opened.options.origin, 'https://cloud.example.test');
  assert.equal(opened.options.maxArchiveBytes, globalThis.ReaderLimits.policy.rarArchiveBytes);
});

test('RAR worker size-mismatch recovery decodes the owned fixture with real libarchive', {
  skip: Number.parseInt(process.versions.node, 10) < 24,
}, async () => {
  const backend = await import('@mary/rar');
  const wasmBinary = fs.readFileSync(new URL('../../src/vendor/libarchive/comic_archive.wasm', import.meta.url));
  const decoder = createRar3LibarchiveDecoder(async () => createComicArchive({ wasmBinary }));
  const data = fs.readFileSync(new URL('../fixtures/rar/comic-ppm-rar4.rar', import.meta.url));
  const messages = await runWorker(data, {
    ...backend,
    unrar3: async function* (source) {
      for await (const entry of backend.unrar3(source)) {
        yield {
          compressedSize: entry.compressedSize,
          filename: entry.filename,
          isDirectory: entry.isDirectory,
          isSymlink: entry.isSymlink,
          size: entry.size,
          get body() {
            return failingStream(`RAR3 decompressed size mismatch: got ${entry.size - 1} bytes, expected ${entry.size}`);
          },
        };
      }
    },
    getRar3FallbackDecoder: () => decoder,
  });

  assert.equal(messages.at(-1).type, 'finish', messages.at(-1).msg);
  assert.equal(messages.at(-1).metadata.backend, '@mary/rar + libarchive');
  const page = messages.find((message) => message.type === 'extract').unarchivedFile;
  assert.equal(page.filename, 'page-01.PNG');
  assert.equal(
    createHash('sha256').update(page.fileData).digest('hex'),
    '146775fc99a489c2242ebe0e2bc2bde5681e0ea9ac552a83a1f351a09a6a77f1',
  );
});

test('archive worker extracts large ZIP URL inputs through bounded ranges', async () => {
  const source = readerFromBytes(Uint8Array.of(0x50, 0x4b));
  let opened = null;
  let extractionSource = null;
  const backend = {
    createArchiveRangeReader: async (url, options) => {
      opened = { options, url };
      return source;
    },
    extractZipRange: async (input, limits, sink) => {
      extractionSource = input;
      const fileData = Uint8Array.of(1, 2, 3);
      await sink.extract({ filename: 'page.jpg', fileData });
      await sink.progress({
        currentBytesUnarchived: 2,
        currentBytesUnarchivedInFile: 3,
        currentFilename: 'page.jpg',
        currentFileNumber: 1,
        totalCompressedBytesRead: 2,
        totalFilesInArchive: 1,
        totalUncompressedBytesInArchive: 2,
      });
      return { totalBytes: 3, totalFiles: 1 };
    },
    fromUint8Array: readerFromBytes,
    walkRar3Archive: async function* () {},
    walkRar5Archive: async function* () {},
    unrar: async function* () {},
    unrar3: async function* () {},
  };

  const messages = await runWorker(
    {
      type: 'extract-zip-url',
      url: 'https://cloud.example.test/large.cbz',
    },
    backend,
  );

  assert.equal(messages.at(-1).type, 'finish', messages.at(-1).msg);
  assert.equal(messages.at(-1).metadata.backend, 'bounded ZIP ranges');
  assert.equal(opened.url, 'https://cloud.example.test/large.cbz');
  assert.equal(opened.options.origin, 'https://cloud.example.test');
  assert.equal(opened.options.maxArchiveBytes, globalThis.ReaderLimits.policy.rarArchiveBytes);
  assert.equal(extractionSource, source);
  assert.deepEqual(
    messages.find((message) => message.type === 'extract').unarchivedFile.fileData,
    Uint8Array.of(1, 2, 3),
  );
});

test('RAR worker keeps ordinary compressed RAR3 entries on the primary decoder', async () => {
  const bytes = Uint8Array.from([0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
  const block = rar3Block();
  let fallbackCalls = 0;
  const backend = rar3Backend(bytes, [block], [rar3Entry(block, () => streamBytes(11, 12, 13))], async () => {
    fallbackCalls += 1;
    return { filename: block.name, fileData: Uint8Array.of(21, 22, 23) };
  });

  const messages = await runWorker(bytes, backend);

  assert.equal(messages.at(-1).type, 'finish', messages.at(-1).msg);
  assert.equal(messages.at(-1).metadata.backend, '@mary/rar');
  assert.equal(fallbackCalls, 0);
  assert.deepEqual(
    messages.find((message) => message.type === 'extract').unarchivedFile.fileData,
    Uint8Array.of(11, 12, 13),
  );
});

for (const decoderError of RAR3_FALLBACK_ERRORS) {
  test(`RAR worker falls back for the independent RAR3 decoder failure: ${decoderError}`, async () => {
    const bytes = Uint8Array.from([0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
    const block = rar3Block();
    let nativeReads = 0;
    let fallbackInput = null;
    const backend = rar3Backend(
      bytes,
      [block],
      [
        rar3Entry(block, () => {
          nativeReads += 1;
          return failingStream(decoderError);
        }),
      ],
      async (inputBlock, entryBytes) => {
        fallbackInput = { entryBytes, inputBlock };
        return { filename: block.name, fileData: Uint8Array.of(11, 12, 13) };
      },
    );

    const messages = await runWorker(bytes, backend);

    assert.equal(messages.at(-1).type, 'finish', messages.at(-1).msg);
    assert.equal(messages.at(-1).metadata.backend, '@mary/rar + libarchive');
    assert.equal(nativeReads, 1);
    assert.equal(fallbackInput.inputBlock, block);
    assert.deepEqual(fallbackInput.entryBytes, bytes.slice(7));
    assert.deepEqual(
      messages.find((message) => message.type === 'extract').unarchivedFile.fileData,
      Uint8Array.of(11, 12, 13),
    );
  });
}

test('RAR worker does not hide unrelated RAR3 decoder failures behind the fallback', async () => {
  const bytes = Uint8Array.from([0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
  const block = rar3Block();
  let fallbackCalls = 0;
  const backend = rar3Backend(
    bytes,
    [block],
    [rar3Entry(block, () => failingStream('RAR3 CRC32 mismatch'))],
    async () => {
      fallbackCalls += 1;
      return { filename: block.name, fileData: Uint8Array.of(11, 12, 13) };
    },
  );

  const messages = await runWorker(bytes, backend);

  assert.equal(messages.at(-1).type, 'error');
  assert.match(messages.at(-1).msg, /CRC32 mismatch/);
  assert.equal(fallbackCalls, 0);
});

test('RAR worker does not hide unrelated PPMd failures behind the fallback', async () => {
  const bytes = Uint8Array.from([0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
  const block = rar3Block();
  let fallbackCalls = 0;
  const backend = rar3Backend(
    bytes,
    [block],
    [rar3Entry(block, () => failingStream('RAR3 PPMd: unsupported decoder state'))],
    async () => {
      fallbackCalls += 1;
      return { filename: block.name, fileData: Uint8Array.of(11, 12, 13) };
    },
  );

  const messages = await runWorker(bytes, backend);

  assert.equal(messages.at(-1).type, 'error');
  assert.match(messages.at(-1).msg, /unsupported decoder state/);
  assert.equal(fallbackCalls, 0);
});

for (const decoderError of RAR3_FALLBACK_ERRORS) {
  test(`RAR worker preserves solid-run dependencies after: ${decoderError}`, async () => {
    const bytes = Uint8Array.from([
      0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
    ]);
    const first = rar3Block();
    const second = rar3Block({
      dataOffset: 24,
      fileFlags: 0x0010,
      headerOffset: 17,
      name: 'page-02.jpg',
    });
    let fallbackCalls = 0;
    const backend = rar3Backend(
      bytes,
      [first, second],
      [rar3Entry(first, () => failingStream(decoderError)), rar3Entry(second, () => streamBytes(21, 22, 23))],
      async () => {
        fallbackCalls += 1;
        return { filename: first.name, fileData: Uint8Array.of(11, 12, 13) };
      },
    );

    const messages = await runWorker(bytes, backend);

    assert.equal(messages.at(-1).type, 'error');
    assert.equal(messages.at(-1).msg, decoderError);
    assert.equal(fallbackCalls, 0);
  });
}

for (const decoderError of [
  'RAR3 decompressed size mismatch: got 2 bytes, expected 4',
  'RAR3 decompressed size mismatch: got 3 bytes, expected 3',
  'RAR3 decompressed size mismatch: got 4 bytes, expected 3',
  'RAR3 decompressed size mismatch',
]) {
  test(`RAR worker does not broaden size-mismatch fallback eligibility: ${decoderError}`, async () => {
    const bytes = Uint8Array.from([0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
    const block = rar3Block();
    let fallbackCalls = 0;
    const backend = rar3Backend(bytes, [block], [rar3Entry(block, () => failingStream(decoderError))], async () => {
      fallbackCalls += 1;
      return { filename: block.name, fileData: Uint8Array.of(11, 12, 13) };
    });

    const messages = await runWorker(bytes, backend);

    assert.equal(messages.at(-1).type, 'error');
    assert.equal(messages.at(-1).msg, decoderError);
    assert.equal(fallbackCalls, 0);
  });
}

test('RAR worker does not use the size-mismatch fallback for stored or solid entries', async () => {
  for (const overrides of [{ method: 0x30 }, { fileFlags: 0x0010 }]) {
    const bytes = Uint8Array.from([0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
    const block = rar3Block(overrides);
    let fallbackCalls = 0;
    const backend = rar3Backend(bytes, [block], [rar3Entry(block, () => failingStream(RAR3_SIZE_MISMATCH_ERROR))], async () => {
      fallbackCalls += 1;
      return { filename: block.name, fileData: Uint8Array.of(11, 12, 13) };
    });

    const messages = await runWorker(bytes, backend);

    assert.equal(messages.at(-1).type, 'error');
    assert.equal(messages.at(-1).msg, RAR3_SIZE_MISMATCH_ERROR);
    assert.equal(fallbackCalls, 0);
  }
});

test('RAR worker propagates fallback integrity failures after a primary size mismatch', async () => {
  const bytes = Uint8Array.from([0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
  const block = rar3Block();
  let fallbackCalls = 0;
  const backend = rar3Backend(bytes, [block], [rar3Entry(block, () => failingStream(RAR3_SIZE_MISMATCH_ERROR))], async () => {
    fallbackCalls += 1;
    throw new Error('File CRC error');
  });

  const messages = await runWorker(bytes, backend);

  assert.equal(messages.at(-1).type, 'error');
  assert.equal(messages.at(-1).msg, 'File CRC error');
  assert.equal(fallbackCalls, 1);
  assert.equal(messages.some((message) => message.type === 'extract'), false);
});
