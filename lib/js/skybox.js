
$(document).ready(function () {
	$('#overlay').css('backgroundColor','#000');
	$('#skybox').css('backgroundColor','#fff');
	$('#overlay').css({ 
		position: "absolute",
//		display: "none",
		zIndex: "5000"
	});

	jQuery(function( $ ){
		//borrowed from jQuery easing plugin
		//http://gsgd.co.uk/sandbox/jquery.easing.php
		//$.scrollTo.defaults.axis = 'xy';
		$.easing.elasout = function(x, t, b, c, d) {
			var s=1.70158;var p=0;var a=c;
			if (t==0) return b;  if ((t/=d)==1) return b+c;  if (!p) p=d*.3;
			if (a < Math.abs(c)) { a=c; var s=p/4; }
			else var s = p/(2*Math.PI) * Math.asin (c/a);
			return a*Math.pow(2,-10*t) * Math.sin( (t*d-s)*(2*Math.PI)/p ) + c + b;
		};
	});

});

function skybox(href,data, w, h, onSuccessFunction, historyOff) {
	if (!historyOff) {
		//SWFAddress.setValue('skybox');
	}//if
    if (data) {
        if ( typeof data !== "object" ) {
            var width = data;
            var height = w;
            onSuccessFunction = h;
            historyOff = onSuccessFunction;
            data = null;
        } else {
			var width = data.width?data.width:w;
			var height = data.height?data.height:h;	
        }
    }
	if ( $.overlayProtect == false ) $('#overlay').css('opacity', 0).show().fadeTo('normal', 0.75);
	if (width) $('#skybox').width(width);
	if (height) $('#skybox').height(height);
	if (/</.test(href)) { // it looks like html
		$('#skybox').html(href);
		overlay(null, width, height, false);
		$('#skybox :input:visible:enabled:first').focus();
	} else {
		$.post(href,data, function(data){
			$('#skybox').html(data);
			overlay(null, width, height, false);
			$('#skybox :input:visible:enabled:first').focus();
			if($.isFunction(onSuccessFunction))
				onSuccessFunction(this);
		});
	}
}

function overlay(action, w, h, historyOff) {
	if (action=='hide') {
		$('#skybox').fadeOut('normal');
		$('#overlay').fadeOut('normal');
		$('#skybox').width('');
		$('#skybox').height('');
	} else {
		if (w) $('#skybox').width(w);
		if (h) $('#skybox').height(h);
		
		//$.scrollTo(0,1000,{easing:'elasout'});
		//$.scrollTo(0,500);
		$("#skybox").smartalign();
		$("#skybox").fadeIn('fast');
		
/*
		var target_w = $("#skybox").width();
		var target_h = $("#skybox").height();
		$("#skybox").width(1);
		$("#skybox").height(1);
		var cssProp = {
			position: 'absolute',
			top: '1px',
			left: '1px'
		};
		$("#skybox").css(cssProp);
		//$("#skybox").css('top') = Math.floor($(window).height() / 2 ) + 'px';
		//$("#skybox").css('left') = Math.floor($(window).width() / 2 ) + 'px';
		$("#skybox").animate({ 
			marginLeft: ( ( $(window).width() - target_w ) / 2 ) + 'px',
			opacity: 1.0,
			width: target_w,
			height: target_h
		}, 'slow', 'swing', function(){ $("#skybox").smartalign() } );
*/

	}
};

function closeskybox() {
	overlay('hide');
	return false;
};

jQuery.fn.smartalign = function(params) {
   return this.each(function(){

		var owidth = $(window).width();
		if ( $(document).width() > owidth ) owidth = $(document).width();
		$('#overlay').width( owidth );
		$('#overlay').height( $(document).height() );

		var $self = jQuery(this);
		var width = $self.width();
		var height = $self.height();
		//$self.height(0);
		var winW = $(window).width();
		var winH = $(window).height();
		var docH = $(document).height();
		//get the type of positioning
		var positionType = $self.parent().css("position");
		// get the half minus of width and height
		var halfWidth = (width/2)*(-1);
		var halfHeight = ((height/2)*(-1));
		// initializing the css properties
		var cssProp = {
			position: 'absolute'
		};
		// smart vertical align the skybox on the page
		var vpad = winH - height;
		var max_vpad;
		var tpad = 0;
		if ( vpad > height ) {
			tpad = Math.floor( vpad * .35 );
			cssProp.top = tpad + 'px';
			cssProp.marginTop = '0';
		} else if ( winH > height ) {
			cssProp.top = '50%';
			cssProp.marginTop = halfHeight;
		} else {
			tpad = Math.floor( (-0.5) * vpad );
			max_vpad = ( 0.05 * winH );
			if ( tpad > max_vpad ) tpad = max_vpad;
			cssProp.top = tpad + 'px';
			cssProp.marginTop = '0';
		}
		cssProp.height = '';
		cssProp.marginBottom = tpad + 'px';
		// horizontal center
		var scrollbarW = 0;
		if ( width > winW - scrollbarW ) {
			cssProp.left = '0';
			cssProp.marginLeft = '1px';		
		} else { 
			cssProp.left = '50%';
			cssProp.marginLeft = halfWidth;
		}
		cssProp.width = width;
		//check the current position
		if(positionType == 'static') {
			$self.parent().css("position","relative");
		}
		//aplying the css
		$self.css(cssProp);
   });
};

	$(window).resize(function(){
		if ( $('#skybox').css('display')=='block' ) $('#skybox').smartalign();
	});
	
	$(window).scroll(function(){
		if ( $('#skybox').css('display')=='block' ) $('#skybox').smartalign();
	});

function handleChange(event) {
	//alert( event.path );
	if ( $.overlayProtect == false ) {
		if ( event.path != '/skybox' ) closeskybox();
	}
}

function skybox_img(media_instance_ide) {
	skybox('/media/skybox_img/'+media_instance_ide,500);
}


function skybox_alert(text) {
	text = '<div style="padding:10px">'+text + '<br /><br /><br /><a href="javascript:void()" onclick="history.back()">close</a></div>';
	skybox(text);
}

//alert( $(window).overlayProtect );
//SWFAddress.setStrict(false);
//SWFAddress.addEventListener(SWFAddressEvent.CHANGE, handleChange);
