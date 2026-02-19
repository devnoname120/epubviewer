(function (window) {
  "use strict";

  var $ = window.jQuery;
  if (!$) {
    return;
  }

  // Keep legacy plugins working on jQuery 3.
  if (!$.fn.andSelf && $.fn.addBack) {
    $.fn.andSelf = $.fn.addBack;
  }

  if (!$.fn.size) {
    $.fn.size = function () {
      return this.length;
    };
  }
})(window);
