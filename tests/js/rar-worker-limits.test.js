import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import '../../js/reader-limits.js';

const workerSource = ts
  .transpileModule(fs.readFileSync(new URL('../../src/rarWorker.ts', import.meta.url), 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.ES2022, target: ts.ScriptTarget.ES2022 },
  })
  .outputText.replace(/^import .*;\n/gm, '');

async function runWorker(bytes, backend) {
  let listener;
  const messages = [];
  let complete;
  const result = new Promise((resolve) => {
    complete = resolve;
  });
  const scope = {
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
    self: scope,
    ReaderLimits: globalThis.ReaderLimits,
    ArrayBuffer,
    Uint8Array,
    ReadableStream,
    Error,
    console,
  });
  listener({
    data: { type: 'extract', arrayBuffer: bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) },
  });
  return result;
}

function metadataBackend(block) {
  let extractionStarted = false;
  const walk = async function* () {
    yield { block };
  };
  return {
    fromUint8Array: (bytes) => bytes,
    walkRar3Archive: walk,
    walkRar5Archive: walk,
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
