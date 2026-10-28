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

function createHarness(entries, options = {}) {
  const revoked = [];
  const blobs = [];
  const books = [];
  const messages = [];
  const fetchCalls = [];
  const rarUrls = [];
  const zipUrls = [];
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

  const cbrjs = {
    UnarchiveEventType: eventTypes,
  };
  const installArchiveSupport = () => {
    cbrjs.getUnarchiver = function () {
      return new FakeUnarchiver();
    };
    cbrjs.getRarUnarchiver = function (url) {
      rarUrls.push(url);
      return new FakeUnarchiver();
    };
    cbrjs.getZipUnarchiver = function (url) {
      zipUrls.push(url);
      return new FakeUnarchiver();
    };
  };
  if (options.deferArchiveSupport) {
    cbrjs.archiveReady = new Promise((resolve) => {
      setImmediate(() => {
        installArchiveSupport();
        resolve();
      });
    });
  } else {
    installArchiveSupport();
    cbrjs.archiveReady = Promise.resolve();
  }

  const context = vm.createContext({
    $: jQuery,
    ArrayBuffer,
    Blob,
    AbortController,
    setTimeout,
    clearTimeout,
    fetch: async (...args) => {
      fetchCalls.push(args);
      return ({
      ok: options.fetchOk ?? true,
      headers: {
        get(name) {
          return name.toLowerCase() === 'content-length'
            ? String(options.contentLength ?? 1)
            : null;
        },
      },
      body: new ReadableStream({
        start(controller) {
          controller.enqueue(new Uint8Array([1]));
          controller.close();
        },
      }),
      });
    },
    Uint8Array,
    addEventListener() {},
    CBRJS: cbrjs,
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
      blobs: Array.from(pages.blobs || []),
      pixels: Array.from(pages.pixels || []),
      destroy() {},
      draw() {
        this.drawCalls++;
      },
    };
    books.push(book);
    return book;
  };

  new context.CBRJS.Reader(options.path ?? '/comic.cbz', {
    session: {
      cursor: { value: 0 },
      defaults: [],
      format: options.format ?? 'application/x-cbz',
      preferences: [],
    },
  });

  return { blobs, books, context, fetchCalls, messages, rarUrls, revoked, zipUrls, OriginalComicBook,
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

test('passes validated source pixel counts into the comic cache', async () => {
  const result = createHarness([{
    bytes: Buffer.from('ffd8ffc0000808186a12aa01ffd9', 'hex'),
    filename: '001.JPG',
  }]);
  await result.ready;

  assert.deepEqual(result.books[0].pixels, [29_862_500]);
});

for (const format of ['cbr', 'cbz']) {
  test(`naturally orders ${format} pages with their blobs and validated dimensions`, async () => {
    const page = number => {
      const bytes = validImage('page.jpg');
      bytes.writeUInt16BE(number, 9);
      return { filename: `comic/Page ${number}.jpg`, bytes };
    };
    const result = createHarness([page(25), page(1), page(10), page(2)], { format });
    await result.ready;

    assert.deepEqual(result.books[0].pages, ['blob:2', 'blob:4', 'blob:3', 'blob:1']);
    assert.deepEqual(result.books[0].pixels, [1, 2, 10, 25]);
    assert.deepEqual(result.books[0].blobs, [result.blobs[1], result.blobs[3], result.blobs[2], result.blobs[0]]);
  });
}

test('closing the comic sidebar leaves the toolbar available for hover', async () => {
  const { context, OriginalComicBook } = createHarness([]);
  context.document.createElement = () => ({ getContext: () => ({}) });
  context.document.getElementById = () => ({ children: [] });
  const book = new OriginalComicBook('viewer', [], {});
  let toggles = 0;
  book.toggleToolbar = () => { toggles++; };
  book.hideControl = () => {};
  book.closeSidebar();
  assert.equal(toggles, 0, 'sidebar close must not toggle the toolbar to display:none');
  book.isMobile = true;
  book.closeSidebar();
  assert.equal(toggles, 1, 'preserve the existing mobile touch-to-toggle behavior');
});

async function createThumbnailHarness({ highResolution = false, retainPages = false, deferSources = false } = {}) {
  const harness = createHarness([]);
  await harness.ready;
  const { context, OriginalComicBook } = harness;
  const viewport = {
    scrollTop: 0,
    listeners: {},
    addEventListener(event, callback) { this.listeners[event] = callback; },
    removeEventListener(event) { delete this.listeners[event]; },
    getBoundingClientRect: () => ({ top: 0, bottom: 300, height: 300 }),
  };
  const toc = { children: [], parentNode: viewport, appendChild(item) { item.parentNode = this; this.children.push(item); } };
  const images = [];
  context.Image = class {
    constructor() { this.value = ''; images.push(this); }
    set src(value) {
      this.value = value;
      const size = /^data:(\d+)x(\d+)$/.exec(value);
      this.width = this.naturalWidth = size ? Number(size[1]) : highResolution ? 4778 : 100;
      this.height = this.naturalHeight = size ? Number(size[2]) : highResolution ? 6250 : 100;
      if (!deferSources || !value.startsWith('blob:')) queueMicrotask(() => value ? this.onload?.() : this.onerror?.());
    }
    get src() { return this.value; }
    removeAttribute() { this.value = ''; queueMicrotask(() => this.onerror?.()); }
  };
  context.document.createElement = type => type === 'canvas' ? {
    getContext: () => ({ fillRect() {}, clearRect() {}, drawImage() {} }),
    toDataURL() { return `data:${this.width}x${this.height}`; },
  } : {
    children: [],
    listeners: {},
    appendChild(child) { child.parentNode = this; this.children.push(child); this.firstChild = this.children[0]; },
    replaceChild(child, previous) {
      this.children[this.children.indexOf(previous)] = child;
      child.parentNode = this;
      this.firstChild = this.children[0];
    },
    setAttribute(name, value) { this[name] = value; },
    addEventListener(event, callback) { this.listeners[event] = callback; },
    getBoundingClientRect() {
      const top = (Number(this.id.slice(5)) - 1) * 200 - viewport.scrollTop;
      return { top, bottom: top + 200, height: 200 };
    },
  };
  context.document.getElementById = id => id === 'toc' ? toc : toc.children.find(item => item.id === id);
  context.removeEventListener = () => {};
  context.jQuery.each = () => {};
  const sources = Array.from({ length: 47 }, (_, index) => `blob:page-${index}`);
  sources.blobs = sources.map(() => new Blob([validImage('page.jpg')]));
  sources.pixels = sources.map(() => highResolution ? 29_862_500 : 10000);
  const errors = [];
  const navigation = [];
  const book = new OriginalComicBook('viewer', sources, { displayMode: 'double', thumbnails: true, error: message => errors.push(message) });
  book.showControl = book.hideControl = book.scrollToc = () => {};
  book.drawPage = number => { navigation.push(number); };
  book.tocCreate(sources.length);
  if (retainPages) {
    book.preload();
    await new Promise(resolve => setImmediate(resolve));
  }
  return { ...harness, book, toc, viewport, sources, images, errors, navigation };
}

test('sidebar thumbnails load beyond the page cache and release offscreen previews', async () => {
  const { book, toc, viewport, images, errors, navigation } = await createThumbnailHarness();
  book.openSidebar();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(toc.children[0].firstChild.naturalWidth, 200);
  assert.equal(toc.children[1].firstChild.naturalWidth, 200);
  assert.equal(toc.children[9].firstChild.naturalWidth, 5);

  viewport.scrollTop = 1800;
  viewport.listeners.scroll();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(toc.children[9].firstChild.naturalWidth, 200);
  assert.equal(toc.children[10].firstChild.naturalWidth, 200);
  assert.equal(toc.children[0].firstChild.naturalWidth, 5);
  assert.equal(images.filter(image => image.src.startsWith('blob:')).length, 0, 'temporary full-size thumbnail sources must be released');

  // The click handler was bound once, even before its thumbnail was decoded.
  assert.equal(typeof toc.children[46].listeners.click, 'function');
  toc.children[46].listeners.click();
  assert.deepEqual(navigation, [47]);
  book.closeSidebar();
  assert.equal(toc.children[9].firstChild.naturalWidth, 5);
  assert.deepEqual(errors, []);
  book.controls = {};
  book.destroy();
  assert.equal(viewport.listeners.scroll, undefined);
});

test('closing the sidebar cancels a pending thumbnail decode and releases its URL', async () => {
  const { book, viewport, images, revoked, errors } = await createThumbnailHarness({ deferSources: true });
  book.openSidebar();
  const pending = images.find(image => image.src.startsWith('blob:'));
  assert.ok(pending);
  const url = pending.src;
  book.closeSidebar();
  assert.equal(pending.src, '');
  assert.equal(pending.onload, null);
  assert.equal(pending.onerror, null);
  assert.ok(revoked.includes(url));
  assert.deepEqual(errors, []);
  book.controls = {};
  book.destroy();
  assert.equal(viewport.listeners.scroll, undefined);
});

test('thumbnail decoding cannot exceed the retained image budget beside a high-resolution spread', async () => {
  const { book, viewport, images, errors } = await createThumbnailHarness({ highResolution: true, retainPages: true });
  const sources = () => images.filter(image => image.src.startsWith('blob:')).length;
  assert.equal(sources(), 2);
  viewport.scrollTop = 1800;
  book.openSidebar();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(sources(), 2, 'a third 30-megapixel source must not be decoded for the sidebar');
  assert.deepEqual(errors, []);
  book.closeSidebar();
  book.controls = {};
  book.destroy();
});

test('reports an archive with no supported image pages', async () => {
  const result = createHarness([{ filename: 'README' }, { filename: '001.tiff' }, { filename: 'metadata.xml' }]);
  await result.ready;

  assert.equal(result.blobs.length, 0);
  assert.equal(result.books.length, 0);
  assert.equal(result.messages.at(-1), 'No supported images were found in this comic archive.');
});

test('streams RAR comics through the range worker instead of buffering the publication', async () => {
  const result = createHarness([{ filename: '001.JPG' }], {
    format: 'cbr',
    path: '/comic.cbr',
  });
  await result.ready;

  assert.deepEqual(result.rarUrls, ['/comic.cbr']);
  assert.equal(result.fetchCalls.length, 0);
  assert.equal(result.books.length, 1);
});

test('waits for asynchronous archive backends before starting a direct RAR URL', async () => {
  const result = createHarness([{ filename: '001.JPG' }], {
    deferArchiveSupport: true,
    format: 'cbr',
    path: '/delayed.cbr',
  });
  await result.ready;

  assert.deepEqual(result.rarUrls, ['/delayed.cbr']);
  assert.equal(result.fetchCalls.length, 0);
  assert.equal(result.books.length, 1);
});

test('waits for asynchronous archive backends before opening a buffered CBZ', async () => {
  const result = createHarness([{ filename: '001.JPG' }], {
    deferArchiveSupport: true,
    format: 'cbz',
    path: '/delayed.cbz',
  });
  await result.ready;

  assert.equal(result.fetchCalls.length, 1);
  assert.deepEqual(result.zipUrls, []);
  assert.equal(result.books.length, 1);
});

test('falls back to bounded ZIP ranges when a CBZ exceeds the buffered input limit', async () => {
  const result = createHarness([{ filename: '001.JPG' }], {
    contentLength: 100 * 1024 * 1024 + 1,
    deferArchiveSupport: true,
    format: 'cbz',
    path: '/large.cbz',
  });
  await result.ready;

  assert.equal(result.fetchCalls.length, 1);
  assert.deepEqual(result.zipUrls, ['/large.cbz']);
  assert.equal(result.books.length, 1);
});

test('does not hide non-limit CBZ download failures behind the range fallback', async () => {
  const result = createHarness([], {
    fetchOk: false,
    format: 'cbz',
    path: '/unavailable.cbz',
  });
  await result.ready;

  assert.deepEqual(result.zipUrls, []);
  assert.equal(result.books.length, 0);
  assert.match(result.messages.at(-1), /download failed/);
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
  context.scroll = () => {};
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

test('comic preload retains a 30-megapixel double-page spread without decoding a third page', async () => {
  const { context, OriginalComicBook } = createHarness([]);
  const images = [];
  const errors = [];
  context.Image = class {
    constructor() {
      this.height = 6250;
      this.naturalHeight = 6250;
      this.naturalWidth = 4778;
      this.value = '';
      this.width = 4778;
      images.push(this);
    }
    set src(value) {
      this.value = value;
      queueMicrotask(() => value ? this.onload?.() : this.onerror?.());
    }
    get src() { return this.value; }
    removeAttribute(name) {
      assert.equal(name, 'src');
      this.value = '';
      queueMicrotask(() => this.onerror?.());
    }
  };
  const toc = { children: Array.from({ length: 10 }, () => ({ firstChild: { removeAttribute() {} } })) };
  context.document.getElementById = () => toc;
  context.document.createElement = () => ({ getContext: () => ({}) });
  context.removeEventListener = () => {};
  context.scroll = () => {};
  context.jQuery.each = () => {};
  const pages = Array.from({ length: 10 }, (_, index) => `blob:page-${index}`);
  pages.blobs = pages.map(() => new Blob([validImage('page.jpg')]));
  pages.pixels = pages.map(() => 29_862_500);
  const book = new OriginalComicBook('viewer', pages, {
    currentPage: 0,
    displayMode: 'double',
    error: message => errors.push(message),
  });
  const drawPage = book.drawPage;
  book.showControl = book.hideControl = book.tocInsert = () => {};
  book.drawPage = function (number) { if (number !== undefined) drawPage.call(this, number); };

  book.preload();
  await new Promise(resolve => setImmediate(resolve));

  assert.deepEqual(errors, []);
  assert.equal(images.length, 2);
  assert.equal(images.filter(image => image.src).length, 2);
  assert.ok(2 * 29_862_500 <= context.ReaderLimits.policy.totalImagePixels);

  book.drawPage(3);
  await new Promise(resolve => setImmediate(resolve));

  assert.deepEqual(errors, []);
  assert.equal(images.length, 4);
  assert.equal(images.filter(image => image.src).length, 2);

  const drawPrevPage = book.drawPrevPage;
  let previousCalls = 0;
  book.drawPrevPage = function () {
    previousCalls++;
    return drawPrevPage.call(this);
  };
  book.drawPrevPage();
  await new Promise(resolve => setImmediate(resolve));

  assert.deepEqual(errors, []);
  assert.equal(previousCalls, 2);
  assert.equal(images.length, 5);
  assert.equal(images.filter(image => image.src).length, 2);
});

test('comic navigation preserves a requested 30-megapixel page under the aggregate budget', async () => {
  const { context, OriginalComicBook } = createHarness([]);
  const images = [];
  const errors = [];
  context.Image = class {
    constructor() {
      this.height = 6250;
      this.naturalHeight = 6250;
      this.naturalWidth = 4778;
      this.value = '';
      this.width = 4778;
      images.push(this);
    }
    set src(value) {
      this.value = value;
      queueMicrotask(() => value ? this.onload?.() : this.onerror?.());
    }
    get src() { return this.value; }
    removeAttribute(name) {
      assert.equal(name, 'src');
      this.value = '';
      queueMicrotask(() => this.onerror?.());
    }
  };
  const toc = { children: Array.from({ length: 10 }, () => ({ firstChild: { removeAttribute() {} } })) };
  context.document.getElementById = () => toc;
  context.document.createElement = () => ({ getContext: () => ({}) });
  context.removeEventListener = () => {};
  context.scroll = () => {};
  context.jQuery.each = () => {};
  const pages = Array.from({ length: 10 }, (_, index) => `blob:page-${index}`);
  pages.blobs = pages.map(() => new Blob([validImage('page.jpg')]));
  pages.pixels = pages.map(() => 29_862_500);
  const book = new OriginalComicBook('viewer', pages, {
    currentPage: 0,
    displayMode: 'single',
    error: message => errors.push(message),
  });
  book.showControl = book.hideControl = book.tocInsert = () => {};
  book.drawPage = () => {};

  book.preload();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(images.length, 2);

  const drawNextPage = book.drawNextPage;
  let nextCalls = 0;
  book.drawNextPage = function () {
    nextCalls++;
    return drawNextPage.call(this);
  };
  book.drawNextPage();
  nextCalls = 0;
  book.drawNextPage();
  await new Promise(resolve => setImmediate(resolve));

  assert.deepEqual(errors, []);
  assert.equal(nextCalls, 2);
  assert.equal(images.length, 3);
  assert.equal(images.filter(image => image.src).length, 2);
});

test('accepts browser-downsampled pages below the validated source pixel count', async () => {
  const { context, OriginalComicBook } = createHarness([]);
  const images = [];
  const errors = [];
  context.Image = class {
    constructor() {
      this.height = 3125;
      this.naturalHeight = 3125;
      this.naturalWidth = 2389;
      this.value = '';
      this.width = 2389;
      images.push(this);
    }
    set src(value) {
      this.value = value;
      queueMicrotask(() => value ? this.onload?.() : this.onerror?.());
    }
    get src() { return this.value; }
    removeAttribute(name) {
      assert.equal(name, 'src');
      this.value = '';
      queueMicrotask(() => this.onerror?.());
    }
  };
  const toc = { children: Array.from({ length: 3 }, () => ({ firstChild: { removeAttribute() {} } })) };
  context.document.getElementById = () => toc;
  context.document.createElement = () => ({ getContext: () => ({}) });
  context.removeEventListener = () => {};
  context.jQuery.each = () => {};
  const pages = Array.from({ length: 3 }, (_, index) => `blob:page-${index}`);
  pages.blobs = pages.map(() => new Blob([validImage('page.jpg')]));
  pages.pixels = pages.map(() => 29_862_500);
  const book = new OriginalComicBook('viewer', pages, {
    currentPage: 0,
    displayMode: 'single',
    error: message => errors.push(message),
  });
  book.showControl = book.hideControl = book.tocInsert = () => {};
  book.drawPage = () => {};

  book.preload();
  await new Promise(resolve => setImmediate(resolve));

  assert.deepEqual(errors, []);
  assert.equal(images.length, 3);
  assert.equal(images.filter(image => image.src).length, 3);
});
