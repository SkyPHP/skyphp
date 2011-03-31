
(function(window,undefined){
    var History = window.History; // we are using a capital H instead of a lower h
    var State = History.getState();
    History.Adapter.bind(window,'statechange',function(){ // this does not listen for hash changes
        var State = History.getState();
        url = State.url;
        skyboxURL = $.jqURL.get('skybox');
        if ( skyboxURL ) {
            $.skyboxShow(skyboxURL);
        } else {
            $.skyboxHide();
            if ( $('body').hasClass('ajax') ) {
                $.post(url, {_ajax:1}, function(json){
                    p = jQuery.parseJSON(json);
                    //console.log(p);
                    if ( p != null ) {
                        document.title = p.title;
                        for (var key in p.div) {
                             $('#'+key).html(p.div[key]);
                        }
                        $('div[ajax]').ajaxRefresh(json);
                    } else {
                        location.href = url;
                    }
                }).error(function() {
                    location.href = url;
                });
            }
        }
    });
})(window);

$(function(){
    $('body').addClass('ajax');
    $('body.ajax a[class!="noajax"]').live('click',function(event){
        url = $(this).attr('href');
        window.History.pushState(null,null,url);
        return false;
    });
    $(window).resize(function() {
      $('#skybox').center();
      $('#overlay').width($(window).width()).height($(window).height());
    });
    $('uploader').livequery(function(){
        $(this).uploader();
    });
    console.log('PAGE LOADED');
    $.pageLoaded = true;
});

(function($){


    jQuery.fn.uploader = function (vfolder,options) {
        // read attributes of this
        // display gallery
        // display upload button
        return this;
    }


    /*
     *  skybox(url)
     *  skybox(url,width)
     *  skybox(url,width,height)
     *
     **/
    $.skybox = function(url,w,h) {
        $('#skybox').html('');
        History.pushState(null,null,$.jqURL.set({skybox:url}));
        if (w) $('#skybox').width(w);
        if (h) $('#skybox').height(h);
    };
    $.skyboxIsOpen = function() {
        if ( $('#skybox').css('opacity') > 0 ) return true;
        else return false;
    };
    $.skyboxShow = function(url) {
        if (url) {
            $('#skybox').html('');
            $.post(url,{skybox:1,_ajax:1},function(json){
                p = jQuery.parseJSON(json);
                $('#skybox').html(p.div['page']).center();
            });
        }
        $('#skybox').css('backgroundColor','#fff').show().center().fadeTo('fast', 1);
        $('#overlay').width($(window).width()).height($(window).height()).css('backgroundColor','#000').show().fadeTo('fast', 0.75);
    };
    $.skyboxHide = function() {
        $('#skybox').fadeTo('fast', 0).hide();
        $('#overlay').fadeTo('fast', 0).hide();
    };

    jQuery.fn.center = function () {
        var top = ( $(window).height() - this.height() ) / 2+$(window).scrollTop();
        if ( top < 5 ) top = 5;
        var left = ( $(window).width() - this.width() ) / 2+$(window).scrollLeft();
        if ( left < 5 ) left = 5;
        this.css("position","absolute");
        this.css("top", top + "px");
        this.css("left", left + "px");
        return this;
    }

    jQuery.fn.ajaxRefresh = function (p_json) {
        div = this;
        url = this.attr('ajax');
        div.fadeTo('fast',0.01);
        $.post(url,{_p:p_json},function(data){
            div.html(data);
            div.fadeTo('fast',1);
        });
    }

})( jQuery );

