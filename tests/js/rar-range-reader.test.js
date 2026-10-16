import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { createRarRangeReader } from '../../src/rarRangeReader.js';

const ORIGIN = 'https://cloud.example.test';
const URL = `${ORIGIN}/remote.php/dav/files/alice/comic.cbr`;

function rangeResponse(start, end, total, bytes = null, status = 206, contentLength = null) {
  const data = bytes ?? Uint8Array.from({ length: end - start + 1 }, (_, index) => (start + index) & 0xff);
  return {
    body: new ReadableStream({
      start(controller) {
        controller.enqueue(data);
        controller.close();
      },
    }),
    headers: new Headers({
      'content-length': String(contentLength ?? data.byteLength),
      'content-range': `bytes ${start}-${end}/${total}`,
    }),
    ok: status >= 200 && status < 300,
    status,
    url: URL,
  };
}

function rangeFetch(total, requests) {
  return async (input, options = {}) => {
    const requestUrl = String(input);
    const range = new Headers(options.headers).get('range');
    requests.push({ options, range, url: requestUrl });
    const match = /^bytes=(\d+)-(\d+)$/.exec(range ?? '');
    assert.ok(match, `Missing byte range: ${range}`);
    return rangeResponse(Number(match[1]), Number(match[2]), total);
  };
}

async function readAll(stream) {
  const chunks = [];
  let length = 0;
  for await (const chunk of stream) {
    chunks.push(chunk);
    length += chunk.byteLength;
  }
  const result = new Uint8Array(length);
  let offset = 0;
  for (const chunk of chunks) {
    result.set(chunk, offset);
    offset += chunk.byteLength;
  }
  return result;
}

describe('bounded RAR HTTP range reader', () => {
  it('opens a 281 MB same-origin archive without downloading it up front', async () => {
    const requests = [];
    const total = 281_218_915;
    const reader = await createRarRangeReader(URL, {
      fetch: rangeFetch(total, requests),
      maxArchiveBytes: 1024 * 1024 * 1024,
      maxFetchedBytes: total + 1024,
      origin: ORIGIN,
    });

    assert.equal(reader.length, total);
    assert.deepEqual(await readAll(await reader.read(254_758_226, 8)), Uint8Array.from([0x52, 0x53, 0x54, 0x55, 0x56, 0x57, 0x58, 0x59]));
    assert.deepEqual(requests.map(({ range }) => range), ['bytes=0-0', 'bytes=254758226-254758233']);
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.equal(requests[0].options.redirect, 'error');
  });

  it('rejects cross-origin input before issuing a request', async () => {
    let called = false;
    await assert.rejects(
      createRarRangeReader('https://attacker.example/file.cbr', {
        fetch: async () => { called = true; },
        maxArchiveBytes: 1024,
        maxFetchedBytes: 2048,
        origin: ORIGIN,
      }),
      /same origin/,
    );
    assert.equal(called, false);
  });

  it('rejects archives above the remote RAR input limit', async () => {
    await assert.rejects(
      createRarRangeReader(URL, {
        fetch: rangeFetch(1025, []),
        maxArchiveBytes: 1024,
        maxFetchedBytes: 2048,
        origin: ORIGIN,
      }),
      /input limit/,
    );
  });

  it('requires a precise 206 Content-Range response', async () => {
    for (const response of [
      rangeResponse(0, 0, 10, Uint8Array.of(0), 200),
      { ...rangeResponse(1, 1, 10), url: URL },
      { ...rangeResponse(0, 0, 10), headers: new Headers({ 'content-length': '1' }) },
    ]) {
      await assert.rejects(
        createRarRangeReader(URL, {
          fetch: async () => response,
          maxArchiveBytes: 1024,
          maxFetchedBytes: 2048,
          origin: ORIGIN,
        }),
        /range response/,
      );
    }
  });

  it('cancels an invalid range response body before failing', async () => {
    let cancelled = false;
    const body = new ReadableStream({
      cancel() {
        cancelled = true;
      },
    });

    await assert.rejects(
      createRarRangeReader(URL, {
        fetch: async () => ({
          body,
          headers: new Headers({
            'content-length': '10',
            'content-range': 'bytes 0-9/10',
          }),
          ok: true,
          status: 200,
          url: URL,
        }),
        maxArchiveBytes: 1024,
        maxFetchedBytes: 2048,
        origin: ORIGIN,
      }),
      /range response/,
    );

    assert.equal(cancelled, true);
  });

  it('rejects short and oversized range bodies', async () => {
    for (const data of [Uint8Array.of(1), Uint8Array.of(1, 2, 3)]) {
      let calls = 0;
      const fetch = async () => {
        calls += 1;
        return calls === 1
          ? rangeResponse(0, 0, 10)
          : rangeResponse(2, 3, 10, data, 206, 2);
      };
      const reader = await createRarRangeReader(URL, {
        fetch,
        maxArchiveBytes: 1024,
        maxFetchedBytes: 2048,
        origin: ORIGIN,
      });
      await assert.rejects(readAll(await reader.read(2, 2)), /range body length/);
    }
  });

  it('bounds cumulative network reads and validates requested offsets', async () => {
    const reader = await createRarRangeReader(URL, {
      fetch: rangeFetch(100, []),
      maxArchiveBytes: 100,
      maxFetchedBytes: 6,
      origin: ORIGIN,
    });
    await readAll(await reader.read(0, 5));
    await assert.rejects(reader.read(5, 1), /fetch limit/);
    await assert.rejects(reader.read(-1, 1), /range/);
    await assert.rejects(reader.read(99, 2), /range/);
    await assert.rejects(reader.read(0, 0), /range/);
  });
});
