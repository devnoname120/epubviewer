import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { registerHandler } from '../../src/nextcloudViewerRegistration.js';

function createHandler(overrides = {}) {
  return {
    id: 'epubviewer',
    mimes: ['application/epub+zip'],
    component: {},
    ...overrides,
  };
}

describe('Nextcloud Viewer registration', () => {
  it('registers a valid handler in the Viewer bootstrap map', () => {
    const win = {};
    const handler = createHandler();

    registerHandler(handler, win);

    assert.ok(win._oca_viewer_handlers instanceof Map);
    assert.equal(win._oca_viewer_handlers.get('epubviewer'), handler);
  });

  it('accepts MIME aliases when no MIME list is provided', () => {
    const win = {};
    const handler = createHandler({
      mimes: undefined,
      mimesAliases: { 'application/x-epub': 'application/epub+zip' },
    });

    registerHandler(handler, win);

    assert.equal(win._oca_viewer_handlers.get('epubviewer'), handler);
  });

  it('rejects invalid handler data', () => {
    const win = {};

    assert.throws(() => registerHandler(createHandler({ id: '' }), win), /valid id/);
    assert.throws(
      () => registerHandler(createHandler({ mimes: undefined }), win),
      /valid mime array or mimesAliases/,
    );
    assert.throws(() => registerHandler(createHandler({ component: null }), win), /valid component/);
  });
});
