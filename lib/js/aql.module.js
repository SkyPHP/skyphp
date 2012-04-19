(function($) {

	var attr = {
		prefix: 'data-',
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

	function Module($el,options) { 
		this.initialize($el, options); 
	}
	
	Module.prototype = {
		initialize: function($el, options) {
			
			$el = aql._getDivObject($el);
			options = options || {};

			this.defaults = { 
				handlers: { 
					success: aql.json.success, 
					error: aql.json.error 
				}
			};

			this.handlers = this.defaults.handlers;

			if (!$el) return;

			this.$el = $el;
			this.el = $el.get(0);
			this.data = {};
			this.model = '';
			this.ide = '';
			this.token = '';
			this.errors = [];
			this.setByAttributes(this.$el);
			this.setByOptions(options);
			this.attach();

		},
		__setters__: function(n, val) {
			var that = this,
				setters = {
					data: function(v) { that.setData(v); },
					model: function(v) { that.model = v; },
					ide: function(v) { that.setIDE(v); },
					token: function(v) { that.token = v; },
					handlers: function(v) { that.setHandlers(v); }
				};

			if (typeof setters[n] == 'undefined') {
				return false;
			} else {
				setters[n](val);
				return true;
			}
		},
		clearData: function() {
			this.data = {};
			return this;
		},
		setByOptions: function(opts) {
			for (var i in opts) this.__setters__(i, opts[i]);
			return this;
		},
		setHandlers: function(handlers) {
			handlers = handlers || {};
			this.handlers = this.merge(this.handlers, handlers);
			return this;
		},
		resetHandlers: function() {
			this.handlers = this.default_handlers;
			return this;
		},
		setByAttributes: function($el) {
			var attrs = this.getElementAttributes($el.get(0)), i;
			for (i in attrs) {
				if (this.__setters__(i, attrs[i])) continue;
				this[i] = attrs[i];
			}
			return this;
		},
		getElementAttributes: function(el) {
			var prefix = 'data-',
				pattern = new RegExp(prefix),
				attrs = el.attributes,
				l = attrs.length,
				attr, n,
				coll = {};

			for (var i = 0; i < l; i++) {
				attr = attrs.item(i);
				if (!pattern.test(attr.nodeName)) continue;
				n = attr.nodeName.replace(pattern, '');
				coll[n] = attr.nodeValue;
			}

			return coll;

		},
		getAttr: function(n) {
			return attr.get(this.$el, n);
		},
		setAttr: function(n,v) {
			attr.set(this.$el, n, v);
			return this;
		},
		setIDE: function(v) {
			this.ide = v;
			if (this.model) this.data[this.model + '_ide'] = v;
			return this;
		},
		setData: function(v) {
			this.data = this.merge(this.data, v);
			return this;
		},
		setToken: function(v) {
			this.token = v;
			return this;
		},
		merge: function() {
			var o = {}, attr, i;
			for (i = 0;i < arguments.length; i++) {
				for (attr in arguments[i]) {
					o[attr] = arguments[i][attr];
				}
			}
			return o;
		},
		attach: function() {
			$.data(this.el, 'Model', this);
			return this;
		},
		fetch: function(el) {
			return $.data($(el)[0], 'Model');
		},
		detach: function() {
			$.data(this.el, 'Model', null);
			return this;
		},
		moduleHandlers: function(options) {
			var that = this;
			return this.merge(options, {
				success: function(json, $div) {
					
					var scope = this, //  this will be set in aql callback.
						continuation = options.success || that.handlers.success;

					that.setData(json.data);
					that.setIDE(json.data[that.model + '_ide']);
					that.token = json._token;
					that.errors = [];

					aql._callback(continuation, scope, json, $div);

				},
				error: function(json, $div, errors) {
					
					var scope = this,
						continuation = options.error || that.handlers.error;

					that.errors = errors;
					aql._callback(continuation, scope, json, $div, errors);

				}
			});
		},
		save: function(data, options) {
			
			options = this.moduleHandlers(options || {});
			data = data || {};
			data._token = this.token;

			if (!data[this.model + '_ide'] && this.ide) {
				data[this.model + '_ide'] = this.ide;
			}

			aql.save(this.model, data, options);

			return this;

		}
	};

	$.fn.aqlModule = function(options) {

		options = options || {};

		var	module = Module.prototype.fetch(this.get(0));
		if (!module) module = new Module(this);

		if (options.destroy) {
			module.destroy();
			delete options.destroy;
		} 

		module.setByOptions(options);
		return module;

	};

}) (jQuery);