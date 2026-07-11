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

function createHarness(entries) {
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

    start() {
      this.emit(eventTypes.START);
      for (const entry of entries) {
        this.emit(eventTypes.EXTRACT, {
          unarchivedFile: {
            fileData: new Uint8Array(entry.bytes || [1, 2, 3]),
            filename: entry.filename,
          },
        });
      }
      this.emit(eventTypes.FINISH);
    }
  }

  class FakeXMLHttpRequest {
    open() {}

    send() {
      this.status = 200;
      this.response = new ArrayBuffer(1);
      this.onload?.();
    }
  }

  const context = vm.createContext({
    $: jQuery,
    ArrayBuffer,
    Blob,
    XMLHttpRequest: FakeXMLHttpRequest,
    Uint8Array,
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
    createObjectURL(blob) {
      blobs.push(blob);
      return `blob:${blobs.length}`;
    },
  };
  context.window = context;

  vm.runInContext(fs.readFileSync(bundlePath, 'utf8'), context, {
    filename: bundlePath.pathname,
  });

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

  return { blobs, books, context, messages };
}

test('routes JPEG and WebP archive entries to the comic renderer', () => {
  const result = createHarness([{ filename: '001.JPG' }, { filename: '002.WEBP' }, { filename: 'metadata.xml' }]);

  assert.deepEqual(
    result.blobs.map((blob) => blob.type),
    ['image/jpeg', 'image/webp'],
  );
  assert.equal(result.books.length, 1);
  assert.deepEqual(result.books[0].pages, ['blob:1', 'blob:2']);
  assert.equal(result.books[0].drawCalls, 1);
});

test('reports an archive with no supported image pages', () => {
  const result = createHarness([{ filename: 'README' }, { filename: '001.tiff' }, { filename: 'metadata.xml' }]);

  assert.equal(result.blobs.length, 0);
  assert.equal(result.books.length, 0);
  assert.equal(result.messages.at(-1), 'No supported images were found in this comic archive.');
});

test('renders fitted comic pages at the display pixel density', () => {
  const result = createHarness([]);
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

test('does not allocate a backing canvas larger than the source page can benefit from', () => {
  const result = createHarness([]);
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
