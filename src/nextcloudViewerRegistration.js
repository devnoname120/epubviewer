// Runtime equivalent of @nextcloud/viewer registration. It is kept local because
// that package's Vue 2 peer dependency conflicts with the separate Vue 3 settings app.
export function registerHandler(handler, win = globalThis.window) {
  if (!win) {
    throw new Error('Viewer handler registration requires a window object.');
  }

  validateHandler(handler);

  win._oca_viewer_handlers ??= new Map();
  if (win._oca_viewer_handlers.has(handler.id)) {
    console.warn(`Handler with id ${handler.id} is already registered.`);
    return;
  }

  win._oca_viewer_handlers.set(handler.id, handler);
}

function validateHandler(handler) {
  const { id, mimes, mimesAliases, component } = handler;

  if (!id || id.trim() === '' || typeof id !== 'string') {
    throw new Error('The handler does not have a valid id');
  }

  if ((!mimes || !Array.isArray(mimes)) && !mimesAliases) {
    throw new Error('Handler needs a valid mime array or mimesAliases');
  }

  if (!component || (typeof component !== 'object' && typeof component !== 'function')) {
    throw new Error('The handler does not have a valid component');
  }
}
