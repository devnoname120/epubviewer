import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import '../../js/reader-limits.js';
import { createHighlightsController } from '../../src/epubHighlights/controller.js';
import { registerEpubHighlights } from '../../src/epubHighlights/index.js';

const policy = globalThis.ReaderLimits.policy;

class FakeClassList {
  constructor(...values) {
    this.values = new Set(values.filter(Boolean));
  }

  add(...values) {
    for (const value of values) this.values.add(value);
  }

  contains(value) {
    return this.values.has(value);
  }

  remove(...values) {
    for (const value of values) this.values.delete(value);
  }

  toggle(value, force) {
    if (force === true) this.values.add(value);
    else if (force === false) this.values.delete(value);
    else if (this.values.has(value)) this.values.delete(value);
    else this.values.add(value);
    return this.values.has(value);
  }

  toString() {
    return [...this.values].join(' ');
  }
}

class FakeElement extends EventTarget {
  constructor(tagName, id = '') {
    super();
    this.attributes = new Map();
    this.children = [];
    this.classList = new FakeClassList();
    this.dataset = {};
    this.disabled = false;
    this.hidden = false;
    this.id = id;
    this.parentNode = null;
    this.rect = { bottom: 0, height: 0, left: 0, right: 0, top: 0, width: 0 };
    this.style = {};
    this.tagName = tagName.toUpperCase();
    this.textContent = '';
    this.value = '';
  }

  append(...children) {
    for (const child of children) this.appendChild(child);
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  click() {
    this.dispatchEvent(new Event('click'));
  }

  contains(target) {
    if (target === this) return true;
    return this.children.some((child) => child.contains?.(target));
  }

  getAttribute(name) {
    if (name === 'class') return this.classList.toString();
    return this.attributes.get(name) ?? null;
  }

  getBoundingClientRect() {
    return this.rect;
  }

  querySelectorAll(selector) {
    const matches = [];
    const match = (element) => {
      if (selector === '[data-highlight-color]') return Boolean(element.dataset.highlightColor);
      if (selector.startsWith('.')) return element.classList.contains(selector.slice(1));
      return element.tagName.toLowerCase() === selector.toLowerCase();
    };
    const visit = (element) => {
      for (const child of element.children) {
        if (match(child)) matches.push(child);
        visit(child);
      }
    };
    visit(this);
    return matches;
  }

  remove() {
    if (!this.parentNode) return;
    const index = this.parentNode.children.indexOf(this);
    if (index >= 0) this.parentNode.children.splice(index, 1);
    this.parentNode = null;
  }

  replaceChildren(...children) {
    for (const child of this.children) child.parentNode = null;
    this.children = [];
    this.append(...children);
  }

  setAttribute(name, value) {
    this.attributes.set(name, String(value));
  }
}

class FakeDocument extends EventTarget {
  constructor() {
    super();
    this.body = new FakeElement('body', 'body');
    this.elements = new Map([['body', this.body]]);
  }

  add(element) {
    this.elements.set(element.id, element);
    this.body.appendChild(element);
    return element;
  }

  createElement(tagName) {
    return new FakeElement(tagName);
  }

  getElementById(id) {
    return this.elements.get(id) ?? null;
  }
}

class FakeBook {
  constructor(chapterDocument) {
    this.events = new Map();
    this.gotoCalls = [];
    this.hooks = new Map();
    this.renderer = {
      currentChapter: { cfiBase: 'spine-0', spinePos: 0 },
      doc: chapterDocument,
      registerHook: (name, callback) => {
        this.hooks.set(name, callback);
      },
      removeHook: (name, callback) => {
        if (this.hooks.get(name) === callback) this.hooks.delete(name);
      },
      render: {
        iframe: {
          getBoundingClientRect() {
            return { bottom: 550, height: 500, left: 100, right: 700, top: 50, width: 600 };
          },
        },
        leftPos: 0,
      },
    };
  }

  emit(name, value) {
    for (const listener of this.events.get(name) ?? []) listener(value);
  }

  gotoCfi(value) {
    this.gotoCalls.push(value);
  }

  off(name, listener) {
    this.events.set(name, (this.events.get(name) ?? []).filter((value) => value !== listener));
  }

  on(name, listener) {
    this.events.set(name, [...(this.events.get(name) ?? []), listener]);
  }
}

class FakeEpubCFI {
  compare(left, right) {
    return left.localeCompare(right);
  }
}

function highlight(id = 'saved-highlight', overrides = {}) {
  const base = {
    anchor: {
      end: 'epubcfi(spine-0!/end:4)',
      spinePos: 0,
      start: 'epubcfi(spine-0!/start:0)',
      version: 1,
    },
    body: '<img src=x onerror=alert(1)> literal text',
    color: 'yellow',
    date: 10,
    edited: 10,
    id,
    type: 'highlight',
  };
  return {
    ...base,
    ...overrides,
    anchor: { ...base.anchor, ...(overrides.anchor ?? {}) },
  };
}

function makeSelection(options = {}) {
  const range = {
    anchor: options.anchor ?? highlight().anchor,
    collapsed: options.collapsed ?? false,
    cloneRange() {
      return this;
    },
    endContainer: { ownerDocument: options.document },
    getBoundingClientRect() {
      return { bottom: 100, height: 20, left: 20, right: 120, top: 80, width: 100 };
    },
    startContainer: { ownerDocument: options.document },
    toString() {
      return options.text ?? 'Selected multi-node text';
    },
  };
  return {
    cleared: false,
    getRangeAt() {
      return range;
    },
    isCollapsed: options.collapsed ?? false,
    rangeCount: options.rangeCount ?? 1,
    removeAllRanges() {
      this.cleared = true;
    },
    toString() {
      return range.toString();
    },
  };
}

function findByClass(root, className) {
  if (root.classList?.contains(className)) return root;
  for (const child of root.children ?? []) {
    const found = findByClass(child, className);
    if (found) return found;
  }
  return null;
}

function eventWith(type, values) {
  const event = new Event(type);
  for (const [key, value] of Object.entries(values)) {
    Object.defineProperty(event, key, { configurable: true, value });
  }
  return event;
}

function fixture(initialAnnotations = {}) {
  const outerDocument = new FakeDocument();
  const toolbar = outerDocument.add(new FakeElement('div', 'epub-highlight-toolbar'));
  toolbar.hidden = true;
  toolbar.rect = { bottom: 40, height: 40, left: 0, right: 200, top: 0, width: 200 };
  for (const color of ['yellow', 'green', 'blue', 'pink', 'orange']) {
    const button = new FakeElement('button');
    button.dataset.highlightColor = color;
    toolbar.appendChild(button);
  }
  const view = outerDocument.add(new FakeElement('div', 'highlightsView'));
  const list = new FakeElement('ol', 'highlights');
  outerDocument.elements.set(list.id, list);
  view.appendChild(list);
  const status = new FakeElement('div', 'highlight-status');
  outerDocument.elements.set(status.id, status);
  view.appendChild(status);

  const chapterDocument = new FakeDocument();
  chapterDocument.defaultView = {
    getSelection() {
      return null;
    },
  };
  const book = new FakeBook(chapterDocument);
  const setCalls = [];
  const deleteCalls = [];
  const annotations = { ...initialAnnotations };
  const reader = {
    settings: {
      annotations,
      session: {
        deleteBookmark(name) {
          deleteCalls.push(name);
          return Promise.resolve();
        },
        setBookmark(name, value, type, content) {
          setCalls.push({ content, name, type, value });
          return Promise.resolve();
        },
      },
    },
  };
  const renderCalls = [];
  const highlightRenderer = {
    clearCalls: 0,
    clear() {
      this.clearCalls += 1;
    },
    schedule(...args) {
      renderCalls.push(args);
    },
  };
  let nextId = 1;
  const outerWindow = {
    innerHeight: 600,
    innerWidth: 800,
  };

  const controller = createHighlightsController({
    EpubCFI: FakeEpubCFI,
    book,
    document: outerDocument,
    now: () => 500,
    palette: {
      yellow: '#f6d94a',
      green: '#75d69c',
      blue: '#73b8f4',
      pink: '#f29ac2',
      orange: '#f4a261',
    },
    policy,
    rangeToAnchor(range) {
      return range.anchor;
    },
    reader,
    rendererFactory() {
      return highlightRenderer;
    },
    uuid() {
      return `generated-${nextId++}`;
    },
    window: outerWindow,
  });

  return {
    annotations,
    book,
    chapterDocument,
    controller,
    deleteCalls,
    highlightRenderer,
    list,
    outerDocument,
    outerWindow,
    reader,
    renderCalls,
    setCalls,
    status,
    toolbar,
    view,
  };
}

async function settle() {
  await new Promise((resolve) => setImmediate(resolve));
}

describe('EPUB highlight controller', () => {
  test('registers as an epub.js reader plugin without replacing existing plugins', () => {
    const existing = function () {};
    const EPUBJS = {
      EpubCFI: FakeEpubCFI,
      core: { uuid: () => 'uuid' },
      reader: { plugins: { Existing: existing } },
    };

    assert.equal(registerEpubHighlights(EPUBJS), true);
    assert.equal(EPUBJS.reader.plugins.Existing, existing);
    assert.equal(typeof EPUBJS.reader.plugins.HighlightsController, 'function');
  });

  test('creates a persisted color highlight from the current selection', async () => {
    const result = fixture();
    const selection = makeSelection({ document: result.chapterDocument });

    result.book.emit('renderer:selected', selection);

    assert.equal(result.toolbar.hidden, false);
    assert.equal(result.toolbar.style.left, '70px');
    assert.equal(result.toolbar.style.top, '82px');

    const yellow = result.toolbar.querySelectorAll('[data-highlight-color]')[0];
    yellow.click();
    await settle();

    assert.equal(selection.cleared, true);
    assert.equal(result.toolbar.hidden, true);
    assert.equal(result.setCalls.length, 1);
    assert.deepEqual(result.setCalls[0], {
      content: highlight('generated-1', {
        body: 'Selected multi-node text',
        date: 500,
        edited: 500,
      }),
      name: 'generated-1',
      type: 'highlight',
      value: 'epubcfi(spine-0!/start:0)',
    });
    assert.equal(Object.keys(result.annotations).length, 1);
    assert.equal(result.renderCalls.length > 0, true);
    assert.equal(result.list.children.length, 1);
  });

  test('recolors an exact existing range instead of creating a duplicate', async () => {
    const result = fixture();
    const first = makeSelection({ document: result.chapterDocument });
    result.book.emit('renderer:selected', first);
    result.toolbar.querySelectorAll('[data-highlight-color]')[0].click();
    await settle();

    const second = makeSelection({ document: result.chapterDocument });
    result.book.emit('renderer:selected', second);
    const blue = result.toolbar.querySelectorAll('[data-highlight-color]')[2];
    blue.click();
    await settle();

    assert.equal(Object.keys(result.annotations).length, 1);
    assert.equal(result.annotations['generated-1'].color, 'blue');
    assert.equal(result.setCalls.length, 2);
    assert.equal(result.setCalls[1].name, 'generated-1');
    assert.equal(result.setCalls[1].content.color, 'blue');
  });

  test('hides the toolbar for collapsed selections and on Escape', () => {
    const result = fixture();
    result.book.emit('renderer:selected', makeSelection({ document: result.chapterDocument }));
    assert.equal(result.toolbar.hidden, false);

    result.outerDocument.dispatchEvent(eventWith('keydown', { key: 'Escape' }));
    assert.equal(result.toolbar.hidden, true);

    result.book.emit('renderer:selected', makeSelection({
      collapsed: true,
      document: result.chapterDocument,
    }));
    assert.equal(result.toolbar.hidden, true);
  });

  test('renders untrusted excerpts as text and supports navigation, recoloring, and deletion', async () => {
    const saved = highlight();
    const result = fixture({ [saved.id]: saved });
    assert.equal(result.list.children.length, 1);

    const item = result.list.children[0];
    const link = findByClass(item, 'epub-highlight-link');
    const select = findByClass(item, 'epub-highlight-color');
    const remove = findByClass(item, 'epub-highlight-delete');

    assert.equal(link.textContent, '<img src=x onerror=alert(1)> literal text');
    assert.equal(findByClass(item, 'img'), null);
    link.click();
    assert.deepEqual(result.book.gotoCalls, [saved.anchor.start]);

    select.value = 'green';
    select.dispatchEvent(new Event('change'));
    await settle();
    assert.equal(result.annotations[saved.id].color, 'green');

    remove.click();
    await settle();
    assert.equal(result.annotations[saved.id], undefined);
    assert.deepEqual(result.deleteCalls, [saved.id]);
    assert.equal(result.list.children.length, 0);
  });

  test('shows malformed restored highlight state as unavailable without rendering it', () => {
    const malformed = { ...highlight('broken'), color: 'javascript:bad' };
    const result = fixture({ broken: malformed });

    assert.equal(result.list.children.length, 1);
    assert.ok(findByClass(result.list.children[0], 'epub-highlight-unavailable'));
    assert.match(result.list.children[0].textContent, /Unavailable saved highlight/);
    assert.equal(result.renderCalls.at(-1)[3].length, 0);
  });

  test('runs the chapter hook, rerenders after location changes, and cleans up', () => {
    const result = fixture({ saved: highlight('saved') });
    const hook = result.book.hooks.get('beforeChapterDisplay');
    let completed = false;

    hook(() => { completed = true; }, result.book.renderer);
    assert.equal(completed, true);
    const before = result.renderCalls.length;
    result.book.emit('renderer:locationChanged', 'epubcfi(...)');
    assert.equal(result.renderCalls.length, before + 1);

    result.controller.destroy();
    assert.equal(result.highlightRenderer.clearCalls, 1);
    assert.equal(result.book.hooks.has('beforeChapterDisplay'), false);
    const after = result.renderCalls.length;
    result.book.emit('renderer:locationChanged', 'epubcfi(other)');
    assert.equal(result.renderCalls.length, after);
  });
});
