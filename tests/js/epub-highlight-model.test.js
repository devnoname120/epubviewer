import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import '../../js/reader-limits.js';
import {
  HIGHLIGHT_PALETTE,
  compareHighlights,
  excerptSelection,
  highlightAnchorToRange,
  highlightContentBytes,
  normalizeHighlight,
  rangeToHighlightAnchor,
  sameHighlightAnchor,
} from '../../src/epubHighlights/model.js';

const policy = globalThis.ReaderLimits.policy;

class FakeText {
  constructor(key, data) {
    this.data = data;
    this.key = key;
    this.length = data.length;
    this.nodeType = 3;
    this.parentNode = null;
    this.textContent = data;
  }
}

class FakeElement {
  constructor(key, children = []) {
    this.childNodes = [];
    this.children = [];
    this.key = key;
    this.nodeType = 1;
    this.parentNode = null;
    for (const child of children) this.appendChild(child);
  }

  appendChild(child) {
    child.parentNode = this;
    this.childNodes.push(child);
    if (child.nodeType === 1) this.children.push(child);
    return child;
  }
}

function textDescendants(node) {
  if (node.nodeType === 3) return [node];
  return node.childNodes.flatMap(textDescendants);
}

function firstText(node) {
  return textDescendants(node)[0] ?? null;
}

function lastText(node) {
  return textDescendants(node).at(-1) ?? null;
}

class FakeRange {
  constructor(document) {
    this.document = document;
    this.startContainer = document.root;
    this.startOffset = 0;
    this.endContainer = document.root;
    this.endOffset = 0;
  }

  setStart(node, offset) {
    this.startContainer = node;
    this.startOffset = offset;
  }

  setEnd(node, offset) {
    this.endContainer = node;
    this.endOffset = offset;
  }

  cloneRange() {
    const clone = new FakeRange(this.document);
    clone.setStart(this.startContainer, this.startOffset);
    clone.setEnd(this.endContainer, this.endOffset);
    return clone;
  }

  get commonAncestorContainer() {
    return this.document.root;
  }

  get collapsed() {
    return this.document.comparePoints(
      this.startContainer,
      this.startOffset,
      this.endContainer,
      this.endOffset,
    ) >= 0;
  }

  intersectsNode(node) {
    const start = this.document.point(node, 0);
    const end = this.document.point(node, node.length);
    const rangeStart = this.document.point(this.startContainer, this.startOffset);
    const rangeEnd = this.document.point(this.endContainer, this.endOffset);
    return start < rangeEnd && end > rangeStart;
  }

  toString() {
    const texts = this.document.texts;
    const start = this.document.point(this.startContainer, this.startOffset);
    const end = this.document.point(this.endContainer, this.endOffset);
    let result = '';
    for (let index = 0; index < texts.length; index++) {
      const node = texts[index];
      const nodeStart = this.document.point(node, 0);
      const nodeEnd = this.document.point(node, node.length);
      if (nodeStart >= end || nodeEnd <= start) continue;
      const from = Math.max(0, start - nodeStart);
      const to = Math.min(node.length, end - nodeStart);
      result += node.data.slice(from, to);
    }
    return result;
  }
}

class FakeDocument {
  constructor(root) {
    this.root = root;
    this.texts = textDescendants(root);
    this.defaultView = { NodeFilter: { SHOW_TEXT: 4 } };
  }

  createRange() {
    return new FakeRange(this);
  }

  createTreeWalker(root) {
    const nodes = textDescendants(root);
    let index = -1;
    return {
      nextNode() {
        index += 1;
        return nodes[index] ?? null;
      },
    };
  }

  findText(key) {
    return this.texts.find((node) => node.key === key) ?? null;
  }

  point(container, offset) {
    if (container.nodeType === 3) {
      const index = this.texts.indexOf(container);
      if (index === -1) throw new Error('Text node is outside the document');
      return this.texts.slice(0, index).reduce((total, node) => total + node.length, 0) + offset;
    }

    const child = container.childNodes[offset];
    if (child) {
      const text = firstText(child);
      if (text) return this.point(text, 0);
    }
    const previous = container.childNodes[offset - 1];
    const text = previous ? lastText(previous) : firstText(container);
    return text ? this.point(text, previous ? text.length : 0) : 0;
  }

  comparePoints(leftNode, leftOffset, rightNode, rightOffset) {
    return this.point(leftNode, leftOffset) - this.point(rightNode, rightOffset);
  }
}

class FakeEpubCFI {
  constructor(value) {
    if (value) return this.parse(value);
  }

  generateCfiFromTextNode(node, offset, base) {
    return `epubcfi(${base}!/${node.key}:${offset})`;
  }

  parse(value) {
    const match = /^epubcfi\(spine-(\d+)!\/([^:]+):(\d+)\)$/.exec(value);
    if (!match) return { spinePos: -1, steps: [] };
    const childIndexes = { a: 0, b: 1, c: 0 };
    return {
      characterOffset: Number(match[3]),
      nodeKey: match[2],
      spinePos: Number(match[1]),
      steps: [{ type: 'text', index: childIndexes[match[2]] ?? 0 }],
      str: value,
    };
  }

  findParent(parsed, document) {
    return document.findText(parsed.nodeKey)?.parentNode ?? null;
  }

  compare(left, right) {
    const leftPoint = this.parse(left);
    const rightPoint = this.parse(right);
    if (leftPoint.spinePos !== rightPoint.spinePos) return leftPoint.spinePos - rightPoint.spinePos;
    if (leftPoint.nodeKey !== rightPoint.nodeKey) return leftPoint.nodeKey.localeCompare(rightPoint.nodeKey);
    return leftPoint.characterOffset - rightPoint.characterOffset;
  }
}

function fixture() {
  const first = new FakeText('a', 'Alpha ');
  const second = new FakeText('b', 'beta');
  const third = new FakeText('c', ' gamma');
  const left = new FakeElement('left', [first, second]);
  const right = new FakeElement('right', [third]);
  const root = new FakeElement('root', [left, right]);
  const document = new FakeDocument(root);
  return { document, first, left, right, root, second, third };
}

function validHighlight(overrides = {}) {
  const base = {
    anchor: {
      end: 'epubcfi(spine-2!/b:4)',
      spinePos: 2,
      start: 'epubcfi(spine-2!/a:2)',
      version: 1,
    },
    body: '<script>literal selection</script>',
    color: 'yellow',
    date: 10,
    edited: 11,
    id: 'highlight-1',
    type: 'highlight',
  };
  return {
    ...base,
    ...overrides,
    anchor: { ...base.anchor, ...(overrides.anchor ?? {}) },
  };
}

describe('EPUB highlight model', () => {
  test('accepts a bounded palette-only persisted annotation as plain data', () => {
    const normalized = normalizeHighlight(validHighlight(), policy);

    assert.deepEqual(Object.keys(HIGHLIGHT_PALETTE), ['yellow', 'green', 'blue', 'pink', 'orange']);
    assert.deepEqual(normalized, validHighlight());
    assert.notEqual(normalized, validHighlight());
    assert.equal(normalized.body, '<script>literal selection</script>');
    assert.ok(highlightContentBytes(normalized) <= policy.highlightContentBytes);
  });

  test('rejects malformed, injected, and over-column-limit records', () => {
    const cases = [
      null,
      { ...validHighlight(), type: 'annotation' },
      validHighlight({ color: 'url(javascript:alert(1))' }),
      validHighlight({ id: '' }),
      validHighlight({ body: 'x'.repeat(policy.highlightTextCharacters + 1) }),
      validHighlight({ anchor: { start: 'x'.repeat(policy.highlightCfiCharacters + 1) } }),
      validHighlight({ anchor: { end: 'not-a-cfi' } }),
      validHighlight({ anchor: { spinePos: -1 } }),
      validHighlight({ date: Number.NaN }),
    ];

    for (const value of cases) assert.equal(normalizeHighlight(value, policy), null);

    const oversized = validHighlight({
      body: 'é'.repeat(policy.highlightContentBytes),
    });
    assert.ok(highlightContentBytes(oversized) > policy.highlightContentBytes);
    assert.equal(normalizeHighlight(oversized, policy), null);
  });

  test('normalizes selection excerpts without preserving unbounded whitespace', () => {
    assert.equal(excerptSelection('  Alpha\n\tbeta   gamma  ', 30), 'Alpha beta gamma');
    assert.equal(excerptSelection('abcdefghij', 6), 'abcde…');
    assert.equal(excerptSelection('   ', 6), '');
  });

  test('creates paired point CFIs for a multi-node element-boundary selection', () => {
    const { document, root } = fixture();
    const range = document.createRange();
    range.setStart(root, 0);
    range.setEnd(root, 2);

    const anchor = rangeToHighlightAnchor(
      range,
      { cfiBase: 'spine-2', spinePos: 2 },
      FakeEpubCFI,
      policy,
    );

    assert.deepEqual(anchor, {
      end: 'epubcfi(spine-2!/c:6)',
      spinePos: 2,
      start: 'epubcfi(spine-2!/a:0)',
      version: 1,
    });
  });

  test('restores an exact multi-node range including an end-of-text boundary', () => {
    const { document } = fixture();
    const anchor = {
      end: 'epubcfi(spine-2!/b:4)',
      spinePos: 2,
      start: 'epubcfi(spine-2!/a:2)',
      version: 1,
    };

    const range = highlightAnchorToRange(anchor, document, FakeEpubCFI, policy);

    assert.ok(range);
    assert.equal(range.startOffset, 2);
    assert.equal(range.endOffset, 4);
    assert.equal(range.toString(), 'pha beta');
  });

  test('rejects collapsed, inverted, missing, and cross-spine anchors', () => {
    const { document } = fixture();
    const cases = [
      { end: 'epubcfi(spine-2!/a:2)', spinePos: 2, start: 'epubcfi(spine-2!/a:2)', version: 1 },
      { end: 'epubcfi(spine-2!/a:1)', spinePos: 2, start: 'epubcfi(spine-2!/b:1)', version: 1 },
      { end: 'epubcfi(spine-2!/missing:1)', spinePos: 2, start: 'epubcfi(spine-2!/a:1)', version: 1 },
      { end: 'epubcfi(spine-3!/b:1)', spinePos: 2, start: 'epubcfi(spine-2!/a:1)', version: 1 },
    ];

    for (const anchor of cases) {
      assert.equal(highlightAnchorToRange(anchor, document, FakeEpubCFI, policy), null);
    }
  });

  test('compares anchors exactly and sorts by spine then CFI', () => {
    const first = validHighlight();
    const same = validHighlight({ id: 'other' });
    const later = validHighlight({
      id: 'later',
      anchor: {
        end: 'epubcfi(spine-3!/a:4)',
        spinePos: 3,
        start: 'epubcfi(spine-3!/a:1)',
      },
    });

    assert.equal(sameHighlightAnchor(first.anchor, same.anchor), true);
    assert.equal(sameHighlightAnchor(first.anchor, later.anchor), false);
    assert.deepEqual([later, first].sort((left, right) => compareHighlights(left, right, FakeEpubCFI)), [first, later]);
  });
});
