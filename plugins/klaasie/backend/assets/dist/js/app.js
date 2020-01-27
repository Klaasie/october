/******/ (function(modules) { // webpackBootstrap
/******/ 	// The module cache
/******/ 	var installedModules = {};
/******/
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/
/******/ 		// Check if module is in cache
/******/ 		if(installedModules[moduleId]) {
/******/ 			return installedModules[moduleId].exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = installedModules[moduleId] = {
/******/ 			i: moduleId,
/******/ 			l: false,
/******/ 			exports: {}
/******/ 		};
/******/
/******/ 		// Execute the module function
/******/ 		modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
/******/
/******/ 		// Flag the module as loaded
/******/ 		module.l = true;
/******/
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/
/******/
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = modules;
/******/
/******/ 	// expose the module cache
/******/ 	__webpack_require__.c = installedModules;
/******/
/******/ 	// define getter function for harmony exports
/******/ 	__webpack_require__.d = function(exports, name, getter) {
/******/ 		if(!__webpack_require__.o(exports, name)) {
/******/ 			Object.defineProperty(exports, name, { enumerable: true, get: getter });
/******/ 		}
/******/ 	};
/******/
/******/ 	// define __esModule on exports
/******/ 	__webpack_require__.r = function(exports) {
/******/ 		if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 			Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 		}
/******/ 		Object.defineProperty(exports, '__esModule', { value: true });
/******/ 	};
/******/
/******/ 	// create a fake namespace object
/******/ 	// mode & 1: value is a module id, require it
/******/ 	// mode & 2: merge all properties of value into the ns
/******/ 	// mode & 4: return value when already ns object
/******/ 	// mode & 8|1: behave like require
/******/ 	__webpack_require__.t = function(value, mode) {
/******/ 		if(mode & 1) value = __webpack_require__(value);
/******/ 		if(mode & 8) return value;
/******/ 		if((mode & 4) && typeof value === 'object' && value && value.__esModule) return value;
/******/ 		var ns = Object.create(null);
/******/ 		__webpack_require__.r(ns);
/******/ 		Object.defineProperty(ns, 'default', { enumerable: true, value: value });
/******/ 		if(mode & 2 && typeof value != 'string') for(var key in value) __webpack_require__.d(ns, key, function(key) { return value[key]; }.bind(null, key));
/******/ 		return ns;
/******/ 	};
/******/
/******/ 	// getDefaultExport function for compatibility with non-harmony modules
/******/ 	__webpack_require__.n = function(module) {
/******/ 		var getter = module && module.__esModule ?
/******/ 			function getDefault() { return module['default']; } :
/******/ 			function getModuleExports() { return module; };
/******/ 		__webpack_require__.d(getter, 'a', getter);
/******/ 		return getter;
/******/ 	};
/******/
/******/ 	// Object.prototype.hasOwnProperty.call
/******/ 	__webpack_require__.o = function(object, property) { return Object.prototype.hasOwnProperty.call(object, property); };
/******/
/******/ 	// __webpack_public_path__
/******/ 	__webpack_require__.p = "/";
/******/
/******/
/******/ 	// Load entry module and return exports
/******/ 	return __webpack_require__(__webpack_require__.s = 0);
/******/ })
/************************************************************************/
/******/ ({

/***/ "./src/js/app.js":
/*!***********************!*\
  !*** ./src/js/app.js ***!
  \***********************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

// modules
__webpack_require__(/*! ./dropdown.js */ "./src/js/dropdown.js");

__webpack_require__(/*! ./sidenav.js */ "./src/js/sidenav.js"); // Initialize components


$(document).ready(function () {
  $('#main-menu').dragScroll();
});

/***/ }),

/***/ "./src/js/dropdown.js":
/*!****************************!*\
  !*** ./src/js/dropdown.js ***!
  \****************************/
/*! no static exports found */
/***/ (function(module, exports) {

function _typeof(obj) { if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") { _typeof = function _typeof(obj) { return typeof obj; }; } else { _typeof = function _typeof(obj) { return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }; } return _typeof(obj); }

+function ($) {
  "use strict";

  var Base = $.oc.foundation.base,
      BaseProto = Base.prototype;

  var TWDropdown = function TWDropdown(element, options) {
    var $el = this.$el = $(element);
    this.options = options || {};
    Base.call(this);
    $($el).on('click', this.proxy(this.toggle));
    $('#overlay').on('click', this.proxy(this.toggle));
  };

  TWDropdown.prototype = Object.create(BaseProto);
  TWDropdown.prototype.constructor = TWDropdown;

  TWDropdown.prototype.toggle = function () {
    $(this.$el).toggleClass('dropdown-open');
    $('#overlay').toggle();
    $(this.options.dropdownTarget).toggle();
  };

  TWDropdown.DEFAULTS = {}; // SIMPLE LIST PLUGIN DEFINITION
  // ============================

  var old = $.fn.twdropdown;

  $.fn.twdropdown = function (option) {
    return this.each(function () {
      var $this = $(this);
      var data = $this.data('dropdown');
      var options = $.extend({}, TWDropdown.DEFAULTS, $this.data(), _typeof(option) == 'object' && option);
      if (!data) $this.data('dropdown', data = new TWDropdown(this, options));
    });
  };

  $.fn.twdropdown.Constructor = TWDropdown; // SIMPLE LIST NO CONFLICT
  // =================

  $.fn.twdropdown.noConflict = function () {
    $.fn.twdropdown = old;
    return this;
  }; // SIMPLE LIST DATA-API
  // ===============


  $(document).render(function () {
    $('[data-control="dropdown"]').twdropdown();
  });
}(window.jQuery);

/***/ }),

/***/ "./src/js/sidenav.js":
/*!***************************!*\
  !*** ./src/js/sidenav.js ***!
  \***************************/
/*! no static exports found */
/***/ (function(module, exports) {

function _typeof(obj) { if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") { _typeof = function _typeof(obj) { return typeof obj; }; } else { _typeof = function _typeof(obj) { return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }; } return _typeof(obj); }

/*
 * Side Navigation
 *
 * Data attributes:
 * - data-control="sidenav" - enables the side navigation plugin
 *
 * JavaScript API:
 * $('#nav').sideNav()
 * $.oc.sideNav.setCounter('cms/partials', 5); - sets the counter value for a particular menu item
 * $.oc.sideNav.increaseCounter('cms/partials', 5); - increases the counter value for a particular menu item
 * $.oc.sideNav.dropCounter('cms/partials'); - drops the counter value for a particular menu item
 *
 * Dependences:
 * - Drag Scroll (october.dragscroll.js)
 */
+function ($) {
  "use strict";

  if ($.oc === undefined) $.oc = {}; // SIDENAV CLASS DEFINITION
  // ============================

  var SideNav = function SideNav(element, options) {
    this.options = options;
    this.$el = $(element);
    this.$list = $('ul', this.$el);
    this.$items = $('li', this.$list);
    this.init();
  };

  SideNav.DEFAULTS = {
    activeClass: 'active'
  };

  SideNav.prototype.init = function () {
    var self = this;
    this.$list.dragScroll({
      vertical: false,
      useNative: true,
      start: function start() {
        self.$list.addClass('drag');
      },
      stop: function stop() {
        self.$list.removeClass('drag');
      },
      scrollClassContainer: self.$el,
      scrollMarkerContainer: self.$el
    });
    this.$list.on('click', function () {
      /* Do not handle menu item clicks while dragging */
      if (self.$list.hasClass('drag')) {
        return false;
      }
    });
  };

  SideNav.prototype.unsetActiveItem = function (itemId) {
    this.$items.removeClass(this.options.activeClass);
  };

  SideNav.prototype.setActiveItem = function (itemId) {
    if (!itemId) {
      return;
    }

    this.$items.removeClass(this.options.activeClass).filter('[data-menu-item=' + itemId + ']').addClass(this.options.activeClass);
  };

  SideNav.prototype.setCounter = function (itemId, value) {
    var $counter = $('span.counter[data-menu-id="' + itemId + '"]', this.$el);
    $counter.removeClass('empty');
    $counter.toggleClass('empty', value == 0);
    $counter.text(value);
    return this;
  };

  SideNav.prototype.increaseCounter = function (itemId, value) {
    var $counter = $('span.counter[data-menu-id="' + itemId + '"]', this.$el);
    var originalValue = parseInt($counter.text());
    if (isNaN(originalValue)) originalValue = 0;
    var newValue = value + originalValue;
    $counter.toggleClass('empty', newValue == 0);
    $counter.text(newValue);
    return this;
  };

  SideNav.prototype.dropCounter = function (itemId) {
    this.setCounter(itemId, 0);
    return this;
  }; // SIDENAV PLUGIN DEFINITION
  // ============================


  var old = $.fn.sideNav;

  $.fn.sideNav = function (option) {
    var args = Array.prototype.slice.call(arguments, 1),
        result;
    this.each(function () {
      var $this = $(this);
      var data = $this.data('oc.sideNav');
      var options = $.extend({}, SideNav.DEFAULTS, $this.data(), _typeof(option) == 'object' && option);
      if (!data) $this.data('oc.sideNav', data = new SideNav(this, options));
      if (typeof option == 'string') result = data[option].apply(data, args);
      if (typeof result != 'undefined') return false;
      if ($.oc.sideNav === undefined) $.oc.sideNav = data;
    });
    return result ? result : this;
  };

  $.fn.sideNav.Constructor = SideNav; // SIDENAV NO CONFLICT
  // =================

  $.fn.sideNav.noConflict = function () {
    $.fn.sideNav = old;
    return this;
  }; // SIDENAV DATA-API
  // ===============


  $(document).ready(function () {
    $('[data-control="sidenav"]').sideNav();
  });
}(window.jQuery);

/***/ }),

/***/ "./src/sass/app.scss":
/*!***************************!*\
  !*** ./src/sass/app.scss ***!
  \***************************/
/*! no static exports found */
/***/ (function(module, exports) {

// removed by extract-text-webpack-plugin

/***/ }),

/***/ 0:
/*!*************************************************!*\
  !*** multi ./src/js/app.js ./src/sass/app.scss ***!
  \*************************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

__webpack_require__(/*! /var/www/clean-oc/public/plugins/klaasie/backend/assets/src/js/app.js */"./src/js/app.js");
module.exports = __webpack_require__(/*! /var/www/clean-oc/public/plugins/klaasie/backend/assets/src/sass/app.scss */"./src/sass/app.scss");


/***/ })

/******/ });