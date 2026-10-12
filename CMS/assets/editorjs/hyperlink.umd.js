(function webpackUniversalModuleDefinition(root, factory) {
	if(typeof exports === 'object' && typeof module === 'object')
		module.exports = factory();
	else if(typeof define === 'function' && define.amd)
		define([], factory);
	else if(typeof exports === 'object')
		exports["Hyperlink"] = factory();
	else
		root["Hyperlink"] = factory();
})(window, function() {
return /******/ (function(modules) { // webpackBootstrap
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
/******/ 	return __webpack_require__(__webpack_require__.s = "./src/Hyperlink.js");
/******/ })
/************************************************************************/
/******/ ({

/***/ "./node_modules/css-loader/dist/cjs.js!./src/Hyperlink.css":
/*!*****************************************************************!*\
  !*** ./node_modules/css-loader/dist/cjs.js!./src/Hyperlink.css ***!
  \*****************************************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {


// Imports
var ___CSS_LOADER_API_IMPORT___ = __webpack_require__(/*! ../node_modules/css-loader/dist/runtime/api.js */ "./node_modules/css-loader/dist/runtime/api.js");
exports = ___CSS_LOADER_API_IMPORT___(false);
// Module
exports.push([module.i, ".ce-inline-tool-hyperlink-wrapper {\r\n    outline: none;\r\n    border: 0;\r\n    border-radius: 0 0 4px 4px;\r\n    margin: 0;\r\n    font-size: 13px;\r\n    padding: 10px;\r\n    width: 100%;\r\n    -webkit-box-sizing: border-box;\r\n    box-sizing: border-box;\r\n    display: none;\r\n    font-weight: 500;\r\n    border-top: 1px solid rgba(201,201,204,.48);\r\n}\r\n\r\n.ce-inline-tool-hyperlink-wrapper.ce-inline-tool-hyperlink-wrapper--showed {\r\n    display: block;\r\n}\r\n\r\n.ce-inline-tool-hyperlink--input,\r\n.ce-inline-tool-hyperlink--select-target,\r\n.ce-inline-tool-hyperlink--select-rel {\r\n    border: 1px solid rgba(201,201,204,.48);\r\n    -webkit-box-shadow: inset 0 1px 2px 0 rgba(35,44,72,.06);\r\n    box-shadow: inset 0 1px 2px 0 rgba(35,44,72,.06);\r\n    border-radius: 5px;\r\n    padding: 5px 8px;\r\n    margin-bottom: 10px;\r\n    outline: none;\r\n    width: 100%;\r\n    -webkit-box-sizing: border-box;\r\n    box-sizing: border-box;\r\n}\r\n\r\n.ce-inline-tool-hyperlink--select-target,\r\n.ce-inline-tool-hyperlink--select-rel {\r\n    width: 48%;\r\n    display: inline-block;\r\n}\r\n.ce-inline-tool-hyperlink--select-target {\r\n    margin-right: 2%;\r\n}\r\n.ce-inline-tool-hyperlink--select-rel {\r\n    margin-left: 2%;\r\n}\r\n\r\n.ce-inline-tool-hyperlink--button {\r\n    display: block;\r\n    width: 100%;\r\n    background-color: #34c38f;\r\n    color: #fff;\r\n    padding: 7px 0;\r\n    border: none;\r\n    text-align: center;\r\n    text-decoration: none;\r\n    font-size: 16px;\r\n    border-radius: 5px;\r\n    cursor: pointer;\r\n}\r\n", ""]);
// Exports
module.exports = exports;



;

/***/ }),

/***/ "./node_modules/css-loader/dist/runtime/api.js":
/*!*****************************************************!*\
  !*** ./node_modules/css-loader/dist/runtime/api.js ***!
  \*****************************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

"use strict";



/*
  MIT License http://www.opensource.org/licenses/mit-license.php
  Author Tobias Koppers @sokra
*/
// css base code, injected by the css-loader
// eslint-disable-next-line func-names
module.exports = function (useSourceMap) {
  var list = []; // return the list of modules as css string

  list.toString = function toString() {
    return this.map(function (item) {
      var content = cssWithMappingToString(item, useSourceMap);

      if (item[2]) {
        return "@media ".concat(item[2], " {").concat(content, "}");
      }

      return content;
    }).join('');
  }; // import a list of modules into the list
  // eslint-disable-next-line func-names


  list.i = function (modules, mediaQuery, dedupe) {
    if (typeof modules === 'string') {
      // eslint-disable-next-line no-param-reassign
      modules = [[null, modules, '']];
    }

    var alreadyImportedModules = {};

    if (dedupe) {
      for (var i = 0; i < this.length; i++) {
        // eslint-disable-next-line prefer-destructuring
        var id = this[i][0];

        if (id != null) {
          alreadyImportedModules[id] = true;
        }
      }
    }

    for (var _i = 0; _i < modules.length; _i++) {
      var item = [].concat(modules[_i]);

      if (dedupe && alreadyImportedModules[item[0]]) {
        // eslint-disable-next-line no-continue
        continue;
      }

      if (mediaQuery) {
        if (!item[2]) {
          item[2] = mediaQuery;
        } else {
          item[2] = "".concat(mediaQuery, " and ").concat(item[2]);
        }
      }

      list.push(item);
    }
  };

  return list;
};

function cssWithMappingToString(item, useSourceMap) {
  var content = item[1] || ''; // eslint-disable-next-line prefer-destructuring

  var cssMapping = item[3];

  if (!cssMapping) {
    return content;
  }

  if (useSourceMap && typeof btoa === 'function') {
    var sourceMapping = toComment(cssMapping);
    var sourceURLs = cssMapping.sources.map(function (source) {
      return "/*# sourceURL=".concat(cssMapping.sourceRoot || '').concat(source, " */");
    });
    return [content].concat(sourceURLs).concat([sourceMapping]).join('\n');
  }

  return [content].join('\n');
} // Adapted from convert-source-map (MIT)


function toComment(sourceMap) {
  // eslint-disable-next-line no-undef
  var base64 = btoa(unescape(encodeURIComponent(JSON.stringify(sourceMap))));
  var data = "sourceMappingURL=data:application/json;charset=utf-8;base64,".concat(base64);
  return "/*# ".concat(data, " */");
}


;

/***/ }),

/***/ "./node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js":
/*!****************************************************************************!*\
  !*** ./node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js ***!
  \****************************************************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

"use strict";



var isOldIE = function isOldIE() {
  var memo;
  return function memorize() {
    if (typeof memo === 'undefined') {
      // Test for IE <= 9 as proposed by Browserhacks
      // @see http://browserhacks.com/#hack-e71d8692f65334173fee715c222cb805
      // Tests for existence of standard globals is to allow style-loader
      // to operate correctly into non-standard environments
      // @see https://github.com/webpack-contrib/style-loader/issues/177
      memo = Boolean(window && document && document.all && !window.atob);
    }

    return memo;
  };
}();

var getTarget = function getTarget() {
  var memo = {};
  return function memorize(target) {
    if (typeof memo[target] === 'undefined') {
      var styleTarget = document.querySelector(target); // Special case to return head of iframe instead of iframe itself

      if (window.HTMLIFrameElement && styleTarget instanceof window.HTMLIFrameElement) {
        try {
          // This will throw an exception if access to iframe is blocked
          // due to cross-origin restrictions
          styleTarget = styleTarget.contentDocument.head;
        } catch (e) {
          // istanbul ignore next
          styleTarget = null;
        }
      }

      memo[target] = styleTarget;
    }

    return memo[target];
  };
}();

var stylesInDom = [];

function getIndexByIdentifier(identifier) {
  var result = -1;

  for (var i = 0; i < stylesInDom.length; i++) {
    if (stylesInDom[i].identifier === identifier) {
      result = i;
      break;
    }
  }

  return result;
}

function modulesToDom(list, options) {
  var idCountMap = {};
  var identifiers = [];

  for (var i = 0; i < list.length; i++) {
    var item = list[i];
    var id = options.base ? item[0] + options.base : item[0];
    var count = idCountMap[id] || 0;
    var identifier = "".concat(id, " ").concat(count);
    idCountMap[id] = count + 1;
    var index = getIndexByIdentifier(identifier);
    var obj = {
      css: item[1],
      media: item[2],
      sourceMap: item[3]
    };

    if (index !== -1) {
      stylesInDom[index].references++;
      stylesInDom[index].updater(obj);
    } else {
      stylesInDom.push({
        identifier: identifier,
        updater: addStyle(obj, options),
        references: 1
      });
    }

    identifiers.push(identifier);
  }

  return identifiers;
}

function insertStyleElement(options) {
  var style = document.createElement('style');
  var attributes = options.attributes || {};

  if (typeof attributes.nonce === 'undefined') {
    var nonce =  true ? __webpack_require__.nc : undefined;

    if (nonce) {
      attributes.nonce = nonce;
    }
  }

  Object.keys(attributes).forEach(function (key) {
    style.setAttribute(key, attributes[key]);
  });

  if (typeof options.insert === 'function') {
    options.insert(style);
  } else {
    var target = getTarget(options.insert || 'head');

    if (!target) {
      throw new Error("Couldn't find a style target. This probably means that the value for the 'insert' parameter is invalid.");
    }

    target.appendChild(style);
  }

  return style;
}

function removeStyleElement(style) {
  // istanbul ignore if
  if (style.parentNode === null) {
    return false;
  }

  style.parentNode.removeChild(style);
}
/* istanbul ignore next  */


var replaceText = function replaceText() {
  var textStore = [];
  return function replace(index, replacement) {
    textStore[index] = replacement;
    return textStore.filter(Boolean).join('\n');
  };
}();

function applyToSingletonTag(style, index, remove, obj) {
  var css = remove ? '' : obj.media ? "@media ".concat(obj.media, " {").concat(obj.css, "}") : obj.css; // For old IE

  /* istanbul ignore if  */

  if (style.styleSheet) {
    style.styleSheet.cssText = replaceText(index, css);
  } else {
    var cssNode = document.createTextNode(css);
    var childNodes = style.childNodes;

    if (childNodes[index]) {
      style.removeChild(childNodes[index]);
    }

    if (childNodes.length) {
      style.insertBefore(cssNode, childNodes[index]);
    } else {
      style.appendChild(cssNode);
    }
  }
}

function applyToTag(style, options, obj) {
  var css = obj.css;
  var media = obj.media;
  var sourceMap = obj.sourceMap;

  if (media) {
    style.setAttribute('media', media);
  } else {
    style.removeAttribute('media');
  }

  if (sourceMap && typeof btoa !== 'undefined') {
    css += "\n/*# sourceMappingURL=data:application/json;base64,".concat(btoa(unescape(encodeURIComponent(JSON.stringify(sourceMap)))), " */");
  } // For old IE

  /* istanbul ignore if  */


  if (style.styleSheet) {
    style.styleSheet.cssText = css;
  } else {
    while (style.firstChild) {
      style.removeChild(style.firstChild);
    }

    style.appendChild(document.createTextNode(css));
  }
}

var singleton = null;
var singletonCounter = 0;

function addStyle(obj, options) {
  var style;
  var update;
  var remove;

  if (options.singleton) {
    var styleIndex = singletonCounter++;
    style = singleton || (singleton = insertStyleElement(options));
    update = applyToSingletonTag.bind(null, style, styleIndex, false);
    remove = applyToSingletonTag.bind(null, style, styleIndex, true);
  } else {
    style = insertStyleElement(options);
    update = applyToTag.bind(null, style, options);

    remove = function remove() {
      removeStyleElement(style);
    };
  }

  update(obj);
  return function updateStyle(newObj) {
    if (newObj) {
      if (newObj.css === obj.css && newObj.media === obj.media && newObj.sourceMap === obj.sourceMap) {
        return;
      }

      update(obj = newObj);
    } else {
      remove();
    }
  };
}

module.exports = function (list, options) {
  options = options || {}; // Force single-tag solution on IE6-9, which has a hard limit on the # of <style>
  // tags it will allow on a page

  if (!options.singleton && typeof options.singleton !== 'boolean') {
    options.singleton = isOldIE();
  }

  list = list || [];
  var lastIdentifiers = modulesToDom(list, options);
  return function update(newList) {
    newList = newList || [];

    if (Object.prototype.toString.call(newList) !== '[object Array]') {
      return;
    }

    for (var i = 0; i < lastIdentifiers.length; i++) {
      var identifier = lastIdentifiers[i];
      var index = getIndexByIdentifier(identifier);
      stylesInDom[index].references--;
    }

    var newLastIdentifiers = modulesToDom(newList, options);

    for (var _i = 0; _i < lastIdentifiers.length; _i++) {
      var _identifier = lastIdentifiers[_i];

      var _index = getIndexByIdentifier(_identifier);

      if (stylesInDom[_index].references === 0) {
        stylesInDom[_index].updater();

        stylesInDom.splice(_index, 1);
      }
    }

    lastIdentifiers = newLastIdentifiers;
  };
};


;

/***/ }),

/***/ "./src/Hyperlink.css":
/*!***************************!*\
  !*** ./src/Hyperlink.css ***!
  \***************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {


var api = __webpack_require__(/*! ../node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js */ "./node_modules/style-loader/dist/runtime/injectStylesIntoStyleTag.js");
            var content = __webpack_require__(/*! !../node_modules/css-loader/dist/cjs.js!./Hyperlink.css */ "./node_modules/css-loader/dist/cjs.js!./src/Hyperlink.css");

            content = content.__esModule ? content.default : content;

            if (typeof content === 'string') {
              content = [[module.i, content, '']];
            }

var options = {};

options.insert = "head";
options.singleton = false;

api(content, options);



module.exports = content.locals || {};


;

/***/ }),

/***/ "./src/Hyperlink.js":
/*!**************************!*\
  !*** ./src/Hyperlink.js ***!
  \**************************/
/*! exports provided: default */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";

__webpack_require__.r(__webpack_exports__);
/* harmony export (binding) */ __webpack_require__.d(__webpack_exports__, "default", function() { return Hyperlink; });
/* harmony import */ var _SelectionUtils__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./SelectionUtils */ "./src/SelectionUtils.js");
/* harmony import */ var _Hyperlink_css__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./Hyperlink.css */ "./src/Hyperlink.css");
/* harmony import */ var _Hyperlink_css__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_Hyperlink_css__WEBPACK_IMPORTED_MODULE_1__);
function _classCallCheck(instance, Constructor) { if (!(instance instanceof Constructor)) { throw new TypeError("Cannot call a class as a function"); } }

function _defineProperties(target, props) { for (var i = 0; i < props.length; i++) { var descriptor = props[i]; descriptor.enumerable = descriptor.enumerable || false; descriptor.configurable = true; if ("value" in descriptor) descriptor.writable = true; Object.defineProperty(target, descriptor.key, descriptor); } }

function _createClass(Constructor, protoProps, staticProps) { if (protoProps) _defineProperties(Constructor.prototype, protoProps); if (staticProps) _defineProperties(Constructor, staticProps); return Constructor; }




var Hyperlink = /*#__PURE__*/function () {
  function Hyperlink(_ref) {
    var config = _ref.config,
        api = _ref.api;

    _classCallCheck(this, Hyperlink);

    this.toolbar = api.toolbar;
    this.inlineToolbar = api.inlineToolbar;
    this.tooltip = api.tooltip;
    this.i18n = api.i18n;
    this.config = config;
    this.selection = new _SelectionUtils__WEBPACK_IMPORTED_MODULE_0__["default"]();
    this.commandLink = 'createLink';
    this.commandUnlink = 'unlink';
    this.CSS = {
      wrapper: 'ce-inline-tool-hyperlink-wrapper',
      wrapperShowed: 'ce-inline-tool-hyperlink-wrapper--showed',
      button: 'ce-inline-tool',
      buttonActive: 'ce-inline-tool--active',
      buttonModifier: 'ce-inline-tool--link',
      buttonUnlink: 'ce-inline-tool--unlink',
      input: 'ce-inline-tool-hyperlink--input',
      selectTarget: 'ce-inline-tool-hyperlink--select-target',
      selectRel: 'ce-inline-tool-hyperlink--select-rel',
      buttonSave: 'ce-inline-tool-hyperlink--button'
    };
    this.targetAttributes = this.config.availableTargets || ['_blank', // Opens the linked document in a new window or tab
    '_self', // Opens the linked document in the same frame as it was clicked (this is default)
    '_parent', // Opens the linked document in the parent frame
    '_top' // Opens the linked document in the full body of the window
    ];
    this.relAttributes = this.config.availableRels || ['alternate', //Provides a link to an alternate representation of the document (i.e. print page, translated or mirror)
    'author', //Provides a link to the author of the document
    'bookmark', //Permanent URL used for bookmarking
    'external', //Indicates that the referenced document is not part of the same site as the current document
    'help', //Provides a link to a help document
    'license', //Provides a link to licensing information for the document
    'next', //Provides a link to the next document in the series
    'nofollow', //Links to an unendorsed document, like a paid link. ("nofollow" is used by Google, to specify that the Google search spider should not follow that link)
    'noreferrer', //Requires that the browser should not send an HTTP referer header if the user follows the hyperlink
    'noopener', //Requires that any browsing context created by following the hyperlink must not have an opener browsing context
    'prev', //The previous document in a selection
    'search', //Links to a search tool for the document
    'tag' //A tag (keyword) for the current document
    ];
    this.nodes = {
      button: null,
      wrapper: null,
      input: null,
      selectTarget: null,
      selectRel: null,
      buttonSave: null
    };
    this.inputOpened = false;
  }

  _createClass(Hyperlink, [{
    key: "render",
    value: function render() {
      this.nodes.button = document.createElement('button');
      this.nodes.button.type = 'button';
      this.nodes.button.classList.add(this.CSS.button, this.CSS.buttonModifier);
      this.nodes.button.appendChild(this.iconSvg('link', 14, 10));
      this.nodes.button.appendChild(this.iconSvg('unlink', 15, 11));
      return this.nodes.button;
    }
  }, {
    key: "renderActions",
    value: function renderActions() {
      var _this = this;

      this.nodes.wrapper = document.createElement('div');
      this.nodes.wrapper.classList.add(this.CSS.wrapper); // Input

      this.nodes.input = document.createElement('input');
      this.nodes.input.placeholder = 'https://...';
      this.nodes.input.classList.add(this.CSS.input);
      var i; // Target

      this.nodes.selectTarget = document.createElement('select');
      this.nodes.selectTarget.classList.add(this.CSS.selectTarget);
      this.addOption(this.nodes.selectTarget, this.i18n.t('Select target'), '');

      for (i = 0; i < this.targetAttributes.length; i++) {
        this.addOption(this.nodes.selectTarget, this.targetAttributes[i], this.targetAttributes[i]);
      }

      if (!!this.config.target) {
        this.nodes.selectTarget.value = this.config.target;
      } // Rel


      this.nodes.selectRel = document.createElement('select');
      this.nodes.selectRel.classList.add(this.CSS.selectRel);
      this.addOption(this.nodes.selectRel, this.i18n.t('Select rel'), '');

      for (i = 0; i < this.relAttributes.length; i++) {
        this.addOption(this.nodes.selectRel, this.relAttributes[i], this.relAttributes[i]);
      }

      if (!!this.config.rel) {
        this.nodes.selectRel.value = this.config.rel;
      } // Button


      this.nodes.buttonSave = document.createElement('button');
      this.nodes.buttonSave.type = 'button';
      this.nodes.buttonSave.classList.add(this.CSS.buttonSave);
      this.nodes.buttonSave.innerHTML = this.i18n.t('Save');
      this.nodes.buttonSave.addEventListener('click', function (event) {
        _this.savePressed(event);
      }); // append

      this.nodes.wrapper.appendChild(this.nodes.input);
      this.nodes.wrapper.appendChild(this.nodes.selectTarget);
      this.nodes.wrapper.appendChild(this.nodes.selectRel);
      this.nodes.wrapper.appendChild(this.nodes.buttonSave);
      return this.nodes.wrapper;
    }
  }, {
    key: "surround",
    value: function surround(range) {
      if (range) {
        if (!this.inputOpened) {
          this.selection.setFakeBackground();
          this.selection.save();
        } else {
          this.selection.restore();
          this.selection.removeFakeBackground();
        }

        var parentAnchor = this.selection.findParentTag('A');

        if (parentAnchor) {
          this.selection.expandToTag(parentAnchor);
          this.unlink();
          this.closeActions();
          this.checkState();
          this.toolbar.close();
          return;
        }
      }

      this.toggleActions();
    }
  }, {
    key: "checkState",
    value: function checkState() {
      var anchorTag = this.selection.findParentTag('A');

      if (anchorTag) {
        this.nodes.button.classList.add(this.CSS.buttonUnlink);
        this.nodes.button.classList.add(this.CSS.buttonActive);
        this.openActions();
        var hrefAttr = anchorTag.getAttribute('href');
        var targetAttr = anchorTag.getAttribute('target');
        var relAttr = anchorTag.getAttribute('rel');
        this.nodes.input.value = !!hrefAttr ? hrefAttr : '';
        this.nodes.selectTarget.value = !!targetAttr ? targetAttr : '';
        this.nodes.selectRel.value = !!relAttr ? relAttr : '';
        this.selection.save();
      } else {
        this.nodes.button.classList.remove(this.CSS.buttonUnlink);
        this.nodes.button.classList.remove(this.CSS.buttonActive);
      }

      return !!anchorTag;
    }
  }, {
    key: "clear",
    value: function clear() {
      this.closeActions();
    }
  }, {
    key: "toggleActions",
    value: function toggleActions() {
      if (!this.inputOpened) {
        this.openActions(true);
      } else {
        this.closeActions(false);
      }
    }
  }, {
    key: "openActions",
    value: function openActions() {
      var needFocus = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : false;
      this.nodes.wrapper.classList.add(this.CSS.wrapperShowed);

      if (needFocus) {
        this.nodes.input.focus();
      }

      this.inputOpened = true;
    }
  }, {
    key: "closeActions",
    value: function closeActions() {
      var clearSavedSelection = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : true;

      if (this.selection.isFakeBackgroundEnabled) {
        var currentSelection = new _SelectionUtils__WEBPACK_IMPORTED_MODULE_0__["default"]();
        currentSelection.save();
        this.selection.restore();
        this.selection.removeFakeBackground();
        currentSelection.restore();
      }

      this.nodes.wrapper.classList.remove(this.CSS.wrapperShowed);
      this.nodes.input.value = '';
      this.nodes.selectTarget.value = '';
      this.nodes.selectRel.value = '';

      if (clearSavedSelection) {
        this.selection.clearSaved();
      }

      this.inputOpened = false;
    }
  }, {
    key: "savePressed",
    value: function savePressed(event) {
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      var value = this.nodes.input.value || '';
      var target = this.nodes.selectTarget.value || '';
      var rel = this.nodes.selectRel.value || '';

      if (!value.trim()) {
        this.selection.restore();
        this.unlink();
        event.preventDefault();
        this.closeActions();
      } // if (!this.validateURL(value)) {
      //     this.tooltip.show(this.nodes.input, 'Pasted link is not valid.', {
      //         placement: 'top',
      //     });
      //     setTimeout(() => {
      //         this.tooltip.hide();
      //     }, 1000);
      //     return;
      // }


      value = this.prepareLink(value);
      this.selection.restore();
      this.selection.removeFakeBackground();
      this.insertLink(value, target, rel);
      this.selection.collapseToEnd();
      this.inlineToolbar.close();
    }
  }, {
    key: "validateURL",
    value: function validateURL(str) {
      // 365CMS: Längenlimit + eindeutige Label-Definition (keine verschachtelten Quantoren) gegen ReDoS (CodeQL js/redos).
      if (typeof str !== 'string' || str.length > 2048) {
        return false;
      }
      var pattern = new RegExp('^(https?:\\/\\/)?' + // protocol
      '(([a-z\\d](?:[a-z\\d-]{0,61}[a-z\\d])?\\.)+[a-z]{2,}|' + // domain name
      '((\\d{1,3}\\.){3}\\d{1,3}))' + // OR ip (v4) address
      '(\\:\\d+)?(\\/[-a-z\\d%_.~+]*)*' + // port and path
      '(\\?[;&a-z\\d%_.~+=-]*)?' + // query string
      '(\\#[-a-z\\d_]*)?$', 'i'); // fragment locator

      return !!pattern.test(str);
    }
  }, {
    key: "prepareLink",
    value: function prepareLink(link) {
      link = link.trim();
      link = this.addProtocol(link);
      return link;
    }
  }, {
    key: "addProtocol",
    value: function addProtocol(link) {
      if (/^(\w+):(\/\/)?/.test(link)) {
        return link;
      }

      var isInternal = /^\/[^/\s]/.test(link),
          isAnchor = link.substring(0, 1) === '#',
          isProtocolRelative = /^\/\/[^/\s]/.test(link);

      if (!isInternal && !isAnchor && !isProtocolRelative) {
        link = 'http://' + link;
      }

      return link;
    }
  }, {
    key: "insertLink",
    value: function insertLink(link) {
      var target = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : '';
      var rel = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : '';
      var anchorTag = this.selection.findParentTag('A');

      if (anchorTag) {
        this.selection.expandToTag(anchorTag);
      } else {
        document.execCommand(this.commandLink, false, link);
        anchorTag = this.selection.findParentTag('A');
      }

      if (anchorTag) {
        if (!!target) {
          anchorTag['target'] = target;
        } else {
          anchorTag.removeAttribute('target');
        }

        if (!!rel) {
          anchorTag['rel'] = rel;
        } else {
          anchorTag.removeAttribute('rel');
        }
      }
    }
  }, {
    key: "unlink",
    value: function unlink() {
      document.execCommand(this.commandUnlink);
    }
  }, {
    key: "iconSvg",
    value: function iconSvg(name) {
      var width = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : 14;
      var height = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : 14;
      var icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      icon.classList.add('icon', 'icon--' + name);
      icon.setAttribute('width', width + 'px');
      icon.setAttribute('height', height + 'px');
      icon.innerHTML = "<use xmlns:xlink=\"http://www.w3.org/1999/xlink\" xlink:href=\"#".concat(name, "\"></use>");
      return icon;
    }
  }, {
    key: "addOption",
    value: function addOption(element, text) {
      var value = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : null;
      var option = document.createElement('option');
      option.text = text;
      option.value = value;
      element.add(option);
    }
  }, {
    key: "shortcut",
    get: function get() {
      return this.config.shortcut || 'CMD+L';
    }
  }, {
    key: "title",
    get: function get() {
      return 'Hyperlink';
    }
  }], [{
    key: "isInline",
    get: function get() {
      return true;
    }
  }, {
    key: "sanitize",
    get: function get() {
      return {
        a: {
          href: true,
          target: true,
          rel: true
        }
      };
    }
  }]);

  return Hyperlink;
}();




;

/***/ }),

/***/ "./src/SelectionUtils.js":
/*!*******************************!*\
  !*** ./src/SelectionUtils.js ***!
  \*******************************/
/*! exports provided: default */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";

__webpack_require__.r(__webpack_exports__);
/* harmony export (binding) */ __webpack_require__.d(__webpack_exports__, "default", function() { return SelectionUtils; });
function _typeof(obj) { "@babel/helpers - typeof"; if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") { _typeof = function _typeof(obj) { return typeof obj; }; } else { _typeof = function _typeof(obj) { return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }; } return _typeof(obj); }

function _classCallCheck(instance, Constructor) { if (!(instance instanceof Constructor)) { throw new TypeError("Cannot call a class as a function"); } }

function _defineProperties(target, props) { for (var i = 0; i < props.length; i++) { var descriptor = props[i]; descriptor.enumerable = descriptor.enumerable || false; descriptor.configurable = true; if ("value" in descriptor) descriptor.writable = true; Object.defineProperty(target, descriptor.key, descriptor); } }

function _createClass(Constructor, protoProps, staticProps) { if (protoProps) _defineProperties(Constructor.prototype, protoProps); if (staticProps) _defineProperties(Constructor, staticProps); return Constructor; }

var SelectionUtils = /*#__PURE__*/function () {
  function SelectionUtils() {
    _classCallCheck(this, SelectionUtils);

    this.selection = null;
    this.savedSelectionRange = null;
    this.isFakeBackgroundEnabled = false;
    this.commandBackground = 'backColor';
    this.commandRemoveFormat = 'removeFormat';
  }

  _createClass(SelectionUtils, [{
    key: "isElement",
    value: function isElement(node) {
      return node && _typeof(node) === 'object' && node.nodeType && node.nodeType === Node.ELEMENT_NODE;
    }
  }, {
    key: "isContentEditable",
    value: function isContentEditable(element) {
      return element.contentEditable === 'true';
    }
  }, {
    key: "isNativeInput",
    value: function isNativeInput(target) {
      var nativeInputs = ['INPUT', 'TEXTAREA'];
      return target && target.tagName ? nativeInputs.includes(target.tagName) : false;
    }
  }, {
    key: "canSetCaret",
    value: function canSetCaret(target) {
      var result = true;

      if (this.isNativeInput(target)) {
        switch (target.type) {
          case 'file':
          case 'checkbox':
          case 'radio':
          case 'hidden':
          case 'submit':
          case 'button':
          case 'image':
          case 'reset':
            result = false;
            break;

          default:
        }
      } else {
        result = this.isContentEditable(target);
      }

      return result;
    }
  }, {
    key: "CSS",
    value: function CSS() {
      return {
        editorWrapper: 'codex-editor',
        editorZone: 'codex-editor__redactor'
      };
    }
  }, {
    key: "anchorNode",
    value: function anchorNode() {
      var selection = window.getSelection();
      return selection ? selection.anchorNode : null;
    }
  }, {
    key: "anchorElement",
    value: function anchorElement() {
      var selection = window.getSelection();

      if (!selection) {
        return null;
      }

      var anchorNode = selection.anchorNode;

      if (!anchorNode) {
        return null;
      }

      if (!this.isElement(anchorNode)) {
        return anchorNode.parentElement;
      } else {
        return anchorNode;
      }
    }
  }, {
    key: "anchorOffset",
    value: function anchorOffset() {
      var selection = window.getSelection();
      return selection ? selection.anchorOffset : null;
    }
  }, {
    key: "isCollapsed",
    value: function isCollapsed() {
      var selection = window.getSelection();
      return selection ? selection.isCollapsed : null;
    }
  }, {
    key: "isAtEditor",
    value: function isAtEditor() {
      var selection = SelectionUtils.get();
      var selectedNode = selection.anchorNode || selection.focusNode;

      if (selectedNode && selectedNode.nodeType === Node.TEXT_NODE) {
        selectedNode = selectedNode.parentNode;
      }

      var editorZone = null;

      if (selectedNode) {
        editorZone = selectedNode.closest(".".concat(SelectionUtils.CSS.editorZone));
      }

      return editorZone && editorZone.nodeType === Node.ELEMENT_NODE;
    }
  }, {
    key: "isSelectionExists",
    value: function isSelectionExists() {
      var selection = SelectionUtils.get();
      return !!selection.anchorNode;
    }
  }, {
    key: "get",
    value: function get() {
      return window.getSelection();
    }
  }, {
    key: "setCursor",
    value: function setCursor(element) {
      var offset = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : 0;
      var range = document.createRange();
      var selection = window.getSelection();

      if (this.isNativeInput(element)) {
        if (!this.canSetCaret(element)) {
          return;
        }

        element.focus();
        element.selectionStart = element.selectionEnd = offset;
        return element.getBoundingClientRect();
      }

      range.setStart(element, offset);
      range.setEnd(element, offset);
      selection.removeAllRanges();
      selection.addRange(range);
      return range.getBoundingClientRect();
    }
  }, {
    key: "removeFakeBackground",
    value: function removeFakeBackground() {
      if (!this.isFakeBackgroundEnabled) {
        return;
      }

      this.isFakeBackgroundEnabled = false;
      document.execCommand(this.commandRemoveFormat);
    }
  }, {
    key: "setFakeBackground",
    value: function setFakeBackground() {
      document.execCommand(this.commandBackground, false, '#a8d6ff');
      this.isFakeBackgroundEnabled = true;
    }
  }, {
    key: "save",
    value: function save() {
      this.savedSelectionRange = SelectionUtils.range;
    }
  }, {
    key: "restore",
    value: function restore() {
      if (!this.savedSelectionRange) {
        return;
      }

      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(this.savedSelectionRange);
    }
  }, {
    key: "clearSaved",
    value: function clearSaved() {
      this.savedSelectionRange = null;
    }
  }, {
    key: "collapseToEnd",
    value: function collapseToEnd() {
      var sel = window.getSelection();
      var range = document.createRange();
      range.selectNodeContents(sel.focusNode);
      range.collapse(false);
      sel.removeAllRanges();
      sel.addRange(range);
    }
  }, {
    key: "findParentTag",
    value: function findParentTag(tagName) {
      var className = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : null;
      var searchDepth = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : 10;
      var selection = window.getSelection();
      var parentTag = null;

      if (!selection || !selection.anchorNode || !selection.focusNode) {
        return null;
      }

      var boundNodes = [selection.anchorNode, selection.focusNode];
      boundNodes.forEach(function (parent) {
        var searchDepthIterable = searchDepth;

        while (searchDepthIterable > 0 && parent.parentNode) {
          if (parent.tagName === tagName) {
            parentTag = parent;

            if (className && parent.classList && !parent.classList.contains(className)) {
              parentTag = null;
            }

            if (parentTag) {
              break;
            }
          }

          parent = parent.parentNode;
          searchDepthIterable--;
        }
      });
      return parentTag;
    }
  }, {
    key: "expandToTag",
    value: function expandToTag(element) {
      var selection = window.getSelection();
      selection.removeAllRanges();
      var range = document.createRange();
      range.selectNodeContents(element);
      selection.addRange(range);
    }
  }], [{
    key: "range",
    get: function get() {
      var selection = window.getSelection();
      return selection && selection.rangeCount ? selection.getRangeAt(0) : null;
    }
  }, {
    key: "rect",
    get: function get() {
      var sel = document.selection,
          range;
      var rect = {
        x: 0,
        y: 0,
        width: 0,
        height: 0
      };

      if (sel && sel.type !== 'Control') {
        range = sel.createRange();
        rect.x = range.boundingLeft;
        rect.y = range.boundingTop;
        rect.width = range.boundingWidth;
        rect.height = range.boundingHeight;
        return rect;
      }

      if (!window.getSelection) {
        return rect;
      }

      sel = window.getSelection();

      if (sel.rangeCount === null || isNaN(sel.rangeCount)) {
        return rect;
      }

      if (sel.rangeCount === 0) {
        return rect;
      }

      range = sel.getRangeAt(0).cloneRange();

      if (range.getBoundingClientRect) {
        rect = range.getBoundingClientRect();
      }

      if (rect.x === 0 && rect.y === 0) {
        var span = document.createElement('span');

        if (span.getBoundingClientRect) {
          span.appendChild(document.createTextNode("\u200B"));
          range.insertNode(span);
          rect = span.getBoundingClientRect();
          var spanParent = span.parentNode;
          spanParent.removeChild(span);
          spanParent.normalize();
        }
      }

      return rect;
    }
  }, {
    key: "text",
    get: function get() {
      return window.getSelection ? window.getSelection().toString() : '';
    }
  }]);

  return SelectionUtils;
}();




;

/***/ })

/******/ })["default"];
});