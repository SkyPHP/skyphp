// skybox and ajax
firstStateChange = true;
skyboxHideOnSuccess = null;
(function(window,undefined){
    var History = window.History; // we are using a capital H instead of a lower h
    var State = History.getState();
    History.Adapter.bind(window,'statechange',function(){ // this does not listen for hash changes
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
        if ( skyboxURL ) {
            $.skyboxShow(skyboxURL);
        } else if (!firstStateChange) {
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
    });
})(window);

function ajaxPageLoad(url) {
    $('#page').fadeOut();
    $.post(url, {_json:1,_no_template:1}, function(json){
        try {
            p = jQuery.parseJSON(json);
        } catch(e) {
            p = jQuery.parseJSON( '{"div":{"page":"'+escape(url)+' is not a valid page."}}' );
        }
        if ( p != null ) {
            document.title = p.title;

                $('#page').html(p.div['page']);

                // disable and remove previously dynamically loaded css
                $('link[rel=stylesheet]').each(function(){
                    if ( $(this).attr('title') == 'page' ) {
                        //console.log('disabled ' + $(this).attr('href') );
                        $(this).attr('disabled',true);
                        $(this).replaceWith('');
                    }
                });

                // dynamically load page css and page js
                if (p.page_css) $.getCSS(p.page_css,{title:'page'},function(){
                    if(typeof Cufon != 'undefined') Cufon.refresh();
                });

                for (var i = 0; i < p.css.length; i++) {
                    $.getCSS(p.css[i]);
                }

                if (p.page_js) $.getScript(p.page_js);

                for (var i=0; i< p.js.length;i++) {
                    $.getScript(p.js[i]);    
                }

                $('#page').fadeIn(function(){
                    if(typeof Cufon != 'undefined') Cufon.refresh();
                });
                if ( jQuery.isFunction( ajaxOnSuccess ) ) ajaxOnSuccess(json);

        } else {
            location.href = url;
        }
    }).error(function() {
        location.href = url;
    });
}

$(function(){

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
        if ( thisHandlers.length == 0 && typeof url != 'undefined' && url.substring(0,11) != 'javascript:' ) window.History.pushState(null,null,url);
        return false;
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

});



(function($){

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
        History.pushState(null,null,uri);
		if (w) $('#skybox').width(w);
        if (h) $('#skybox').height(h);
		if (post) $.skyboxShow(skyboxURL, post);
    };
    var skybox = $.skybox;
    $.skyboxIsOpen = function() {
        if ( $('#skybox').css('opacity') > 0 ) return true;
        else return false;
    };
    $.skyboxShow = function(url, data) {
        if (url) {
            if (!url.match(/\</)) {
                $('#skybox').html('');
                if (!data) {
                    var data = {};
                }
                data['_json'] = 1;
                $.post(url,data,function(json){
                    try {
                        p = jQuery.parseJSON(json);
                    } catch(e) {
                        p = jQuery.parseJSON( '{"div":{"page":"'+('<a href='+escape(url)+' target=_blank>'+escape(url)+'</a>')+' is not a valid page!"}}' );
                        // this could happen if the skybox url has access_group and access is denied.
                        //console.log('json: '+json);
                    }
                    $('#skybox').html(p.div['page']);
                    // dynamically load js and css for the skybox
                    if (p.page_css) $.getCSS(p.page_css,function(){
                        // center skybox again after css is finished loading
                        $('#skybox').center();
                    });
                    if (p.page_js) $.getScript(p.page_js);
                    for (var i in p.css) {
                        $.getCSS(p.css[i], function() {
                            $('#skybox').center();  
                        });
                    }
                    for (var i in p.js) {
                        $.getScript(p.js[i]);
                    }
                    $('#skybox').center();
                });
            } else {
                $('#skybox').html(url);
            }
        }
        $('#skybox').css('backgroundColor','#fff').show().center().fadeIn('fast');
        $('#overlay').width($(window).width()).height($(document).height()).css('backgroundColor','#000').show().fadeTo('fast', 0.4);
    };
    
    $.skyboxHide = function() {
        $('#skybox').fadeOut('fast', function() {
            $('#overlay').fadeOut('slow', function() {
                if (typeof skyboxHideOnSuccess == 'function') {
                    skyboxHideOnSuccess();
                    skyboxHideOnSuccess = null;
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
    var html = '<div style="padding:10px;">';
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
        if (!confirm(pars.confirm)) return;
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
        },
        success: function(json, $div) {
            if (!$div) return;
            $div.html('<div class="aql_success">' + this.params.successMessage + '</div>');
        },
        error: function(json, $div, errors) {
            if (!$div) return;
            $div.html('<div class="aql_error">' + this.errorHTML + '</div>');
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
    }
};
