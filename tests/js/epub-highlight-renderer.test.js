import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import '../../js/reader-limits.js';
import { HIGHLIGHT_PALETTE } from '../../src/epubHighlights/model.js';
import { EpubHighlightRenderer } from '../../src/epubHighlights/renderer.js';

const policy = globalThis.ReaderLimits.policy;

function highlight(id, color = 'yellow', spinePos = 0) {
  return {
    anchor: {
      end: `epubcfi(/6/${2 * spinePos + 2}!/4/2/1:8)`,
      spinePos,
      start: `epubcfi(/6/${2 * spinePos + 2}!/4/2/1:0)`,
      version: 1,
    },
    body: `Excerpt ${id}`,
    color,
    date: 1,
    edited: 1,
    id,
    type: 'highlight',
  };
}

class FakeElement {
  constructor(tagName) {
    this.children = [];
    this.className = '';
    this.dataset = {};
    this.id = '';
    this.parentNode = null;
    this.style = {};
    this.tagName = tagName.toUpperCase();
    this.textContent = '';
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  remove() {
    if (!this.parentNode) return;
    const index = this.parentNode.children.indexOf(this);
    if (index >= 0) this.parentNode.children.splice(index, 1);
    this.parentNode = null;
  }
}

class FakeDocument {
  constructor(window) {
    this.defaultView = window;
    this.head = new FakeElement('head');
    this.body = new FakeElement('body');
  }

  createElement(tagName) {
    return new FakeElement(tagName);
  }

  getElementById(id) {
    const visit = (node) => {
      if (node.id === id) return node;
      for (const child of node.children) {
        const found = visit(child);
        if (found) return found;
      }
      return null;
    };
    return visit(this.head) ?? visit(this.body);
  }
}

class FakeHighlight extends Set {}

function nativeHarness() {
  const registry = new Map();
  const window = {
    CSS: { highlights: registry },
    Highlight: FakeHighlight,
    cancelAnimationFrame() {},
    requestAnimationFrame(callback) {
      callback();
      return 1;
    },
    scrollY: 0,
  };
  return { document: new FakeDocument(window), registry, window };
}

function fallbackHarness() {
  let nextFrame = 1;
  const frames = new Map();
  const window = {
    cancelAnimationFrame(id) {
      frames.delete(id);
    },
    requestAnimationFrame(callback) {
      const id = nextFrame++;
      frames.set(id, callback);
      return id;
    },
    scrollY: 30,
  };
  return {
    document: new FakeDocument(window),
    flush() {
      const scheduled = [...frames.values()];
      frames.clear();
      for (const callback of scheduled) callback();
    },
    frames,
    window,
  };
}

describe('EPUB highlight renderer', () => {
  test('registers current-chapter ranges by fixed palette color', () => {
    const { document, registry } = nativeHarness();
    const ranges = new Map([
      ['yellow-1', { id: 'range-yellow-1' }],
      ['yellow-2', { id: 'range-yellow-2' }],
      ['blue-1', { id: 'range-blue-1' }],
    ]);
    const renderer = new EpubHighlightRenderer({
      EpubCFI: class {},
      palette: HIGHLIGHT_PALETTE,
      policy,
      resolveRange(anchor, currentDocument, EpubCFI, value) {
        return ranges.get(value.id) ?? null;
      },
    });
    const values = [
      highlight('yellow-1'),
      highlight('yellow-2'),
      highlight('blue-1', 'blue'),
      highlight('other', 'pink', 1),
      { ...highlight('invalid'), color: 'expression(alert(1))' },
    ];

    const result = renderer.render(document, { spinePos: 0 }, { render: { leftPos: 0 } }, values);

    assert.deepEqual(result, { mode: 'native', rendered: 3, skipped: 2 });
    assert.equal(registry.get('epubviewer-yellow').size, 2);
    assert.equal(registry.get('epubviewer-blue').size, 1);
    assert.equal(registry.has('epubviewer-pink'), false);
    const style = document.getElementById('epubviewer-highlight-style');
    assert.match(style.textContent, /::highlight\(epubviewer-yellow\)/);
    assert.match(style.textContent, /background-color: #f6d94a/);
    assert.doesNotMatch(style.textContent, /expression/);
  });

  test('replaces owned registry ranges and preserves unrelated custom highlights', () => {
    const { document, registry } = nativeHarness();
    registry.set('unrelated', new FakeHighlight([{ id: 'foreign' }]));
    let active = { id: 'first' };
    const renderer = new EpubHighlightRenderer({
      EpubCFI: class {},
      palette: HIGHLIGHT_PALETTE,
      policy,
      resolveRange() {
        return active;
      },
    });

    renderer.render(document, { spinePos: 0 }, { render: { leftPos: 0 } }, [highlight('one')]);
    assert.equal(registry.get('epubviewer-yellow').has(active), true);

    active = { id: 'second' };
    renderer.render(document, { spinePos: 0 }, { render: { leftPos: 0 } }, [highlight('two', 'green')]);

    assert.equal(registry.has('epubviewer-yellow'), false);
    assert.equal(registry.get('epubviewer-green').has(active), true);
    assert.equal(registry.has('unrelated'), true);

    renderer.clear();
    assert.equal(registry.has('epubviewer-green'), false);
    assert.equal(registry.has('unrelated'), true);
  });

  test('renders bounded rectangle overlays when the native API is unavailable', () => {
    const { document } = fallbackHarness();
    const range = {
      getClientRects() {
        return [
          { height: 10, left: 5, top: 7, width: 20 },
          { height: 0, left: 1, top: 1, width: 1 },
          { height: 8, left: 30, top: 12, width: 40 },
        ];
      },
    };
    const renderer = new EpubHighlightRenderer({
      EpubCFI: class {},
      palette: HIGHLIGHT_PALETTE,
      policy,
      resolveRange() {
        return range;
      },
    });

    const result = renderer.render(document, { spinePos: 0 }, { render: { leftPos: 120 } }, [highlight('one', 'orange')]);

    assert.deepEqual(result, { mode: 'overlay', rendered: 1, skipped: 0 });
    const layer = document.getElementById('epubviewer-highlight-layer');
    assert.ok(layer);
    assert.equal(layer.style.pointerEvents, 'none');
    assert.equal(layer.children.length, 2);
    assert.deepEqual(layer.children[0].style, {
      height: '10px',
      left: '125px',
      position: 'absolute',
      top: '37px',
      width: '20px',
    });
    assert.equal(layer.children[0].className, 'epubviewer-highlight-rect epubviewer-highlight-orange');
    assert.equal(layer.children[0].dataset.highlightId, 'one');
  });

  test('fails closed before exceeding the fallback rectangle budget', () => {
    const { document } = fallbackHarness();
    const errors = [];
    const renderer = new EpubHighlightRenderer({
      EpubCFI: class {},
      onError(error) {
        errors.push(error.message);
      },
      palette: HIGHLIGHT_PALETTE,
      policy: { ...policy, highlightRectangles: 2 },
      resolveRange() {
        return {
          getClientRects() {
            return [
              { height: 1, left: 1, top: 1, width: 1 },
              { height: 1, left: 2, top: 2, width: 1 },
              { height: 1, left: 3, top: 3, width: 1 },
            ];
          },
        };
      },
    });

    const result = renderer.render(document, { spinePos: 0 }, { render: { leftPos: 0 } }, [highlight('one')]);

    assert.deepEqual(result, { mode: 'overlay', rendered: 0, skipped: 1 });
    assert.equal(document.getElementById('epubviewer-highlight-layer'), null);
    assert.deepEqual(errors, ['EPUB highlights exceed the fallback rectangle limit.']);
  });

  test('coalesces scheduled reflows and renders only the latest state', () => {
    const { document, flush, frames } = fallbackHarness();
    const seen = [];
    const renderer = new EpubHighlightRenderer({
      EpubCFI: class {},
      palette: HIGHLIGHT_PALETTE,
      policy,
      resolveRange(anchor) {
        seen.push(anchor.start);
        return { getClientRects: () => [] };
      },
    });

    renderer.schedule(document, { spinePos: 0 }, { render: { leftPos: 0 } }, [highlight('first')]);
    renderer.schedule(document, { spinePos: 0 }, { render: { leftPos: 0 } }, [highlight('second')]);

    assert.equal(frames.size, 1);
    flush();
    assert.deepEqual(seen, [highlight('second').anchor.start]);

    renderer.schedule(document, { spinePos: 0 }, { render: { leftPos: 0 } }, [highlight('third')]);
    renderer.clear();
    flush();
    assert.deepEqual(seen, [highlight('second').anchor.start]);
  });
});
