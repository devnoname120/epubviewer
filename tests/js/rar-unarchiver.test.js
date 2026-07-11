import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  createComicUnarchiver,
  isRarArchive,
  MaryRarUnarchiver,
} from '../../src/rarUnarchiver.js';

const RAR3_SIGNATURE = [0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00];
const RAR5_SIGNATURE = [0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x01, 0x00];

function archiveBuffer(signature = RAR5_SIGNATURE) {
  return Uint8Array.from([...signature, 0xaa, 0xbb]).buffer;
}

class FakeWorker extends EventTarget {
  constructor(messages) {
    super();
    this.messages = messages;
    this.posted = [];
    this.terminated = false;
  }

  postMessage(message, transfer) {
    this.posted.push({ message, transfer });
    queueMicrotask(() => {
      for (const data of this.messages) {
        this.dispatchEvent(new MessageEvent('message', { data }));
      }
    });
  }

  terminate() {
    this.terminated = true;
  }
}

describe('@mary/rar unarchiver adapter', () => {
  it('recognizes both supported RAR signatures', () => {
    assert.equal(isRarArchive(archiveBuffer(RAR3_SIGNATURE)), true);
    assert.equal(isRarArchive(archiveBuffer(RAR5_SIGNATURE)), true);
    assert.equal(isRarArchive(Uint8Array.from([0x50, 0x4b, 0x03, 0x04]).buffer), false);
  });

  it('always uses @mary/rar for RAR archives', () => {
    let nonRarFactoryCalled = false;
    for (const signature of [RAR3_SIGNATURE, RAR5_SIGNATURE]) {
      const selected = createComicUnarchiver(archiveBuffer(signature), {
        nonRarFactory: () => {
          nonRarFactoryCalled = true;
        },
        workerFactory: () => new FakeWorker([]),
      });

      assert.ok(selected instanceof MaryRarUnarchiver);
    }
    assert.equal(nonRarFactoryCalled, false);
  });

  it('keeps non-RAR archives on their existing BitJS backends', () => {
    const bitjs = {};
    const selected = createComicUnarchiver(Uint8Array.from([0x50, 0x4b, 0x03, 0x04, 0, 0, 0, 0, 0, 0]).buffer, {
      nonRarFactory: () => bitjs,
    });

    assert.equal(selected, bitjs);
  });

  it('translates worker messages into the existing archive event API', async () => {
    const page = Uint8Array.from([1, 2, 3]);
    const worker = new FakeWorker([
      { type: 'start' },
      { type: 'extract', unarchivedFile: { filename: 'page.jpg', fileData: page } },
      {
        type: 'progress',
        currentBytesUnarchived: 3,
        totalUncompressedBytesInArchive: 3,
      },
      { type: 'finish', metadata: { backend: '@mary/rar' } },
    ]);
    const unarchiver = new MaryRarUnarchiver(archiveBuffer(), {
      workerFactory: () => worker,
    });
    const events = [];

    for (const type of ['start', 'extract', 'progress', 'finish']) {
      unarchiver.addEventListener(type, (event) => events.push(event));
    }

    await unarchiver.start();

    assert.deepEqual(events.map((event) => event.type), ['start', 'extract', 'progress', 'finish']);
    assert.equal(events[1].unarchivedFile.filename, 'page.jpg');
    assert.deepEqual(events[1].unarchivedFile.fileData, page);
    assert.equal(events[2].currentBytesUnarchived, 3);
    assert.equal(events[3].metadata.backend, '@mary/rar');
    assert.equal(worker.posted.length, 1);
    assert.equal(worker.posted[0].message.type, 'extract');
    assert.deepEqual(worker.posted[0].transfer, [worker.posted[0].message.arrayBuffer]);
    assert.equal(worker.terminated, true);
  });

  it('reports worker extraction failures through events and the start promise', async () => {
    const worker = new FakeWorker([{ type: 'error', msg: 'Malformed RAR archive' }]);
    const unarchiver = new MaryRarUnarchiver(archiveBuffer(), {
      workerFactory: () => worker,
    });
    let errorMessage = '';
    unarchiver.addEventListener('error', (event) => {
      errorMessage = event.msg;
    });

    await assert.rejects(unarchiver.start(), /Malformed RAR archive/);

    assert.equal(errorMessage, 'Malformed RAR archive');
    assert.equal(worker.terminated, true);
  });

  it('cleans up when the browser refuses to start the worker', async () => {
    const worker = new FakeWorker([]);
    worker.postMessage = () => {
      throw new Error('Worker blocked');
    };
    const unarchiver = new MaryRarUnarchiver(archiveBuffer(), {
      workerFactory: () => worker,
    });

    await assert.rejects(unarchiver.start(), /Worker blocked/);

    assert.equal(worker.terminated, true);
  });
});
