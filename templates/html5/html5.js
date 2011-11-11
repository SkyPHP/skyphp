// skybox and ajax
firstStateChange = true;
skyboxHideOnSuccess = null;
(function(window,undefined){
    var History = window.History; // we are using a capital H instead of a lower h
    var State = History.getState();
    handleStateChange = function() {
        var State = History.getState();
        url = State.url;
        if ( location.hash.substring(0,2)=='#/' ) {
            // html4
            qs = '?' + decodeURIComponent(location.hash.substring(1)).split('?')[1];
            skyboxURL = getParam('skybox',qs);
        } else {
            // html5
            skyboxURL = getParam('skybox');
        }
        //console.log(firstStateChange);
        if ( skyboxURL ) {
            $.skyboxShow(skyboxURL);
        } else if (!firstStateChange) {
            //console.log( 'hide skybox' );
            if ( $('#skybox:visible,#overlay:visible').length ) {
                $.skyboxHide();
            } else if ( $('body').hasClass('ajax') ) {
                ajaxPageLoad(url);
            }
        } else {
            if ( $('body').hasClass('ajax') ) {
                // first state change -- inital page load
                //console.log(location);
                // redirect to the proper url if directly navigating to a hashstate
                if ( window.location.hash.substring(0,2) == '#/' ) {
                    hashpath = window.location.hash.substring(1);
                    if ( hashpath != window.location.pathname ) location.href = window.location.hash.substring(1);
                } else if ( $.browser.mozilla ) {
                    ajaxPageLoad(url);
                }
            }
        }
        firstStateChange = false;
    }
    History.Adapter.bind(window,'statechange',handleStateChange); // this does not listen for hash changes
    if ( History.emulated.pushState ) History.Adapter.bind(window,'hashchange',handleStateChange); // html4 browsers only
    
})(window);

function ajaxPageLoad(url) {
    $('#page').fadeOut();
    $.post(url, {_json:1,_no_template:1}, function(json){
        render_page(json);
    }).error(function(a) {
        location.href = url;
    });
}

function render_page( json, url, src_domain ) {
    var p = aql.parseJSON(json, { 
        div: {  page: escape(url) + ' is not a valid page.' }  
    });    
    if ( p != null ) {
        document.title = p.title;
        var $p = $('#page');
        $p.html('');

        // disable and remove previously dynamically loaded css
        $('link[rel=stylesheet]').each(function(){
            if ( $(this).attr('title') == 'page' ) {
                //console.log('disabled ' + $(this).attr('href') );
                $(this).attr('disabled',true);
                $(this).replaceWith('');
            }
        });

        aql.loader(p, $p, src_domain).load(function() {
            $p.fadeIn(function() {
                if (typeof Cufon != 'undefined') {
                    Cufon.refresh();
                }
            });
        });

        if (typeof ajaxOnSuccess != 'undefined') aql._callback(ajaxOnSuccess, null, json);

    } else {
        location.href = url;
    }
}

$(function(){

    $('#skybox_drag_handle:visible').livequery(function() {
        if (typeof $.ui.draggable != 'function') {
            $('#skybox_drag_handle').hide();
        } else {
            $('#skybox').draggable({
                handle: '#skybox_drag_handle'
            });
        }
    });

    // ajax
    selector = 'body.ajax a[class!="noajax"]';
    $(selector).live('click',function(event){
        $(this).addClass('ajax-in-progress');
        url = $(this).attr('href');
        liveClickHandlers = $(document).data('events').click;
        thisHandlers = $.map(liveClickHandlers, function(handler) {
            if ( handler.selector == selector ) return null;
            if ( $(handler.selector).filter('.ajax-in-progress').length > 0 ) return handler.selector;
            return null;
        });
        $(this).removeClass('ajax-in-progress');
        if ( thisHandlers.length == 0 && typeof url != 'undefined' && url.substring(0,11) != 'javascript:' ) {
            window.History.pushState(null,null,url);
            return false;
        }
    });

    //skybox
    $(window).resize(function() {
      $('#skybox').center();
      $('#overlay').width($(document).width()).height($(document).height());
    });
   

    $('a[skybox]').live('click', function() {
        var $this = $(this),
            url = $this.attr('href'),
            w = $this.attr('skybox-width'),
            h = $this.attr('skybox-height');
        $.skybox(url, w, h);
        return false;
    });

    $(document).keyup(function(e) {
        if ($('#skybox:visible,#overlay:visible').length) {
            if (e.keyCode == 27) {
                if ( location.hash.substring(0,2)=='#/' ) {
                    // html4
                    qs = '?' + decodeURIComponent(location.hash.substring(1)).split('?')[1];
                    skyboxURL = getParam('skybox',qs);
                } else {
                    // html5
                    skyboxURL = getParam('skybox');
                }
                if (skyboxURL) {
                    history.back();
                } else {
                    $.skyboxHide();
                }
            }
        }
    });

    // handle the state of the initial page load
    handleStateChange();

});



(function($){

    $.fn.serializeObject = function() {
       var o = {};
       var a = this.serializeArray();
       $.each(a, function() {
           if (o[this.name]) {
               if (!o[this.name].push) {
                   o[this.name] = [o[this.name]];
               }
               o[this.name].push(this.value || '');
           } else {
               o[this.name] = this.value || '';
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
		var skyboxURL = a;
        var w, h, post;
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
        var uri = location.pathname + location.search;
        if ( location.hash.substring(0,2)=='#/' ) {
            uri = location.hash.substring(1);
        }
        uri = addParam('skybox',skyboxURL,uri);
        History.pushState(null,null,uri,true);
		if (w) $('#skybox').width(w);
        if (h) $('#skybox').height(h);
		if (post) $.skyboxShow(skyboxURL, post);
    };
    var skybox = $.skybox;
    $.skyboxIsOpen = function() {
        if ( $('#skybox').css('opacity') > 0 ) return true;
        else return false;
    };
    $.skyboxQueue = {};
    $.skyboxShow = function(url, data) {
        $('#overlay').width($(window).width()).height($(document).height()).css('backgroundColor','#999').show().fadeTo('fast', 0.6);
        var $skybox = $('#skybox'),
            finishSkybox = function() {
                $skybox.center().fadeIn('fast', function() {
                    $(this).center();
                });  
            };
        if (!url) { return finishSkybox(); }
        if (url.match(/</)) {
            $skybox.html(url);
            return finishSkybox();
        }
        $skybox.html('');
        data = data || {};
        data['_json'] = 1;
        // clear queue for this request if there is more than one
        $.skyboxQueue[url] = $.skyboxQueue[url] || [];
        $.each($.skyboxQueue[url], function(i, req) {
            req.abort();
        });
        $.skyboxQueue[url].push(
            $.post(url, data, function(json) {
                var escaped = escape(url);
                p = aql.parseJSON(json, { 
                    div: { page: '(<a href="' + escaped + '" target="_blank">' + escaped + '</a>) is not a valid page!' } 
                });
                aql.loader(p, '#skybox').load(function() {
                    finishSkybox();
                });
            })
        ); // end upsh to queue.
    };
    
    $.skyboxHide = function(fn) {
        $('#skybox').fadeOut('fast', function() {
            $('#overlay').fadeOut('slow', function() {
                $('#skybox').html('').css({
                    width: ''
                }); // hopefully this removes the width of the skybox so there is no remnant width when the next skybox opens
                console.log($('#skybox').width());
                if (typeof skyboxHideOnSuccess == 'function') {
                    skyboxHideOnSuccess();
                    skyboxHideOnSuccess = null;
                }
                if (typeof fn == 'function') {
                    fn();
                }
            });
            $(this).attr('class', '');
        });
    };

    jQuery.fn.center = function ($div) {
        if (!$div) {
            $div = $(window);
        }
        var top = ( $(window).height() - this.height() ) / 2+$(window).scrollTop();
        if ( top < 5 ) top = 5;
        var left = ( $div.width() - this.width() ) / 2+$div.scrollLeft();
        if ( left < 5 ) left = 5;
        this.css("position","absolute");
        this.css("top", top + "px");
        this.css("left", left + "px");
        return this;
    };

    jQuery.fn.ajaxRefresh = function (p_json) {
        div = this;
        url = this.attr('ajax');
        if (!url) return false;
        $.post(url,{_p:p_json},function(html){ // maybe we should post _json:1 and get json.page ?
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

    jQuery.fn.loadSelectOptions = function(url, fn) {
        return this.each(function() {
            
            var $this = $(this),
                name = ($this.attr('display_name')) ? $this.attr('display_name') + ' ' : null,
                load = [ { value: 0, name: 'loading...' } ],
                no_url = [ { value: 0, name: (name) ? name : '--' } ],
                error = [ { value: 0, name: 'ERROR LOADING' } ],
                def =  { value: 0, name: '-- Choose ' + name + '--' };
            
            if (!url) {
                $this.selectOptions(no_url);
                return;
            }
            
            $this.selectOptions(load);
            $.post(url, function(json) {
               aql.json.handle(json, null, {
                   success: function() {
                        this.json.data.unshift(def);
                        $this.selectOptions(this.json.data, fn);
                   },
                   error: function() {
                       $this.selectOptions(error);
                   }
               });
            });

        });
    };

    jQuery.fn.selectOptions = function(json, fn) {
        if (!json) json = [];
        return this.each(function() {
           var $this = $(this);
           if (!$this.is('select')) {
               $.error('selectOptions works only on select elements. ' + $this.get(0).tagName + ' given.');
               return;
           }
           $this.html(select_options_from_json(json));
           aql._callback(fn, $this, $this);
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
    var html = '<div style="padding:10px; background: #fff;">';
    html += '<div style="padding-bottom:20px">' + text + '</div>';
    html += '<a href="javascript:void(0)" onclick="$.skyboxHide()">close</a>';
    html += '</div>';
    $.skyboxShow(html);
}


function addParam(param, value, url) {
    if (!url) url = location.href;
    if (url.lastIndexOf('?') <= 0) url = url + "?";

    var re = new RegExp("([?|&])" + param + "=.*?(&|$)", "i");
    if (url.match(re))
        return url.replace(re, '$1' + param + "=" + value + '$2');
    else
        return url.substring(url.length - 1) == '?'
            ? url + param + "=" + value
            : url + '&' + param + "=" + value;
}
function getParam( name, url ) {
  if (!url) url = location.href;
  name = name.replace(/[\[]/,"\\\[").replace(/[\]]/,"\\\]");
  var regexS = "[\\?&]"+name+"=([^&#]*)";
  var regex = new RegExp( regexS );
  var results = regex.exec( url );
  if( results == null )
    return "";
  else
    return results[1];
}
function removeParam(param,url) {
    if (!url) url = location.href;
    var urlparts= url.split('?');
    if (urlparts.length>=2) {
        var prefix= encodeURIComponent(param)+'=';
        var pars= urlparts[1].split(/[&;]/g);
        for (var i=pars.length; i-- > 0;)
        if (pars[i].indexOf(prefix, 0)==0)
            pars.splice(i, 1);
        if (pars.length > 0)
            return urlparts[0]+'?'+pars.join('&');
        else
            return urlparts[0];
    } else return url;
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


var aql = {
    savepath : '/aql/save',
    deletepath: '/aql/delete',
    save : function(model, data, callbacks) {
        if (!callbacks) callbacks = {};
        callbacks.model = model;
        callbacks.data = data;
        return this._save(callbacks);
    },
    remove : function(model, data, callbacks) {
        if (!callbacks) callbacks = {};
        callbacks.data = data;
        callbacks.model = model;
        return this._remove(callbacks);
    },
    _save : function(pars) {
        var def = aql.savepath,
            errormsg = 'aql.save expects a model parameter if the url parameter is not set';
        if (typeof pars != 'object') return;
        var url = this._postHelpers.makeUrl(pars, errormsg, def);
        if (!pars.successMessage) {
            pars.successMessage = 'Saved.';
        }
        return this._postHelpers.post(pars, url);
    },
    _remove : function(pars) {
        var def = aql.deletepath,
            errormsg = 'aql.remove expects a model parameter if the url parameter is not set';
        if (typeof pars != 'object') return;
        if (!pars.confirm) pars.confirm = 'Are you sure you want to remove this?';
        if (!pars.disableConfirm) {
            if (!confirm(pars.confirm)) return;    
        }
        var url = this._postHelpers.makeUrl(pars, errormsg, def);
        if (!pars.successMessage) {
            pars.successMessage = 'Deleted.';
        }
        return this._postHelpers.post(pars, url);       
    },
    _postHelpers : {
        makeUrl : function(pars, errormsg, def) {
            if (typeof pars.url != 'undefined') return pars.url;
            if (typeof pars.model == 'undefined') {
                $.error(errormsg);
            }
            return def + '/' + pars.model;
        },
        post : function(pars, url) {
            var $div = aql._getDivObject(pars.messageDiv);
            return $.ajax({
                url: url,
                type: 'POST',
                data: pars.data,
                beforeSend: function() {
                    aql._callback(pars.beforeSend, null, $div);
                },
                success: function(json) {
                    aql.json.handle(json, $div, pars);
                }
            });
        }
    },
    _callback : function() {
        var l = arguments.length;
        if (l == 0) return false;
        var callback = arguments[0],
            scope = arguments[1];
        if (typeof callback != 'function') {
            return false;
        }
        var args = [];
        for (var i = 2; i < l; i++) {
            args.push(arguments[i]);
        }
        if (!scope) scope = this;
        callback.apply(scope, args);
        return true;
    },
    _getDivObject : function(div) {
        if (typeof div == 'undefined') return null;
        if (typeof div == 'object' && !!div.jquery) return div;
        if (div.substr(0, 1) != '#') div = '#' + div;
        return $(div);
    },
    json : {
        handle: function(json, $div, fns) {
            var errors = json.errors ? json.errors : ['Internal JSON Error'],
                scope = {
                    json : json,
                    errors : errors,
                    errorHTML : aql.json.errorHTML(errors),
                    div : $div,
                    params : fns
                };
            if (json.status == 'OK') {
                if (!aql._callback(fns.success, scope, json, $div)) {
                    aql._callback(aql.json.success, scope, json, $div);
                }
                aql._callback(fns.success2, scope, json, $div);
            } else {
                if (!aql._callback(fns.error, scope, json, $div, errors)) {
                    aql._callback(this.error, scope, json, $div, errors);
                }
                aql._callback(fns.error2, scope, json, $div, errors);
            }
            aql._callback(fns.finish, scope, json, $div);
        },
        success: function(json, $div) {
            if (!$div) return;
            aql.success($div, this.params.successMessage);
        },
        error: function(json, $div, errors) {
            if (!$div) return;
            aql.error($div, this.errorHTML);
        },
        errorHTML: function(errors) {
            if (!errors) return;
            var e = '<ul>';
            for (var i in errors) {
                e += '<li>' + errors[i] + '</li>';
            }
            e += '</ul>';
            return e;
        }
    },
    success: function($div, text) {
        $div = aql._getDivObject($div);
        if (!$div) return;
        $div.html('<div class="aql_success">' + text + '</div>');
    },
    error: function($div, text) {
        $div = aql._getDivObject($div);
        if (!$div) return;
        $div.html('<div class="aql_error">' + text + '</div>');
    },
    loader: function(p, div, src_domain) {
        var params = {
                p: p,
                div: aql._getDivObject(div),
                src_domain: (typeof src_domain != 'undefined') ? 'http://' + src_domain : ''
            };
        return {
            load: function(success) {
                var that = this;
                // that.CSS(function() {
                //     that.JS(function() {
                //         that.body(success);
                //     });
                // });
                that.CSS(function() {
                    that.body(function() {
                       that.JS(function() {
                           that.SCRIPTS(success);
                       }); 
                    });
                });
            },
            JS: function(end) {
                var that = this,
                    loadJS = function(script, fn) {
                        var d = (script.match(/http/)) ? script : params.src_domain + script;
                        if (aql.hasScript(d)) {
                            aql._callback(fn);
                        } else {
                            $.getScript(d, function(data) {
                                page_js_includes.push(d);
                                aql._callback(fn);
                            });    
                        }
                    },
                    success = function() {
                        if (params.p.page_js) loadJS(params.p.page_js, end);
                        else end();
                    },
                    loadEach = function(all) {
                        if (all.length == 0) {
                            success();
                            return;
                        }
                        var piece = all.shift();
                        loadJS(piece, function() { loadEach(all); });
                    };
                loadEach(params.p.js);
            },
            SCRIPTS: function(end) {
                aql._callback(end); 
                for (var i in p.script) {
                    var script = document.createElement('script'),
                        src = p.script[i],
                        tt = document.createTextNode(src);
                    script.appendChild(tt);
                    document.getElementsByTagName('body')[0].appendChild(script);
                }
            },
            CSS: function(success) {
                var cssArr = (params.p.css) ? params.p.css : [];
                if (params.p.page_css) cssArr.push(params.p.page_css);
                aql.deferLoad({
                    arr: cssArr,
                    success: success,
                    fn: function(item, fn) {
                        $.getCSS(params.src_domain + item, function() {
                            aql._callback(fn);
                        });
                    }
                });
            },
            body: function(end) {
                params.div.html(params.p.div['page']);
                aql._callback(end);
            }
        };
    },
    hasScript: function(script) {
        script = script.split('?')[0];
        var has = false;
        var mess = function(message) { var m = (has) ? message + ': dont load: ' : 'load: ';   console.log(m + script); };
        $('<script>').each(function() {
            if ($(this).attr('src') == script) has = true;
        });
        if ($.inArray(script, page_js_includes) > -1) has = true;
        return has;  
    },
    deferLoad: function(params) {
        /*
            params = {
                arr: Array of things to do a function to
                fn: the funciton that you're doing
                success: what you want to happen once all hte loading is done
                interval: the timeout interval default 20ms
            }
        */
        if (!params) params = {};
        if (!params.interval) params.interval = 20;

        var count = params.arr.length,
            loaded = 0;

        if (!params.arr || count == 0) { aql._callback(params.success); return; }

        var loadCheck = setInterval(function() {
            if (count != loaded) return;
            clearInterval(loadCheck);
            aql._callback(params.success);
        }, params.interval);

        for (var i in params.arr) {  
            params.fn(params.arr[i], function() {
                loaded++;
            });
        }
        
    },
    parseJSON: function(val, def) {
        def = def || {};
        if (typeof val == 'object') return val;
        try { p = $.parseJSON(val);
        } catch(e) { p = def; }
        return p;
    }
};


function loadLinkedSelects(selects) {
    
    if (!selects) return;

    var cp = [],
        clearSelect = function(item) { 
            if (!item.select) return;
            item.select.loadSelectOptions(null); 
        },
        loadSelect = function(item, ide) { 
            if (!item.select) return;
            item.select.loadSelectOptions(item.url + '/' + ide); 
        };

    // make sure these are jquery Objects and push to a copy of selects
    selects = $.map(selects, function(item) {
        item.select = aql._getDivObject(item.select);     
        cp.push(item);  
        return item;
    });

    // remove the first and make sure that the array is the same length as selects by adding an empty object at the end
    cp.push({});  cp.shift();

    $.each(selects, function(i, item) {
        var linked = cp.shift(), // get the first linked and keep the rest in the array.
            rest = cp.slice(0); // copy over the remnants to use within thsi closure.
        item.select.live('change', function() {
            var val = $(this).val();
            $.each(rest, function(i, item) {  clearSelect(item); });
            if (val) loadSelect(linked, val);
            else clearSelect(linked);
        });
    });

};


// returns html
function select_options_from_json(json) {
    var html = '';
    for (var i in json) {
        html += '<option value="'+ json[i].value + '">' + json[i].name + '</option>';
    }
    return html;
}

if (typeof console === 'undefined' || typeof console.log === 'undefined') {
    console = {};
    console.log = function() { };
    console.dir = function() { };
}

if (typeof page_js_includes == 'undefined') { 
    var page_js_includes = [];
}