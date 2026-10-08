import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import '../../js/reader-limits.js';

class DomNode {
  constructor(tag = 'div') {
    this.tagName = tag;
    this.children = [];
    this.dataset = {};
    this.style = {};
    this.classList = { add() {}, remove() {} };
    this.value = '';
    this.className = '';
  }
  set innerHTML(value) {
    throw new Error(`HTML parser invoked for ${value}`);
  }
  set textContent(value) {
    this.value = String(value);
    this.children = [];
  }
  get textContent() {
    return this.value + this.children.map((child) => child.textContent).join('');
  }
  appendChild(child) {
    if (child.tagName === 'fragment') this.children.push(...child.children);
    else this.children.push(child);
    return child;
  }
  setAttribute() {}
  addEventListener() {}
  removeEventListener() {}
  getContext() {
    return { fillRect() {} };
  }
  toDataURL() {
    return 'data:image/png;base64,placeholder';
  }
}

function harness(overrides = {}) {
  const nodes = new Map();
  const getNode = (id) => {
    if (!nodes.has(id)) nodes.set(id, new DomNode());
    return nodes.get(id);
  };
  function $(selector) {
    const node = getNode(selector);
    const chain = { 0: node };
    for (const method of [
      'next',
      'contents',
      'find',
      'hide',
      'show',
      'on',
      'css',
      'addClass',
      'removeClass',
      'focus',
    ]) {
      chain[method] = () => chain;
    }
    chain.empty = () => {
      node.textContent = '';
      return chain;
    };
    chain.val = () => '';
    return chain;
  }
  const context = vm.createContext({
    $,
    console,
    setTimeout() {},
    ReaderLimits: { ...globalThis.ReaderLimits, ...overrides },
    Image: class extends DomNode {
      constructor() {
        super('img');
      }
    },
    document: {
      getElementById: (id) => getNode(`#${id}`),
      createElement: (tag) => new DomNode(tag),
      createDocumentFragment: () => new DomNode('fragment'),
      createTextNode: (text) => {
        const node = new DomNode('text');
        node.textContent = text;
        return node;
      },
    },
    window: { addEventListener() {}, innerHeight: 800 },
    PDFJS: { reader: {} },
    tocView: getNode('#tocView'),
  });
  return { context, nodes, getNode };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('PDF page labels containing markup remain literal text', async () => {
  const { context, getNode } = harness();
  vm.runInContext(
    fs.readFileSync(new URL('../../js/pdfjs/controllers/toc_controller.js', import.meta.url), 'utf8'),
    context,
  );
  const label = '<b>Chapter & label</b>';
  const reader = {
    settings: { numPages: 2, thumbnailWidth: 100, thumbnails: false, rotation: 0 },
    pageLabels: { 1: label },
    getThumb() {},
    book: { getPage: async () => ({ rotate: 0, getViewport: () => ({ width: 100, height: 200 }) }) },
  };
  context.PDFJS.reader.TocController.call(reader);
  await flush();
  const toc = getNode('#toc');
  assert.equal(toc.children.length, 2);
  assert.equal(toc.children[0].children[1].textContent, label);
  assert.equal(toc.children[0].children[1].children.length, 0);
});

function searchReader(context, getPageTextContent, pages = 1) {
  vm.runInContext(
    fs.readFileSync(new URL('../../js/pdfjs/controllers/search_controller.js', import.meta.url), 'utf8'),
    context,
  );
  const messages = [];
  const reader = {
    book: {},
    pageContents: [],
    pageMatches: [],
    firstPagePromise: Promise.resolve(),
    settings: { numPages: pages, currentPage: 1, preloadTextcontent: true },
    ControlsController: { setStatus: (message) => messages.push(message) },
    SidebarController: { changePanelTo() {}, getActivePanel: () => 'Search' },
    getPageTextContent,
    bindLink() {},
  };
  const controller = context.PDFJS.reader.SearchController.call(reader);
  return { reader, controller, messages };
}

test('PDF search snippets use text nodes and only programmatic highlight spans', async () => {
  const { context, getNode } = harness();
  let calls = 0;
  const { controller } = searchReader(context, async () => {
    calls++;
    return { items: [{ str: '<label> needle & document text </label>' }] };
  });
  await flush();
  assert.equal(calls, 0, 'search text must not be extracted at reader initialization');
  controller.search('needle');
  await flush();
  const result = getNode('#searchResults').children[1].children[0];
  assert.match(result.textContent, /<label> needle & document text <\/label>/);
  assert.deepEqual(
    result.children.map((child) => child.tagName),
    ['span', 'text', 'span', 'text'],
  );
  assert.equal(result.children[2].className, 'search_match');
  assert.equal(result.children[2].textContent, 'needle');
});

test('PDF search stops requesting pages after cancellation', async () => {
  const { context } = harness();
  let calls = 0,
    resolve;
  const { controller, reader } = searchReader(
    context,
    () => {
      calls++;
      return new Promise((done) => {
        resolve = done;
      });
    },
    3,
  );
  controller.search('word');
  await flush();
  controller.search('');
  resolve({ items: [{ str: 'word' }] });
  await flush();
  assert.equal(calls, 1);
  assert.equal(reader.pageContents.length, 0);
});

test('PDF search releases partial text when the character budget is exceeded', async () => {
  const { context, getNode } = harness({ policy: { ...globalThis.ReaderLimits.policy, pageTextCharacters: 4 } });
  const { controller, reader, messages } = searchReader(context, async () => ({ items: [{ str: 'five!' }] }));
  controller.search('five');
  await flush();
  assert.equal(reader.pageContents.length, 0);
  assert.equal(getNode('#searchResults').children.length, 0);
  assert.match(messages.at(-1), /character limit/);
});
