
// skybox and ajax
(function(window, undefined) {

    // set up globals
    window.firstStateChange = true;
    window.skyboxHideOnSuccess = null;
    window.handleStateChange;
    window.handleHashChange;

    // we are using a capital H instead of a lower h
    var History = window.History,
        State = History.getState(),
        redirectIfHashState = function() {
            var l = window.location, hash;
            if (l.hash.substring(0, 2) != '#/') return;

            hash = l.hash.substring(1);
            if (hash != l.pathname) location.href = hash;
        };

    handleStateChange = function() {

        var State = History.getState(),
            url = State.url,
            skyboxURL = $.skyboxURL(),
            isAjaxPage = $('body').hasClass('ajax');

        if (isAjaxPage) {
            if (!firstStateChange || $.browser.mozilla) ajaxPageLoad(url);
            redirectIfHashState();
        } else if (skyboxURL) {
            $.skyboxShow(skyboxURL);
        } else if (!firstStateChange) {
            if ($.skyboxIsOpen()) $.skyboxHide();
        }

        firstStateChange = false;

    };

    handleHashChange = function() {
        if ($.skyboxURL() || !$.skyboxIsOpen()) return;
        $.skyboxHide();
    };

    // this does not listen for hash changes (html5)
    History.Adapter.bind(window, 'statechange', handleStateChange);

    /*
        If we are in an emulated History, bind hashchange to statechange
        otherwise we listen for pop state to remove the skybox if necessary
        because statechange does not support hash chnages
    */
    var i = (History.emulated.pushState) ? 1 : 0, // which event?
        events = [
            { e: 'popstate',    callback: handleHashChange  }, // if html5
            { e: 'hashchange',  callback: handleStateChange }  // if html4
        ];

    var bound = events[i];
    History.Adapter.bind(window, bound.e, bound.callback);


}) (window);

function ajaxPageLoad(url) {
    $('#page').fadeOut();
    $.post(url, { _json: 1, _no_template: 1}, function(json) {
        render_page(json, url);
    }).error(function(a) {
        window.location.href = url;
    });
}

function render_page( json, url, src_domain, divID ) {

    var p = sky.parseJSON(json, {
        div: {  page: escape(url) + ' is not a valid page.' }
    });

    divID = divID || 'page';

    if ( p == null ) {
        location.href = url;
        return;
    }

    document.title = p.title;

    var $p = $('#' + divID).html(''),
        fadeInCufon = function() {
            $p.fadeIn(function() {
                if (typeof Cufon != 'undefined') Cufon.refresh();
            });
        };

    // disable and remove previously dynamically loaded css
    $('link[rel=stylesheet][title=page]').each(function() {
        $(this).attr('disabled', true).replaceWith('');
    });

    sky.loader(p, $p, src_domain).load(fadeInCufon);

    if (typeof ajaxOnSuccess == 'undefined') return;
    sky.call(ajaxOnSuccess, null, json);

}

$(function(){

    var $skybox = $('#skybox'),
        $overlay = $('#overlay'),
        skybox_handle = '#skybox_drag_handle';

    // add drag capabilities to skybox if we have jquery.ui
    $(skybox_handle + ':visible').livequery(function() {
        if ($.hasUI('draggable')) $skybox.draggable({ handle: skybox_handle });
        else $(skybox_handle).hide();
    });

    // ajax
    var selector = 'body.ajax a[class!="noajax"]',
        progress = 'ajax-in-progress';

    $(selector).live('click',function(event) {

        var $this = $(this),
            url = $this.attr('href') || null,
            liveClickHandlers = $(document).data('events').click,
            hrefNotJS = function(uri) {  return url.substring(0,11) != 'javascript:'; },
            thisHandlers;

        $this.addClass(progress);

        thisHandlers = $.map(liveClickHandlers, function(h) {
            if (h.selector == selector) return null;
            if ( $(h.selector).filter('.' + progress).length > 0 ) return h.selector;
            return null;
        });

        $this.removeClass(progress);

        if (thisHandlers.length === 0 && !!url && hrefNotJS(url)) {
            window.History.pushState(null, null, url);
            return false;
        }

    });

    //skybox
    $(window).resize(function() {
        $skybox.center();
        $overlay.width($(document).width()).height($(document).height());
    });


    $('a[skybox]').live('click', function() {
        var $this = $(this),
            url = $this.attr('href'),
            w = $this.attr('skybox-width'),
            h = $this.attr('skybox-height');
        $.skybox(url, w, h);
        return false;
    });

    // close skybox on ESC key up
    $(document).keyup(function(e) {

        if (e.keyCode != 27 || !$.skyboxIsOpen()) return;
        if ($.skyboxURL()) history.back();
        else $.skyboxHide();

    });

    // handle the state of the initial page load
    handleStateChange();

});


(function($){

    /*
        checks for jquery ui extension and any plugins
        ie:
            $.hasUI('draggable') // true when the page has it
            $.hasUI('thiswillnever__dadfasf') // always false
    */
    $.hasUI = function() {
        var isund = function(a) { return typeof a == 'undefined'; }, args = [], i;
        if (isund($.ui)) return false;
        args = [].slice.call(arguments);
        for (i = 0; i < args.length; i++) if (isund($.ui[args[i]])) return false;
        return true;
    };

    $.fn.serializeObject = function() {

        var o = {}, a = this.serializeArray();

        $.each(a, function() {

            var val = this.value || '';

            if (o[this.name]) {
                if (!o[this.name].push) o[this.name] = [o[this.name]];
                o[this.name].push(val);
            } else {
                o[this.name] = val;
            }

        });

        return o;
    };

    /*
     *  skybox(url)
     *  skybox(url,width)
     *  skybox(url,width,height)
     *  skybox(url,post)
     *  skybox(url,post,width)
     *  skybox(url,post,width,height)
    **/
    $.skybox = function(a,b,c,d) {

        var $skybox = $('#skybox'),
            skyboxURL = a,
            w, h, post, uri, search;

        if (b) {
            if (isNumeric(b)) {
                w = b;
                h = c;
            } else {
                post = b;
                w = c;
                h = d;
            }
        }

        search = location.hash.substring(0, 2) == '#/'
            ? location.hash.substring(1)
            : location.pathname + location.search;

        uri = addParam('skybox', skyboxURL, search);

        if (!uri) $.error('$.skybox() expects a URI parameter.');

        History.pushState(null,null,uri,true);
        if (w) $skybox.width(w);
        if (h)  $skybox.height(h);
        if (post) $.skyboxShow(skyboxURL, post);

    };

    $.skyboxURL = function() {

        // is html4/5?
        var qs = (location.hash.substring(0, 2) == '#/')
            ? '?' + decodeURIComponent(location.hash.substring(1)).split('?')[1]
            : null;

        return getParam('skybox', qs);

    };

    window.skybox = $.skybox;

    $.skyboxIsOpen = function() {

        var checkIsVisbile = ['#skybox', '#overlay'], i;

        for (i = 0; i < checkIsVisbile.length; i++){
            if ($(checkIsVisbile[i]).css('opacity') > 0) return true;
        }

        return false;

    };

    // used to keep a log of requests so if a request is made to the same url
    // prior request will be aborted
    $.skyboxQueue = {};

    $.skyboxShow = function(url, data) {

        // exit if empty url
        if (!url) {
            $.error('$.skyboxShow was called without a URL/HTML parameter.');
            return;
        }

        var $skybox = $('#skybox').html('');

        function finishSkybox() {
            $skybox.center().fadeIn('fast', function() {
                $(this).center().trigger('skybox_shown');
                $(window).resize();
            });
        }

        // set the overlay to visible
        (function($overlay) {
            var w = $(window).width(),
                h = $(document).height(),
                css = {
                    width: w,
                    height: h,
                    backgroundColor: '#999',
                    display: 'block'
                };
            $overlay.stop().css(css).fadeTo('fast', 0.6);
        }) ($('#overlay'));

        // if url is html
        if (url.match(/</)) {
            $skybox.html(url);
            finishSkybox();
            return;
        }

        // else post to get skybox content
        data = data || {};
        data['_json'] = 1;

        // clear queue for this request if there is more than one
        $.skyboxQueue[url] = $.skyboxQueue[url] || [];
        $.each($.skyboxQueue[url], function(i, req) {
            req.abort();
        });

        function getDefaultJson(uri) {

            var str = '(<a href="' + uri + '" target="_blank">' + uri + '</a>)'
                    + ' is not a valid page!'
                    + '<hr />'
                    + '<a href="javascript:history.back()">back</a>';

            str = '<div class="aql_error">' + str + '</div>';

            return { div: { page: str } };

        }

        $.skyboxQueue[url].push($.post(url, data, function(json) {

            var p = sky.parseJSON(json, getDefaultJson(escape(url)));
            sky.loader(p, '#skybox').load(finishSkybox);

        })); // end push to queue.
    };

    $.abortSkyboxRequests = function() {

        $.each($.skyboxQueue, function(url, reqs) {

            // abort all requests
            $.each(reqs, function(i, req) {
                req.abort();
            });

        });

        $.skyboxQueue = {};

    };

    $.skyboxHide = function(fn) {

        var $skybox = $('#skybox'),
            $overlay = $('#overlay');

        function emptyOverlayAndSkybox() {
            $overlay.fadeOut('slow', function() {
                $skybox.html('').css({ width: '' });
                if (sky.call(skyboxHideOnSuccess)) { skyboxHideOnSuccess = null; }
                sky.call(fn);
            });
            $skybox.attr('class', '');
            $.abortSkyboxRequests();
        }

        if ($skybox.is(':visible')) {
            $skybox.fadeOut('fast', emptyOverlayAndSkybox);
        } else {
            emptyOverlayAndSkybox();
        }

    };

    jQuery.fn.center = function ($div) {

        $div = $div || $(window);

        var $window = $(window),
            top = ( $window.height() - this.height() ) / 2 + $window.scrollTop(),
            left = ( $div.width() - this.width() ) / 2 + $div.scrollLeft();

        top = (top < 5) ? 5 : top;
        left = (left < 5) ? 5 : left;

        return this.css({
            position: 'absolute',
            top: top + 'px',
            left: left + 'px'
        });

    };

    jQuery.fn.ajaxRefresh = function (p_json) {
        var div = this,
            url = this.attr('ajax');
        if (!url) return;
        // maybe we should post _json:1 and get json.page ?
        $.post(url,{_p:p_json},function(html) {
            div.fadeTo('fast',0.01);
            div.html(html);
            div.fadeTo('fast',1);
        });
    };

    jQuery.fn.aqlSave = function(model, data, callbacks) {
        if (!callbacks) callbacks = {};
        callbacks.messageDiv = this;
        aql.save(model, data, callbacks);
        return this;
    };

    jQuery.fn.aqlRemove = function(model, data, callbacks) {
        if (!callbacks) callbacks = {};
        callbacks.messageDiv = this;
        aql.remove(model, data, callbacks);
        return this;
    };

    jQuery.fn.loadSelectOptions = function(url, data, fn) {

        function prepareData(data, d) {

            if (data) {
                data.unshift(d);
            } else {
                data = [ { value: 0, name: 'n/a' } ];
            }

            return data;

        }

        function makeHandler($select, def, error) {

            return {
                success: function() {
                    $select.selectOptions( prepareData(this.json.data, def), fn );
                },
                error: function() {
                    $select.selectOptions(error);
                }
            };

        }

        return this.each(function() {

            var $this = $(this),
                name = ($this.attr('display_name')) ? $this.attr('display_name') + ' ' : null,
                load = [ { value: 0, name: 'loading...' } ],
                no_url = [ { value: 0, name: (name) ? name : '--' } ],
                error = [ { value: 0, name: 'ERROR LOADING' } ],
                def =  { value: 0, name: '-- Choose ' + name + '--' },
                handle = makeHandler($this, def, error);

            if (!url) {
                $this.selectOptions(no_url);
                return;
            } else {
                $this.selectOptions(load);
                aql.save(url, data, makeHandler($this, def, error));
            }

        });
    };

    jQuery.fn.selectOptions = function(json, fn) {
        json = json || [];
        return this.each(function() {
           var $this = $(this);
           if (!$this.is('select')) {
               $.error('selectOptions works only on select elements. ' + $this.get(0).tagName + ' given.');
               return;
           }
           $this.html(linkedSelects.optsFromJson(json));
           sky.call(fn, $this, $this);
        });
    };

})( jQuery );


// jQuery outerHTML
// http://darlesson.com/jquery/outerhtml/
(function($){$.fn.extend({outerHTML:function(value){if(typeof value==="string"){var $this=$(this),$parent=$this.parent();var replaceElements=function(){var $img=$this.find("img");if($img.length>0){$img.remove();}
var element;$(value).map(function(){element=$(this);$this.replaceWith(element);})
return element;}
return replaceElements();}else{return $("<div />").append($(this).clone()).html();}}});})(jQuery);


/*

https://github.com/furf/jquery-getCSS
$.getCSS(url,options,onsuccess)
$.getCSS(url,onsuccess)

 **/
(function (window, document, jQuery) {

  var head = document.getElementsByTagName('head')[0],
      loadedCompleteRegExp = /loaded|complete/,
      callbacks = {},
      callbacksNb = 0,
      timer;

  jQuery.getCSS = function (url, options, callback) {

    if (jQuery.isFunction(options)) {
      callback = options;
      options  = {};
    }

    if (!options) options = {};
    if (!callback) callback = function() {};

    $('head link[rel=stylesheet]').each(function() {
        var $this = $(this),
            str = $this.attr('href').split('?')[0];
        if (str == url) {
            $this.attr('disabled', true);
            $this.remove();
        }
    });

    var link = document.createElement('link');

    link.rel   = 'stylesheet';
    link.type  = 'text/css';
    link.href  = url + '?' + Math.floor(Math.random()*100);
    link.media = options.media || 'screen';

    if (options.charset) {
      link.charset = options.charset;
    }

    if (options.title) {
      callback = (function (callback) {
        return function () {
          link.title = options.title;
          callback(link, "success");
        };
      })(callback);
    }

    // onreadystatechange
    if (link.readyState) {

      link.onreadystatechange = function () {
        if (loadedCompleteRegExp.test(link.readyState)) {
          link.onreadystatechange = null;
          callback(link, "success");
        }
      };

    // If onload is available, use it
    } else if (link.onload === null /* exclude Webkit => */ && link.all) {
      link.onload = function () {
        link.onload = null;
        callback(link, "success");
      };

    // In any other browser, we poll
    } else {

      callbacks[link.href] = function () {
        callback(link, "success");
      };

      if (!callbacksNb++) {
        // poll(cssPollFunction);

        timer = window.setInterval(function () {

          var callback,
              stylesheet,
              stylesheets = document.styleSheets,
              href,
              i = stylesheets.length;

          while (i--) {
            stylesheet = stylesheets[i];
            if ((href = stylesheet.href) && (callback = callbacks[href])) {
              try {
                // We store so that minifiers don't remove the code
                callback.r = stylesheet.cssRules;
                // Webkit:
                // Webkit browsers don't create the stylesheet object
                // before the link has been loaded.
                // When requesting rules for crossDomain links
                // they simply return nothing (no exception thrown)
                // Gecko:
                // NS_ERROR_DOM_INVALID_ACCESS_ERR thrown if the stylesheet is not loaded
                // If the stylesheet is loaded:
                //  * no error thrown for same-domain
                //  * NS_ERROR_DOM_SECURITY_ERR thrown for cross-domain
                throw 'SECURITY';
              } catch(e) {
                // Gecko: catch NS_ERROR_DOM_SECURITY_ERR
                // Webkit: catch SECURITY
                if (/SECURITY/.test(e)) {

                  // setTimeout(callback, 0);
                  callback(link, "success");

                  delete callbacks[href];

                  if (!--callbacksNb) {
                    timer = window.clearInterval(timer);
                  }

                }
              }
            }
          }
        }, 13);
      }
    }
    head.appendChild(link);
  };

})(this, this.document, this.jQuery);

function skybox_alert(text) {

    var htm = '<div style="padding:10px; background: #fff;">'
            + '<div style="padding-bottom:20px">' + text + '</div>'
            + '<a href="javascript:void(0)" onclick="$.skyboxHide()">close</a>'
            + '</div>';

    $.skyboxShow(htm);

}


function addParam(param, value, url) {
    if (!url) url = location.href;
    if (url.lastIndexOf('?') <= 0) url = url + "?";

    if (!value) return null;

    var re = new RegExp("([?|&])" + param + "=.*?(&|$)", "i");
    if (url.match(re)) {
        return url.replace(re, '$1' + param + "=" + value + '$2');
    } else {
        return url.substring(url.length - 1) == '?'
            ? url + param + "=" + value
            : url + '&' + param + "=" + value;
    }
}

function getParam( name, url ) {

    // defaults
    url = url || location.href;
    name = name || '';
    name = name.replace(/[\[]/,'\\\[').replace(/[\]]/,'\\\]');

    var reg = new RegExp( '[\\?&]' + name + '=([^&#]*)'),
        results = reg.exec(url);

    return (results === null) ? '' : results[1];

}

function removeParam(param,url) {

    url = url || location.href;

    return (function(parts) {

        if (parts.length < 2) return url;

        var prefix = encodeURIComponent(param) + '=',
            pars = parts[1].split(/[&;]/g),
            i = pars.length;

        while (i > 0) {
            i--;
            if (pars[i].indexOf(prefix, 0) === 0) pars.splice(i, 1);
        }

        return (pars.length > 0) ? parts[0] + '?' + pars.join('&') : parts[0];

    }) (url.split('?'));

}

function isNumeric(n) {
    return !isNaN(parseFloat(n)) && isFinite(n);
}

function add_javascript(file, fn) {
    $.getScript(file, fn);
}

function add_js(file, fn) {
    add_javascript(file, fn);
}

var loadLinkedSelects = linkedSelects.load,
    select_options_from_json = linkedSelects.optsFromJson;

if (typeof console === 'undefined' || typeof console.log === 'undefined') {
    console = {};
    console.log = function() { };
    console.dir = function() { };
}

if (typeof page_js_includes == 'undefined') {
    var page_js_includes = [];
}

// Production steps of ECMA-262, Edition 5, 15.4.4.19
// Reference: http://es5.github.com/#x15.4.4.19
if (!Array.prototype.map) {
  Array.prototype.map = function(callback, thisArg) {

    var T, A, k;

    if (this === null) {
      throw new TypeError(" this is null or not defined");
    }

    // 1. Let O be the result of calling ToObject passing the |this| value as the argument.
    var O = Object(this);

    // 2. Let lenValue be the result of calling the Get internal method of O with the argument "length".
    // 3. Let len be ToUint32(lenValue).
    var len = O.length >>> 0;

    // 4. If IsCallable(callback) is false, throw a TypeError exception.
    // See: http://es5.github.com/#x9.11
    if ({}.toString.call(callback) != "[object Function]") {
      throw new TypeError(callback + " is not a function");
    }

    // 5. If thisArg was supplied, let T be thisArg; else let T be undefined.
    if (thisArg) {
      T = thisArg;
    }

    // 6. Let A be a new array created as if by the expression new Array(len) where Array is
    // the standard built-in constructor with that name and len is the value of len.
    A = new Array(len);

    // 7. Let k be 0
    k = 0;

    // 8. Repeat, while k < len
    while(k < len) {

      var kValue, mappedValue;

      // a. Let Pk be ToString(k).
      //   This is implicit for LHS operands of the in operator
      // b. Let kPresent be the result of calling the HasProperty internal method of O with argument Pk.
      //   This step can be combined with c
      // c. If kPresent is true, then
      if (k in O) {

        // i. Let kValue be the result of calling the Get internal method of O with argument Pk.
        kValue = O[ k ];

        // ii. Let mappedValue be the result of calling the Call internal method of callback
        // with T as the this value and argument list containing kValue, k, and O.
        mappedValue = callback.call(T, kValue, k, O);

        // iii. Call the DefineOwnProperty internal method of A with arguments
        // Pk, Property Descriptor {Value: mappedValue, Writable: true, Enumerable: true, Configurable: true},
        // and false.

        // In browsers that support Object.defineProperty, use the following:
        // Object.defineProperty(A, Pk, { value: mappedValue, writable: true, enumerable: true, configurable: true });

        // For best browser support, use the following:
        A[ k ] = mappedValue;
      }
      // d. Increase k by 1.
      k++;
    }

    // 9. return A
    return A;
  };
}

/**
 * Copyright (c) Mozilla Foundation http://www.mozilla.org/
 * This code is available under the terms of the MIT License
 */
if (!Array.prototype.filter) {
    Array.prototype.filter = function(fun /*, thisp*/) {
        var len = this.length >>> 0;
        if (typeof fun != "function") {
            throw new TypeError();
        }

        var res = [];
        var thisp = arguments[1];
        for (var i = 0; i < len; i++) {
            if (i in this) {
                var val = this[i]; // in case fun mutates this
                if (fun.call(thisp, val, i, this)) {
                    res.push(val);
                }
            }
        }

        return res;
    };
}
