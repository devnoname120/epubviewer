import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import '../../js/reader-limits.js';
import { HighlightRepository } from '../../src/epubHighlights/persistence.js';

const policy = globalThis.ReaderLimits.policy;

function highlight(id = 'highlight-1', overrides = {}) {
  const base = {
    anchor: {
      end: `epubcfi(/6/2!/4/2/1:${id.length + 4})`,
      spinePos: 0,
      start: 'epubcfi(/6/2!/4/2/1:0)',
      version: 1,
    },
    body: `Selected ${id}`,
    color: 'yellow',
    date: 100,
    edited: 100,
    id,
    type: 'highlight',
  };
  return {
    ...base,
    ...overrides,
    anchor: { ...base.anchor, ...(overrides.anchor ?? {}) },
  };
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, reject, resolve };
}

function harness(initial = {}, options = {}) {
  const annotations = { ...initial };
  const setCalls = [];
  const deleteCalls = [];
  const changes = [];
  const statuses = [];
  const session = {
    setBookmark(name, value, type, content) {
      const call = { content, name, type, value };
      setCalls.push(call);
      return options.onSet?.(call) ?? Promise.resolve();
    },
    deleteBookmark(name) {
      deleteCalls.push(name);
      return options.onDelete?.(name) ?? Promise.resolve();
    },
  };
  const repository = new HighlightRepository({
    annotations,
    now: options.now ?? (() => 500),
    onChange(event) {
      changes.push(event);
      options.onChange?.(event);
    },
    onStatus(event) {
      statuses.push(event);
      options.onStatus?.(event);
    },
    policy: options.policy ?? policy,
    session,
  });
  return { annotations, changes, deleteCalls, repository, session, setCalls, statuses };
}

async function settle() {
  await Promise.resolve();
  await Promise.resolve();
}

describe('EPUB highlight persistence', () => {
  test('creates a highlight through the existing bookmark endpoint', async () => {
    const result = harness();
    const value = highlight();

    await result.repository.create(value);

    assert.deepEqual(result.setCalls, [{
      content: value,
      name: value.id,
      type: 'highlight',
      value: value.anchor.start,
    }]);
    assert.deepEqual(result.annotations[value.id], value);
    assert.deepEqual(result.statuses.map(({ state }) => state), ['saving', 'saved']);
    assert.deepEqual(result.changes.map(({ action }) => action), ['create']);
  });

  test('rejects a create before mutation when the highlight count is exhausted', async () => {
    const initial = {};
    for (let index = 0; index < 2; index++) initial[`highlight-${index}`] = highlight(`highlight-${index}`);
    initial.note = { id: 'note', type: 'annotation' };
    const result = harness(initial, { policy: { ...policy, highlights: 2 } });

    await assert.rejects(result.repository.create(highlight('highlight-new')), /highlight limit/);

    assert.equal(result.annotations['highlight-new'], undefined);
    assert.deepEqual(result.setCalls, []);
    assert.deepEqual(result.statuses, []);
  });

  test('rolls back an optimistic create when persistence fails', async () => {
    const failure = new Error('write failed');
    const result = harness({}, { onSet: () => Promise.reject(failure) });

    await assert.rejects(result.repository.create(highlight()), /write failed/);

    assert.equal(result.annotations['highlight-1'], undefined);
    assert.deepEqual(result.changes.map(({ action }) => action), ['create', 'rollback-create']);
    assert.deepEqual(result.statuses.map(({ state }) => state), ['saving', 'error']);
    assert.equal(result.statuses.at(-1).error, failure);
  });

  test('recolors with a fresh edited timestamp and restores the prior record on failure', async () => {
    const original = highlight();
    const result = harness({ [original.id]: original }, {
      now: () => 900,
      onSet: () => Promise.reject(new Error('recolor failed')),
    });

    await assert.rejects(result.repository.update(original.id, { color: 'blue' }), /recolor failed/);

    assert.deepEqual(result.setCalls[0].content, { ...original, color: 'blue', edited: 900 });
    assert.deepEqual(result.annotations[original.id], original);
    assert.deepEqual(result.changes.map(({ action }) => action), ['update', 'rollback-update']);
  });

  test('restores a deleted record when the delete request fails', async () => {
    const original = highlight();
    const result = harness({ [original.id]: original }, {
      onDelete: () => Promise.reject(new Error('delete failed')),
    });

    await assert.rejects(result.repository.remove(original.id), /delete failed/);

    assert.deepEqual(result.deleteCalls, [original.id]);
    assert.deepEqual(result.annotations[original.id], original);
    assert.deepEqual(result.changes.map(({ action }) => action), ['delete', 'rollback-delete']);
  });

  test('serializes updates for one highlight in request order', async () => {
    const first = deferred();
    const second = deferred();
    const secondStarted = deferred();
    const requests = [first, second];
    const original = highlight();
    const result = harness({ [original.id]: original }, {
      now: (() => {
        let value = 1000;
        return () => value++;
      })(),
      onSet: () => {
        const request = requests.shift();
        if (request === second) secondStarted.resolve();
        return request.promise;
      },
    });

    const blue = result.repository.update(original.id, { color: 'blue' });
    const green = result.repository.update(original.id, { color: 'green' });
    await settle();
    assert.equal(result.setCalls.length, 1);
    assert.equal(result.setCalls[0].content.color, 'blue');

    first.resolve();
    await secondStarted.promise;
    assert.equal(result.setCalls.length, 2);
    assert.equal(result.setCalls[1].content.color, 'green');

    second.resolve();
    await Promise.all([blue, green]);
    assert.equal(result.annotations[original.id].color, 'green');
  });

  test('allows independent highlight writes to proceed concurrently', async () => {
    const first = deferred();
    const second = deferred();
    const pending = new Map([
      ['highlight-1', first],
      ['highlight-2', second],
    ]);
    const result = harness({
      'highlight-1': highlight('highlight-1'),
      'highlight-2': highlight('highlight-2'),
    }, {
      onSet: ({ name }) => pending.get(name).promise,
    });

    const one = result.repository.update('highlight-1', { color: 'blue' });
    const two = result.repository.update('highlight-2', { color: 'pink' });
    await settle();

    assert.equal(result.setCalls.length, 2);
    first.resolve();
    second.resolve();
    await Promise.all([one, two]);
  });

  test('lists only normalized highlight records as detached values', () => {
    const original = highlight();
    const result = harness({
      [original.id]: original,
      malformed: { ...original, id: 'malformed', color: 'expression(alert(1))' },
      note: { id: 'note', type: 'annotation' },
    });

    const values = result.repository.list();

    assert.deepEqual(values, [original]);
    assert.notEqual(values[0], original);
  });

  test('rekeys restored list records by persistent highlight id for later mutations', async () => {
    const original = highlight('restored-highlight');
    const annotations = [original, { id: 'note', type: 'annotation' }];
    const setCalls = [];
    const repository = new HighlightRepository({
      annotations,
      policy,
      session: {
        deleteBookmark() {
          return Promise.resolve();
        },
        setBookmark(name, value, type, content) {
          setCalls.push({ content, name, type, value });
          return Promise.resolve();
        },
      },
    });

    await repository.update(original.id, { color: 'green' });

    assert.equal(annotations[0], undefined);
    assert.equal(annotations[original.id].color, 'green');
    assert.equal(setCalls[0].name, original.id);
    assert.equal(annotations[1].type, 'annotation');
  });

  test('does not let UI observer failures interrupt server persistence', async () => {
    const result = harness({}, {
      onChange() {
        throw new Error('render failed');
      },
      onStatus() {
        throw new Error('status failed');
      },
    });

    await result.repository.create(highlight());

    assert.equal(result.setCalls.length, 1);
    assert.equal(result.annotations['highlight-1'].color, 'yellow');
  });
});
