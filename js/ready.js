document.onreadystatechange = function () {
  if (document.readyState == 'complete') {
    const type = decodeURIComponent(getUrlParameter('type'));
    const file = decodeURIComponent(getUrlParameter('file'));
    const options = {};
    const $session = $('.session');

    options.session = {};
    options.session.filename = decodeURI($session.data('filename'));
    options.session.format = $session.data('filetype');
    options.session.fileId = $session.data('fileid');
    options.session.title = options.session.filename;
    options.session.nonce = $session.data('nonce') || '';
    options.session.version = $session.data('version') || '';
    options.session.metadata = $session.data('metadata') || {};
    options.session.annotations = $session.data('annotations') || {};
    options.session.fileId = $session.data('fileid') || '';
    options.session.scope = $session.data('scope') || '';
    options.session.cursor = $session.data('cursor') || {};
    options.session.defaults = $session.data('defaults') || {};
    options.session.preferences = $session.data('preferences') || {};
    options.session.defaults = $session.data('defaults') || {};
    options.session.basePath = $session.data('basepath');
    options.session.staticPath = $session.data('staticpath');
    options.session.downloadLink = $session.data('downloadlink');

    /* functions return jquery promises */
    options.session.getPreference = function (name) {
      return $.get(options.session.basePath + 'preference/' + options.session.fileId + '/' + options.session.scope + '/' + name);
    };
    options.session.setPreference = function (name, value) {
      return $.post(options.session.basePath + 'preference',
        {
          fileId: options.session.fileId,
          scope: options.session.scope,
          name,
          value: JSON.stringify(value)
        });
    };
    options.session.deletePreference = function (name) {
      return $.delete(options.session.basePath + 'preference/' + options.session.fileId + '/' + options.session.scope + '/' + name);
    };
    options.session.getDefault = function (name) {
      return $.get(options.session.basePath + 'preference/default/' + options.session.scope + '/' + name);
    };
    options.session.setDefault = function (name, value) {
      return $.post(options.session.basePath + 'preference/default',
        {
          scope: options.session.scope,
          name,
          value: JSON.stringify(value)
        });
    };
    options.session.deleteDefault = function (name) {
      return $.delete(options.session.basePath + 'preference/default/' + options.session.scope + '/' + name);
    };
    options.session.getBookmark = function (name, type) {
      return $.get(options.session.basePath + 'bookmark/' + options.session.fileId + '/' + type + '/' + name);
    };
    options.session.setBookmark = function (name, value, type, content) {
      return $.post(options.session.basePath + 'bookmark',
        {
          fileId: options.session.fileId,
          name,
          value: JSON.stringify(value),
          type,
          content: JSON.stringify(content)
        });
    };
    options.session.deleteBookmark = function (name) {
      return $.delete(options.session.basePath + 'bookmark/' + options.session.fileId + '/' + name);
    };
    options.session.getCursor = function () {
      return $.get(options.session.basePath + 'bookmark/cursor/' + options.session.fileId);
    };
    options.session.setCursor = function (value) {
      return $.post(options.session.basePath + 'bookmark/cursor',
        {
          fileId: options.session.fileId,
          value: JSON.stringify(value)
        });
    };
    options.session.deleteCursor = function () {
      return $.delete(options.session.basePath + 'bookmark/cursor/' + options.session.fileId);
    };

    switch (type) {
      case 'application/epub+zip':
        options.contained = true;
        renderEpub(file, options);
        break;
      case 'application/x-cbr':
      case 'application/comicbook+7z':
      case 'application/comicbook+ace':
      case 'application/comicbook+rar':
      case 'application/comicbook+tar':
      case 'application/comicbook+truecrypt':
      case 'application/comicbook+zip':
        renderCbr(file, options);
        break;
      case 'application/pdf':
        renderPdf(file, options);
        break;
      default:
        console.log(type + ' is not supported by Reader');
    }
  }

  function getUrlParameter(param) {
    const params = new URLSearchParams(window.location.search);
    return params.get(param) || ''; // Returns the parameter value or an empty string if not found
  }

  // start epub.js renderer
  function renderEpub (file, options) {
    // some parameters...
    const session_el = $('.session');
    const static_path = session_el.data('staticpath');
    EPUBJS.filePath = location.origin + static_path + 'js/epubjs/';

    // epub.js forcibly prepends EPUBJS.basePath to the cssPath.
    // We add a bunch of .. to get rid of this incorrect path.
    EPUBJS.cssPath = '../../..' + static_path + 'js/epubjs/css/';
    EPUBJS.basePath = location.origin + session_el.data('basepath');
    EPUBJS.staticPath = location.origin + static_path;

    /* device-specific boilerplate */

    // IE < 11
    if (navigator.userAgent.includes('MSIE')) {
      EPUBJS.Hooks.register('beforeChapterDisplay').wgxpath = function (callback, renderer) {
        wgxpath.install(renderer.render.window);
        if (callback) { callback(); }
      };
      wgxpath.install(window);
    }

    const reader = ePubViewer(file, options);
  }

  // start cbr.js renderer
  function renderCbr (file, options) {
    CBRJS.filePath = 'js/cbrjs/';

    const reader = cbReader(file, options);
  }

  // start pdf.js renderer
  function renderPdf (file, options) {
    PDFJS.filePath = 'js/pdfjs/';
    PDFJS.imageResourcesPath = 'js/pdfjs/css/images/';
    PDFJS.workerSrc = options.session.staticPath + 'js/pdfjs/lib/pdf.worker.js';

    const reader = pdfReader(file, options);
  }
};
