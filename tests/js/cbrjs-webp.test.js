import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const bundlePath = new URL('../../js/cbrjs/cbr.js', import.meta.url);

function mergeInto(target, source) {
  for (const [key, value] of Object.entries(source || {})) {
    if (Array.isArray(value)) {
      target[key] = value.slice();
    } else if (value !== null && typeof value === 'object') {
      const existing = target[key] !== null && typeof target[key] === 'object' ? target[key] : {};
      target[key] = mergeInto(existing, value);
    } else {
      target[key] = value;
    }
  }

  return target;
}

function validImage(filename) {
  if (/\.webp$/i.test(filename)) {
    const data = Buffer.alloc(30);
    data.write('RIFF');
    data.writeUInt32LE(22, 4);
    data.write('WEBPVP8X', 8);
    data.writeUInt32LE(10, 16);
    return data;
  }
  return Buffer.from('ffd8ffc00008080001000101ffd9', 'hex');
}

function createHarness(entries) {
  const revoked = [];
  const blobs = [];
  const books = [];
  const messages = [];
  const eventTypes = {
    ERROR: 'error',
    EXTRACT: 'extract',
    FINISH: 'finish',
    PROGRESS: 'progress',
    START: 'start',
  };

  function createChain(selector) {
    return {
      attr() {
        return this;
      },
      addClass() {
        return this;
      },
      css() {
        return this;
      },
      hide() {
        return this;
      },
      on() {
        return this;
      },
      removeClass() {
        return this;
      },
      show() {
        return this;
      },
      text(value) {
        if (selector === '.message-text' && value !== undefined) {
          messages.push(value);
        }
        return this;
      },
    };
  }

  function jQuery(selector) {
    return createChain(selector);
  }

  jQuery.extend = function (...args) {
    const deep = args[0] === true;
    if (deep) {
      args.shift();
    }

    const target = args.shift() || {};
    for (const source of args) {
      if (deep) {
        mergeInto(target, source);
      } else {
        Object.assign(target, source);
      }
    }

    return target;
  };

  class FakeUnarchiver {
    constructor() {
      this.listeners = new Map();
    }

    addEventListener(type, listener) {
      this.listeners.set(type, listener);
    }

    emit(type, event = {}) {
      this.listeners.get(type)?.(event);
    }

    stop() {}

    start() {
      this.emit(eventTypes.START);
      for (const entry of entries) {
        this.emit(eventTypes.EXTRACT, {
          unarchivedFile: {
            fileData: new Uint8Array(entry.bytes || validImage(entry.filename)),
            filename: entry.filename,
          },
        });
      }
      this.emit(eventTypes.FINISH);
    }
  }

  const context = vm.createContext({
    $: jQuery,
    ArrayBuffer,
    Blob,
    AbortController,
    setTimeout,
    clearTimeout,
    fetch: async () => ({
      ok: true,
      headers: { get: () => '1' },
      body: new ReadableStream({ start(controller) { controller.enqueue(new Uint8Array([1])); controller.close(); } }),
    }),
    Uint8Array,
    addEventListener() {},
    CBRJS: {
      getUnarchiver() {
        return new FakeUnarchiver();
      },
      UnarchiveEventType: eventTypes,
    },
    console,
    document: {
      head: {
        dataset: {
          staticpath: '/apps/epubviewer/',
        },
      },
      title: '',
    },
    jQuery,
    location: {
      hash: '',
      search: '',
    },
    navigator: {
      userAgent: '',
    },
  });

  context.URL = {
    revokeObjectURL(url) { revoked.push(url); },
    createObjectURL(blob) {
      blobs.push(blob);
      return `blob:${blobs.length}`;
    },
  };
  context.window = context;

  vm.runInContext(fs.readFileSync(new URL('../../js/reader-limits.js', import.meta.url), 'utf8'), context);
  vm.runInContext(fs.readFileSync(bundlePath, 'utf8'), context, {
    filename: bundlePath.pathname,
  });

  const OriginalComicBook = context.ComicBook;
  context.ComicBook = function (id, pages) {
    const book = {
      drawCalls: 0,
      pages: Array.from(pages),
      destroy() {},
      draw() {
        this.drawCalls++;
      },
    };
    books.push(book);
    return book;
  };

  new context.CBRJS.Reader('/comic.cbz', {
    session: {
      cursor: { value: 0 },
      defaults: [],
      preferences: [],
    },
  });

  return { blobs, books, context, messages, revoked, OriginalComicBook,
    ready: new Promise(resolve => setImmediate(resolve)) };
}

test('routes JPEG and WebP archive entries to the comic renderer', async () => {
  const result = createHarness([{ filename: '001.JPG' }, { filename: '002.WEBP' }, { filename: 'metadata.xml' }]);
  await result.ready;

  assert.deepEqual(
    result.blobs.map((blob) => blob.type),
    ['image/jpeg', 'image/webp'],
  );
  assert.equal(result.books.length, 1);
  assert.deepEqual(result.books[0].pages, ['blob:1', 'blob:2']);
  assert.equal(result.books[0].drawCalls, 1);
});

test('reports an archive with no supported image pages', async () => {
  const result = createHarness([{ filename: 'README' }, { filename: '001.tiff' }, { filename: 'metadata.xml' }]);
  await result.ready;

  assert.equal(result.blobs.length, 0);
  assert.equal(result.books.length, 0);
  assert.equal(result.messages.at(-1), 'No supported images were found in this comic archive.');
});

test('renders fitted comic pages at the display pixel density', async () => {
  const result = createHarness([]);
  await result.ready;
  const transforms = [];
  const canvas = { height: 0, style: {}, width: 0 };
  const drawingContext = {
    imageSmoothingEnabled: false,
    imageSmoothingQuality: 'low',
    setTransform(...transform) {
      transforms.push(transform);
    },
  };

  result.context.devicePixelRatio = 3;

  const pixelRatio = result.context.CBRJS.configureCanvas(canvas, drawingContext, 390, 844, 0.2);

  assert.equal(pixelRatio, 3);
  assert.equal(canvas.style.width, '390px');
  assert.equal(canvas.style.height, '844px');
  assert.equal(canvas.width, 1170);
  assert.equal(canvas.height, 2532);
  assert.deepEqual(transforms, [[3, 0, 0, 3, 0, 0]]);
  assert.equal(drawingContext.imageSmoothingEnabled, true);
  assert.equal(drawingContext.imageSmoothingQuality, 'high');
});

test('does not allocate a backing canvas larger than the source page can benefit from', async () => {
  const result = createHarness([]);
  await result.ready;
  const canvas = { height: 0, style: {}, width: 0 };
  const drawingContext = {
    setTransform() {},
  };

  result.context.devicePixelRatio = 3;

  const pixelRatio = result.context.CBRJS.configureCanvas(canvas, drawingContext, 1000, 1400, 0.5);

  assert.equal(pixelRatio, 2);
  assert.equal(canvas.width, 2000);
  assert.equal(canvas.height, 2800);
});

test('bounds comic backing canvases even when zoom requests extreme dimensions', () => {
  const { context } = createHarness([]);
  const canvas = { height: 0, style: {}, width: 0 };
  context.CBRJS.configureCanvas(canvas, { setTransform() {} }, 100000, 100000, 1);
  assert.ok(canvas.width * canvas.height <= context.ReaderLimits.policy.canvasPixels);
  assert.ok(canvas.width <= context.ReaderLimits.policy.imageDimension);
});

test('revokes partial comic URLs when a later image header is rejected', async () => {
  const result = createHarness([{ filename: '001.JPG' }, { filename: '002.PNG', bytes: new Uint8Array(100) }]);
  await result.ready;
  assert.equal(result.books.length, 0);
  assert.deepEqual(result.revoked, ['blob:1']);
  assert.match(result.messages.at(-1), /pixel limit/);
});

test('comic preload retains only the nearby page window and releases URLs on destroy', async () => {
  const { context, OriginalComicBook, revoked } = createHarness([]);
  const images = [];
  const errors = [];
  context.Image = class {
    constructor() { this.width = 1000; this.height = 1000; this.value = ''; images.push(this); }
    set src(value) {
      this.value = value;
      // Firefox reports a decode error when src is assigned an empty string.
      queueMicrotask(() => value ? this.onload?.() : this.onerror?.());
    }
    get src() { return this.value; }
    removeAttribute(name) {
      assert.equal(name, 'src');
      this.value = '';
      // Also exercise a queued event after removal: cleanup must detach handlers.
      queueMicrotask(() => this.onerror?.());
    }
  };
  const toc = { children: Array.from({ length: 100 }, () => ({ firstChild: { removeAttribute() {} } })) };
  context.document.getElementById = () => toc;
  context.document.createElement = () => ({ getContext: () => ({}) });
  context.removeEventListener = () => {};
  context.jQuery.each = () => {};
  const pages = Array.from({ length: 100 }, (_, index) => `blob:page-${index}`);
  const blobs = pages.blobs = pages.map(() => new Blob([validImage('page.jpg')]));
  const book = new OriginalComicBook('viewer', pages, { currentPage: 0, displayMode: 'single', error: message => errors.push(message) });
  const drawPage = book.drawPage;
  book.showControl = book.hideControl = book.tocInsert = () => {};
  book.drawPage = function (number) { if (number !== undefined) drawPage.call(this, number); };
  book.preload();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(images.filter(image => image.src).length, 3);
  assert.equal(pages.filter(Boolean).length, 3);
  book.drawPage(80);
  assert.doesNotThrow(() => drawPage.call(book), 'drawing an evicted page must wait for decoding');
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(images.filter(image => image.src).length, 4);
  assert.equal(pages.filter(Boolean).length, 4);
  assert.ok(images.length < pages.length, 'faraway pages must not be decoded');
  assert.deepEqual(errors, [], 'evicting decoded images must not destroy the reader');
  book.controls = {};
  book.destroy();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(images.filter(image => image.src).length, 0);
  assert.equal(revoked.length, 104);
  assert.equal(blobs.length, 0);
  assert.deepEqual(errors, [], 'destroying images must detach pending decode callbacks');
  assert.ok(images.every(image => image.onload === null && image.onerror === null));
});
