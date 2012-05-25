
var sky = (function() {

    /*
        a helper to get or set attributes based on prefix
        can be used standalone or with objects/prototypes that rely on html attributes

        EX:
            var a = attrHelper('data-');
            a.get($el, 'name');             // returns $el.attr('data-name');
            a.set($el, 'name', 'value');    // $el.attr('data-name', 'value');

    */
    var attrHelper = function(prefix) {
        return {
            prefix: prefix,
            name: function(n) {
                return this.prefix + n;
            },
            get: function($el, n) {
                return $el.attr(this.name(n));
            },
            set: function($el, n, value) {
                $el.attr(this.name(n), value);
            }
        };
    };

    /*
        checks to see if param is a jquery object
        return found jquery object
        assuming that the selector is an #id
     */
    var getDivObject = function(div) {
        if (typeof div == 'undefined') return null;
        if (typeof div == 'object' && !!div.jquery) return div;
        if (div.substr(0, 1) != '#') div = '#' + div;
        return $(div);
    };

    /*

        first argument: callback function
        second argument: scope
        rest: arguments to be passed to callback

        returns true if callback is executed
        false otherwise

    */
    var callback = function() {
        var l = arguments.length, args = [], scope, i;
        if (l === 0) return false;
        if (typeof arguments[0] != 'function') return false;

        scope = arguments[1] || aql;
        for (i = 2; i < l; i++) args.push(arguments[i]);
        arguments[0].apply(scope, args);
        return true;
    };

    var deferLoad = function(params) {
        /*
            params = {
                arr: Array of things to do a function to
                fn: the function that you're doing
                success: what you want to happen once all the loading is done
                interval: the timeout interval default 20ms
            }

            Used for loading the css in sky.loader

            EX:
                deferLoad({
                    arr: [url1, url2, url3],
                    fn: $.get, // can be any function as long as second param is callback
                    success: function() {
                        console.log('everything done!')
                    }
                });

        */
        if (!params) params = {};
        if (!params.interval) params.interval = 20;

        var count = params.arr.length,
            loaded = 0,
            incLoaded = function() { loaded++; },
            loadCheck,
            i;


        if (!params.arr || count === 0) {
            callback(params.success);
            return;
        }

        loadCheck = setInterval(function() {
            if (count != loaded) return;
            clearInterval(loadCheck);
            callback(params.success);
        }, params.interval);

        for (i in params.arr) {
            params.fn(params.arr[i], incLoaded);
        }

    };

    // checks to see if a JS file has already been loaded to the page
    var hasScript = function(script) {

        script = script || '';
        script = script.split('?')[0];

        var has = false,
            mess = function(message) {
                var m = (has) ? message + ': dont load: ' : 'load: ';
                console.log(m + script);
            };

        if ($.inArray(script, page_js_includes) > -1) has = true;

        $('<script>').each(function() {
            if ($(this).attr('src') == script) has = true;
        });

        return has;

    };

    /*
        If posting a form (or serialized), we post normally
        If posting an object, it is posted as JSON content type

        EX:
            var data = $form.serialize();
            sky.post(url, data, function(response) {});

        OR:
            sky.post(url, {key: value}, function(response) { });

        One can also pass an object as the third parameter if you want to do beforeSend,
        These just map to $.ajax(), not aql.save()

            sky.post(url, data, {
                success: function(response) {

                },
                beforeSend: function(response) {

                }
            })

    */
    var contentTypes = ['application/x-www-form-urlencoded', 'application/json'];
    var post = function(url, data, fn) {

        data = data || {};
        fn = fn || function() { };

        var setts = {},
            isForm = typeof(data) == 'string',
            contentType = contentTypes[ (isForm) ? 0 : 1];

        data = (isForm) ? data : JSON.stringify(data);

        if (typeof fn == 'function') setts.success = fn;
        else setts = fn;

        setts.beforeSend = setts.beforeSend || function() { };

        return $.ajax({
            url: url,
            type: 'POST',
            contentType: contentType,
            data: data,
            beforeSend: setts.beforeSend,
            success: setts.success
        });

    };

    // loads a JS page object into the specified div
    // preloading CSS/JS
    var loader = function(p, div, src_domain) {
        src_domain = src_domain || '';
        var params = {
                p: p,
                div: getDivObject(div),
                src_domain: (src_domain.length) ? 'http://' + src_domain : ''
            };
        return {
            load: function(success) {
                var that = this;
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
                        if (hasScript(d)) {
                            callback(fn);
                        } else {
                            $.getScript(d, function(data) {
                                page_js_includes.push(d);
                                callback(fn);
                            });
                        }
                    },
                    success = function() {
                        if (params.p.page_js) loadJS(params.p.page_js, end);
                        else end();
                    },
                    loadEach = function(all) {
                        all = all || [];
                        if (all.length === 0) {
                            success();
                            return;
                        }
                        var piece = all.shift();
                        loadJS(piece, function() { loadEach(all); });
                    };
                loadEach(params.p.js);
            },
            SCRIPTS: function(end) {
                callback(end);
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
                deferLoad({
                    arr: cssArr,
                    success: success,
                    fn: function(item, fn) {
                        $.getCSS(params.src_domain + item, function() { callback(fn); });
                    }
                });
            },
            body: function(end) {
                params.div.html(params.p.div['page']);
                callback(end);
            }
        };
    };

    // attempts to return an object from value
    // if it fails, we return def
    var parseJSON = function(value, def) {

        var p, def = def || {};
        if (typeof value == 'object') return value;
        try         { p = $.parseJSON(value); }
        catch(e)    { p = def; }
        return p;

    };

    // this is what var sky is going to be
    return {
        call: callback,
        getDivObject: getDivObject,
        post: post,
        loader: loader,
        hasScript: hasScript,
        deferLoad: deferLoad,
        parseJSON: parseJSON,
        attrHelper: attrHelper,
        attr: attrHelper('data-')
    };

})();

var aql = {
    savepath : '/aql/save',
    deletepath: '/aql/delete',
    save : function(model, data, callbacks) {
        callbacks = callbacks || {};
        callbacks.model = model;
        callbacks.data = data;
        return this._save(callbacks);
    },
    remove : function(model, data, callbacks) {
        callbacks = callbacks || {};
        callbacks.data = data;
        callbacks.model = model;
        return this._remove(callbacks);
    },
    _save : function(pars) {

        var def = aql.savepath,
            errormsg = 'aql.save expects a model parameter if the url parameter is not set',
            url;

        if (typeof pars != 'object') return null;

        url = this._postHelpers.makeUrl(pars, errormsg, def);
        pars.SuccessMessage = pars.successMessage || 'Saved.';
        return this._postHelpers.post(pars, url);

    },
    _remove : function(pars) {
        var def = aql.deletepath,
            errormsg = 'aql.remove expects a model parameter if the url parameter is not set',
            url;

        if (typeof pars != 'object') return null;

        pars.confirm = pars.confirm || 'Are you sure you want to remove this?';
        pars.successMessage = pars.successMessage || 'Deleted.';
        if (!pars.disableConfirm) if (!confirm(pars.confirm)) return null;
        url = this._postHelpers.makeUrl(pars, errormsg, def);
        return this._postHelpers.post(pars, url);
    },
    _postHelpers : {
        makeUrl : function(pars, errormsg, def) {
            if (typeof pars.url != 'undefined') return pars.url;
            if (typeof pars.model == 'undefined') $.error(errormsg);
            if (pars.model.match(/\//)) return pars.url = pars.model;
            return def + '/' + pars.model;
        },
        post: function(pars, url) {
            var $div = sky.getDivObject(pars.messageDiv);
            return sky.post(url, pars.data, {
                success: function(json) { aql.json.handle(json, $div, pars); },
                beforeSend: function() { sky.call(pars.beforeSend, null, $div); }
            });
        }
    },
    json: {
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
                if (!sky.call(fns.success, scope, json, $div)) {
                    sky.call(aql.json.success, scope, json, $div);
                }
                sky.call(fns.success2, scope, json, $div);
            } else {
                if (!sky.call(fns.error, scope, json, $div, errors)) {
                    sky.call(this.error, scope, json, $div, errors);
                }
                sky.call(fns.error2, scope, json, $div, errors);
            }
            sky.call(fns.finish, scope, json, $div);
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
            var e = '';
            errors = errors || [];
            for (var i in errors) e += '<li>' + errors[i] + '</li>';
            return '<ul>' + e + '</ul>';
        }
    },
    success: function($div, text) {
        $div = sky.getDivObject($div);
        if (!$div) return;
        $div.html('<div class="aql_success">' + text + '</div>');
    },
    error: function($div, text) {
        $div = sky.getDivObject($div);
        if (!$div) return;
        $div.html('<div class="aql_error">' + text + '</div>');
    }
};

var linkedSelects = {
    optsFromJson: function(json, curr) {
        return json.map(function(i) {
            var c = (curr == i.value) ? 'selected' : '';
            return '<option value="' + i.value + '" ' + c + '>' + i.name + '</option>';
        }).join('');
    },
    load: function(selects, data) {
        if (!selects) return;
        data = data || {};

        var cp = [],
            clearSelect = function(item) {
                if (!item.select) return;
                item.select.loadSelectOptions(null, data);
            },
            loadSelect = function(item, ide) {
                if (!item.select) return;
                item.select.loadSelectOptions(item.url + '/' + ide, data);
            };

        // make sure these are jquery Objects and push to a copy of selects
        selects = $.map(selects, function(item) {
            item.select = sky.getDivObject(item.select);
            cp.push(item);
            return item;
        });

        // remove the first item
        // and make sure that the array is the same length as selects
        // by adding an empty object at the end
        cp.push({});  cp.shift();

        $.each(selects, function(i, item) {
            var linked = cp.shift(),    // get the first linked and keep the rest in the array.
                rest = cp.slice(0);     // copy over the remnants to use within thsi closure.
            item.select.live('change', function() {
                var val = $(this).val();
                $.each(rest, function(i, item) {  clearSelect(item); });
                if (val) loadSelect(linked, val);
                else clearSelect(linked);
            });
        });
    }
};
