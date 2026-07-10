import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const zipBundlePath = new URL('../../js/epubjs/libs/zip.min.js', import.meta.url);
const epubBundlePath = new URL('../../js/epubjs/epub.min.js', import.meta.url);

function LegacyBuffer(data, encoding) {
  return typeof data === 'number' ? Buffer.alloc(data) : Buffer.from(data, encoding);
}
LegacyBuffer.isBuffer = Buffer.isBuffer;

const sharedGlobals = {
  ArrayBuffer,
  Blob,
  Buffer: LegacyBuffer,
  Uint8Array,
  Uint16Array,
  Uint32Array,
};

function loadJsZip() {
  const context = vm.createContext({
    ...sharedGlobals,
    exports: {},
    module: { exports: {} },
  });

  vm.runInContext(fs.readFileSync(zipBundlePath, 'utf8'), context, {
    filename: zipBundlePath.pathname,
  });

  return context.module.exports;
}

function loadEpubJs(JSZip, parserCalls) {
  class RecordingDOMParser {
    parseFromString(source, mimeType) {
      const document = {
        contentType: mimeType,
        documentElement: {},
        source,
      };

      parserCalls.push({ document, mimeType, source });
      return document;
    }
  }

  const context = vm.createContext({
    ...sharedGlobals,
    console,
    document: {},
    DOMParser: RecordingDOMParser,
    JSZip,
    localStorage: {
      getItem() {
        return null;
      },
      removeItem() {},
      setItem() {},
    },
    navigator: { userAgent: '' },
    setImmediate,
    setTimeout,
    clearTimeout,
    TextDecoder,
    TextEncoder,
    URL,
  });

  context.global = context;
  context.self = context;
  context.window = context;

  vm.runInContext(fs.readFileSync(epubBundlePath, 'utf8'), context, {
    filename: epubBundlePath.pathname,
  });

  return context.EPUBJS;
}

test('parses archived .html EPUB chapters as XML', async () => {
  const xhtml = [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN"',
    ' "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">',
    '<html xmlns="http://www.w3.org/1999/xhtml">',
    '<head>',
    '<script type="text/javascript" src="js/kobo.js"/>',
    '</head>',
    '<body>',
    '<p id="visible">Readable chapter</p>',
    '</body>',
    '</html>',
  ].join('');

  const JSZip = loadJsZip();
  const sourceZip = new JSZip();
  sourceZip.file('OEBPS/chapter.html', xhtml);

  const packed = sourceZip.generate({
    compression: 'STORE',
    type: 'nodebuffer',
  });
  const arrayBuffer = packed.buffer.slice(packed.byteOffset, packed.byteOffset + packed.byteLength);
  const parserCalls = [];
  const EPUBJS = loadEpubJs(JSZip, parserCalls);

  // Keep generic resource MIME detection unchanged. Only the EPUB XML loader
  // must override the extension-derived text/html parser choice.
  assert.equal(EPUBJS.core.getMimeType('OEBPS/chapter.html'), 'text/html');

  const archive = new EPUBJS.Unarchiver();
  await archive.open(arrayBuffer);

  const chapter = new EPUBJS.Chapter(
    {
      cfiBase: '/6/2[chapter]',
      href: 'chapter.html',
      id: 'chapter',
      index: 0,
      linear: 'yes',
      manifestProperties: [],
      properties: [],
      url: 'OEBPS/chapter.html',
    },
    archive,
  );

  await chapter.load();

  assert.equal(parserCalls.length, 1);
  assert.equal(parserCalls[0].source, xhtml);
  assert.match(parserCalls[0].source, /<script[^>]*\/>/);
  assert.match(parserCalls[0].source, /id="visible">Readable chapter/);
  assert.equal(parserCalls[0].mimeType, 'text/xml');
  assert.equal(chapter.document.contentType, 'text/xml');
  assert.equal(chapter.document, parserCalls[0].document);
});
