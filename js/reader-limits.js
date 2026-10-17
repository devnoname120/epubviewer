/* Shared limits for publication input, archive workers, and retained reader state. */
(function (root) {
  'use strict';
  var policy = Object.freeze({
    archiveBytes: 100 * 1024 * 1024,
    rarArchiveBytes: 1024 * 1024 * 1024,
    rarFetchBytes: 1105 * 1024 * 1024,
    entries: 10000,
    directoryBytes: 16 * 1024 * 1024,
    entryBytes: 64 * 1024 * 1024,
    totalBytes: 512 * 1024 * 1024,
    ratio: 200,
    ratioMinimum: 1024 * 1024,
    documentBytes: 4 * 1024 * 1024,
    pages: 1000,
    imageDimension: 8192,
    imagePixels: 32 * 1024 * 1024,
    totalImagePixels: 64 * 1024 * 1024,
    canvasPixels: 16 * 1024 * 1024,
    textCharacters: 4 * 1024 * 1024,
    pageTextCharacters: 128 * 1024,
    searchResults: 1000,
    highlights: 1000,
    highlightCfiCharacters: 480,
    highlightTextCharacters: 1024,
    highlightContentBytes: 3900,
    highlightRectangles: 4096,
    workMilliseconds: 30000,
  });

  function assert(condition, message) {
    if (!condition) throw new Error(message || 'Publication exceeds reader resource limits.');
  }

  function fail(code, message) {
    var error = new Error(message);
    error.code = code;
    throw error;
  }

  function entry(size, compressed, total) {
    assert(
      Number.isSafeInteger(size) && size >= 0 && size <= policy.entryBytes,
      'Archive entry exceeds the output limit.',
    );
    assert(Number.isSafeInteger(compressed) && compressed >= 0, 'Invalid compressed archive entry size.');
    assert(
      size < policy.ratioMinimum || size <= compressed * policy.ratio,
      'Archive entry exceeds the compression ratio limit.',
    );
    assert(size <= policy.totalBytes - total, 'Archive exceeds the cumulative output limit.');
    return total + size;
  }

  function bytes(data) {
    if (typeof data === 'string') {
      assert(data.length <= policy.archiveBytes, 'Archive exceeds the input limit.');
      return Uint8Array.from(data, function (ch) {
        return ch.charCodeAt(0) & 255;
      });
    }
    return data instanceof ArrayBuffer ? new Uint8Array(data) : data;
  }

  function inspectZip(data) {
    var input = bytes(data);
    assert(
      input && input.byteLength >= 22 && input.byteLength <= policy.archiveBytes,
      'ZIP exceeds the input limit or is truncated.',
    );
    var view = new DataView(input.buffer, input.byteOffset, input.byteLength);
    var end = -1;
    for (var i = view.byteLength - 22; i >= Math.max(0, view.byteLength - 65557); i--) {
      if (view.getUint32(i, true) === 0x06054b50 && i + 22 + view.getUint16(i + 20, true) === view.byteLength) {
        end = i;
        break;
      }
    }
    assert(end >= 0, 'ZIP end record is invalid.');
    for (var later = end + 1; later + 4 <= input.length; later++) {
      assert(view.getUint32(later, true) !== 0x06054b50, 'Ambiguous ZIP end record in archive comment.');
    }
    var count = view.getUint16(end + 10, true);
    var length = view.getUint32(end + 12, true);
    var offset = view.getUint32(end + 16, true);
    assert(
      view.getUint16(end + 4, true) === 0 &&
        view.getUint16(end + 6, true) === 0 &&
        view.getUint16(end + 8, true) === count,
      'Multi-disk ZIP archives are unsupported.',
    );
    // ZIP64 is unnecessary for the permitted sizes; refuse it before legacy parsers run.
    assert(
      count !== 65535 && length !== 0xffffffff && offset !== 0xffffffff,
      'ZIP64 archives are unsupported by the interactive reader.',
    );
    assert(
      count <= policy.entries && length <= policy.directoryBytes && offset + length === end,
      'ZIP central directory exceeds limits or is inconsistent.',
    );
    var entries = new Map();
    var total = 0;
    while (offset < end) {
      assert(
        entries.size < policy.entries && offset + 46 <= end && view.getUint32(offset, true) === 0x02014b50,
        'Invalid ZIP central directory.',
      );
      var compressed = view.getUint32(offset + 20, true);
      var size = view.getUint32(offset + 24, true);
      var local = view.getUint32(offset + 42, true);
      var flags = view.getUint16(offset + 8, true);
      var method = view.getUint16(offset + 10, true);
      assert(
        view.getUint16(offset + 34, true) === 0 && !(flags & 1) && (method === 0 || method === 8),
        'Unsupported ZIP entry.',
      );
      assert(method !== 0 || size === compressed, 'Stored ZIP entry has inconsistent sizes.');
      assert(
        !entries.has(local) && local + 30 <= end && view.getUint32(local, true) === 0x04034b50,
        'Invalid ZIP local header.',
      );
      var start = local + 30 + view.getUint16(local + 26, true) + view.getUint16(local + 28, true);
      assert(
        start + compressed <= end &&
          view.getUint16(local + 6, true) === flags &&
          view.getUint16(local + 8, true) === method,
        'Inconsistent ZIP local header.',
      );
      if (!(flags & 8)) {
        assert(
          view.getUint32(local + 18, true) === compressed && view.getUint32(local + 22, true) === size,
          'Inconsistent ZIP entry size.',
        );
      }
      total = entry(size, compressed, total);
      entries.set(local, {
        size: size,
        compressed: compressed,
        flags: flags,
        method: method,
        crc: view.getUint32(offset + 16, true),
      });
      offset +=
        46 + view.getUint16(offset + 28, true) + view.getUint16(offset + 30, true) + view.getUint16(offset + 32, true);
    }
    assert(offset === end && entries.size === count, 'Inconsistent ZIP entry count.');
    return entries;
  }

  async function readStream(stream, maximum, signal, progress) {
    var reader = stream.getReader();
    var chunks = [],
      total = 0,
      complete = false;
    var cancel = function () {
      reader.cancel().catch(function () {});
    };
    if (signal) signal.addEventListener('abort', cancel, { once: true });
    try {
      while (true) {
        assert(!signal || !signal.aborted, 'Publication loading was cancelled.');
        var item = await reader.read();
        if (item.done) {
          complete = true;
          break;
        }
        assert(item.value.byteLength <= maximum - total, 'Publication stream exceeds the byte limit.');
        total += item.value.byteLength;
        if (progress) progress(total);
        chunks.push(item.value);
      }
      assert(!signal || !signal.aborted, 'Publication loading was cancelled.');
      var output = new Uint8Array(total),
        offset = 0;
      chunks.forEach(function (chunk) {
        output.set(chunk, offset);
        offset += chunk.byteLength;
      });
      return output;
    } finally {
      if (signal) signal.removeEventListener('abort', cancel);
      if (!complete) await reader.cancel().catch(function () {});
      reader.releaseLock();
    }
  }

  async function download(url, signal, progress) {
    var controller, timer;
    if (!signal) {
      controller = new AbortController();
      signal = controller.signal;
      timer = setTimeout(function () {
        controller.abort();
      }, policy.workMilliseconds);
    }
    try {
      var response = await fetch(url, { signal: signal, credentials: 'same-origin' });
      if (!response.ok || !response.body) {
        if (response.body) await response.body.cancel().catch(function () {});
        assert(false, 'Publication download failed or exceeds the input limit.');
      }
      var length = Number(response.headers.get('content-length') || 0);
      if (length > policy.archiveBytes) {
        await response.body.cancel().catch(function () {});
        fail('ARCHIVE_INPUT_LIMIT', 'Publication download failed or exceeds the input limit.');
      }
      try {
        return (
          await readStream(
            response.body,
            policy.archiveBytes,
            signal,
            progress &&
              function (loaded) {
                progress(loaded, length);
              },
          )
        ).buffer;
      } catch (error) {
        if (error && error.message === 'Publication stream exceeds the byte limit.') {
          fail('ARCHIVE_INPUT_LIMIT', 'Publication download failed or exceeds the input limit.');
        }
        throw error;
      }
    } finally {
      clearTimeout(timer);
    }
  }

  function dimensions(width, height) {
    assert(
      Number.isSafeInteger(width) &&
        Number.isSafeInteger(height) &&
        width > 0 &&
        height > 0 &&
        width <= policy.imageDimension &&
        height <= policy.imageDimension &&
        width * height <= policy.imagePixels,
      'Image exceeds the decoded pixel limit.',
    );
    return width * height;
  }

  function documentPages(count) {
    assert(Number.isSafeInteger(count) && count > 0 && count <= policy.pages, 'PDF exceeds the page-count limit.');
  }

  function image(data) {
    var input = bytes(data);
    assert(input && input.byteLength >= 10 && input.byteLength <= policy.entryBytes, 'Invalid comic image.');
    var view = new DataView(input.buffer, input.byteOffset, input.byteLength);
    var width, height, offset, tag;
    if (view.byteLength >= 24 && view.getUint32(0) === 0x89504e47 && view.getUint32(4) === 0x0d0a1a0a) {
      assert(view.getUint32(8) === 13 && view.getUint32(12) === 0x49484452, 'Invalid PNG dimension header.');
      width = view.getUint32(16);
      height = view.getUint32(20);
      for (offset = 8; offset < view.byteLength; ) {
        assert(offset + 12 <= view.byteLength, 'Truncated PNG image.');
        assert(offset === 8 || view.getUint32(offset + 4) !== 0x49484452, 'Duplicate PNG dimension header.');
        assert(view.getUint32(offset + 4) !== 0x6163544c, 'Animated comic images are unsupported.');
        offset += 12 + view.getUint32(offset);
        assert(offset <= view.byteLength, 'Invalid PNG image chunk.');
      }
    } else if (view.getUint16(0) === 0xffd8) {
      offset = 2;
      while (offset + 4 <= view.byteLength) {
        assert(input[offset++] === 255, 'Invalid JPEG header.');
        while (input[offset] === 255) offset++;
        tag = input[offset++];
        if (tag === 0xd9 || tag === 0xda) break;
        if (tag === 1 || (tag >= 0xd0 && tag <= 0xd7)) continue;
        var segment = view.getUint16(offset);
        assert(segment >= 2 && offset + segment <= view.byteLength, 'Truncated JPEG header.');
        if ([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf].indexOf(tag) >= 0) {
          assert(segment >= 8, 'Invalid JPEG dimensions.');
          height = view.getUint16(offset + 3);
          width = view.getUint16(offset + 5);
          break;
        }
        offset += segment;
      }
    } else if (view.byteLength >= 13 && view.getUint32(0) >>> 8 === 0x474946) {
      width = view.getUint16(6, true);
      height = view.getUint16(8, true);
      offset = 13 + (input[10] & 128 ? 3 * (1 << ((input[10] & 7) + 1)) : 0);
      var frames = 0,
        trailer = false;
      while (offset < input.length) {
        tag = input[offset++];
        if (tag === 0x3b) {
          trailer = true;
          break;
        }
        if (tag === 0x2c) {
          assert(++frames === 1 && offset + 9 < input.length, 'Animated or malformed GIF image.');
          assert(
            view.getUint16(offset + 4, true) > 0 &&
              view.getUint16(offset + 6, true) > 0 &&
              view.getUint16(offset, true) + view.getUint16(offset + 4, true) <= width &&
              view.getUint16(offset + 2, true) + view.getUint16(offset + 6, true) <= height,
            'GIF frame exceeds the image dimensions.',
          );
          offset += 9 + (input[offset + 8] & 128 ? 3 * (1 << ((input[offset + 8] & 7) + 1)) : 0) + 1;
        } else {
          assert(tag === 0x21, 'Invalid GIF image.');
          offset++;
        }
        while (offset < input.length) {
          var block = input[offset++];
          if (!block) break;
          offset += block;
        }
      }
      assert(trailer && frames === 1, 'Truncated GIF image.');
    } else if (view.byteLength >= 20 && view.getUint32(0) === 0x52494646 && view.getUint32(8) === 0x57454250) {
      assert(view.getUint32(4, true) + 8 === view.byteLength, 'Invalid WebP container size.');
      var frames = 0;
      for (offset = 12; offset < view.byteLength; ) {
        assert(offset + 8 <= view.byteLength, 'Truncated WebP chunk.');
        var length = view.getUint32(offset + 4, true);
        tag = view.getUint32(offset);
        assert(tag !== 0x414e494d && tag !== 0x414e4d46, 'Animated WebP images are unsupported.');
        if (tag === 0x56503820 || tag === 0x5650384c) {
          assert(++frames === 1, 'Multiple WebP frames are unsupported.');
          var frameWidth, frameHeight;
          if (tag === 0x5650384c) {
            assert(
              length >= 5 && offset + 13 <= view.byteLength && input[offset + 8] === 0x2f,
              'Invalid WebP lossless header.',
            );
            var frameBits = view.getUint32(offset + 9, true);
            frameWidth = 1 + (frameBits & 0x3fff);
            frameHeight = 1 + ((frameBits >>> 14) & 0x3fff);
          } else {
            assert(
              length >= 10 &&
                offset + 18 <= view.byteLength &&
                input[offset + 11] === 0x9d &&
                input[offset + 12] === 1 &&
                input[offset + 13] === 0x2a,
              'Invalid WebP frame header.',
            );
            frameWidth = view.getUint16(offset + 14, true) & 0x3fff;
            frameHeight = view.getUint16(offset + 16, true) & 0x3fff;
          }
          dimensions(frameWidth, frameHeight);
        }
        offset += 8 + length + (length % 2);
        assert(offset <= view.byteLength, 'Invalid WebP chunk length.');
      }
      tag = view.getUint32(12);
      if (tag === 0x56503858) {
        assert(view.byteLength >= 30 && view.getUint32(16, true) >= 10, 'Truncated WebP dimensions.');
        assert(!(input[20] & 2), 'Animated WebP images are unsupported.');
        width = 1 + input[24] + (input[25] << 8) + (input[26] << 16);
        height = 1 + input[27] + (input[28] << 8) + (input[29] << 16);
      } else if (tag === 0x5650384c) {
        assert(view.byteLength >= 25, 'Truncated WebP dimensions.');
        assert(input[20] === 0x2f, 'Invalid WebP image.');
        var bits = view.getUint32(21, true);
        width = 1 + (bits & 0x3fff);
        height = 1 + ((bits >>> 14) & 0x3fff);
      } else if (tag === 0x56503820) {
        assert(
          view.byteLength >= 30 && input[23] === 0x9d && input[24] === 1 && input[25] === 0x2a,
          'Invalid WebP image.',
        );
        width = view.getUint16(26, true) & 0x3fff;
        height = view.getUint16(28, true) & 0x3fff;
      }
      assert(
        frameWidth === undefined || (frameWidth === width && frameHeight === height),
        'WebP frame differs from its canvas dimensions.',
      );
    }
    return dimensions(width, height);
  }

  root.ReaderLimits = Object.freeze({
    policy: policy,
    assert: assert,
    entry: entry,
    inspectZip: inspectZip,
    readStream: readStream,
    download: download,
    dimensions: dimensions,
    image: image,
    documentPages: documentPages,
  });
})(globalThis);
