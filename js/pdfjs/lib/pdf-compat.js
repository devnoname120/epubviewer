(function (window, document) {
  "use strict";

  if (!window.pdfjsLib) {
    return;
  }

  // Keep legacy reader code working with modern pdfjs-dist globals.
  window.PDFJS = window.pdfjsLib;

  function deriveWorkerSrc() {
    if (document.currentScript && document.currentScript.src) {
      return new URL("pdf.worker.js", document.currentScript.src).toString();
    }

    var scripts = document.querySelectorAll(
      'script[src*="js/pdfjs/lib/pdf-compat.js"]'
    );
    if (!scripts.length) {
      return null;
    }

    return new URL("pdf.worker.js", scripts[scripts.length - 1].src).toString();
  }

  var workerSrc = deriveWorkerSrc();
  if (workerSrc) {
    window.PDFJS.GlobalWorkerOptions.workerSrc = workerSrc;
  }

  Object.defineProperty(window.PDFJS, "workerSrc", {
    configurable: true,
    enumerable: true,
    get: function () {
      return window.PDFJS.GlobalWorkerOptions.workerSrc;
    },
    set: function (value) {
      window.PDFJS.GlobalWorkerOptions.workerSrc = value;
    },
  });

  if (!window.PDFJS.core) {
    window.PDFJS.core = {};
  }
  if (typeof window.PDFJS.core.uuid !== "function") {
    window.PDFJS.core.uuid = function () {
      if (
        window.crypto &&
        typeof window.crypto.randomUUID === "function"
      ) {
        return window.crypto.randomUUID();
      }

      return (
        "uuid-" +
        Date.now().toString(16) +
        "-" +
        Math.random().toString(16).slice(2)
      );
    };
  }

  if (!window.PDFJS.LinkTarget) {
    window.PDFJS.LinkTarget = {
      NONE: 0,
      SELF: 1,
      BLANK: 2,
      PARENT: 3,
      TOP: 4,
    };
  }

  if (typeof window.PDFJS.removeNullCharacters !== "function") {
    window.PDFJS.removeNullCharacters = function (value) {
      return typeof value === "string" ? value.replace(/\x00/g, "") : value;
    };
  }

  if (typeof window.PDFJS.addLinkAttributes !== "function") {
    window.PDFJS.addLinkAttributes = function (link, options) {
      if (!link || !options) {
        return;
      }

      if (options.url) {
        link.href = options.url;
      } else {
        link.removeAttribute("href");
      }

      var targets = {
        1: "_self",
        2: "_blank",
        3: "_parent",
        4: "_top",
      };
      var target = targets[options.target];
      if (target) {
        link.target = target;
      } else {
        link.removeAttribute("target");
      }

      if (options.rel) {
        link.rel = options.rel;
      } else {
        link.removeAttribute("rel");
      }
    };
  }
})(window, document);
