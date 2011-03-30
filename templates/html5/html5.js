
(function(window,undefined){
    // Establish Variables
    var
        History = window.History, // Note: We are using a capital H instead of a lower h
        State = History.getState();
    // Log Initial State
    //History.log('initial:', State.data, State.title, State.url);

    // Bind to State Change
    History.Adapter.bind(window,'statechange',function(){ // Note: We are using statechange instead of popstate
        // Log the State
        var State = History.getState(); // Note: We are using History.getState() instead of event.state
        var p = State.data;
        //History.log('statechange:', p, State.title, State.url);
        if (p.body!=null) {
            $('#page').html(p.body);
            // refresh template areas
        } else location.href = location.href;
    });

    // Ajaxify our Internal Links

})(window);

$(function(){
    $('a[class!="noajax"]').live('click',function(event){
        url = $(this).attr('href');
        $.post(url, {_ajax:1}, function(json){
            p = jQuery.parseJSON(json);
            //console.log(p);
            if ( p != null ) window.History.pushState(p,p.title,url);
            else location.href = url;
        });
        return false;
    });

    $(window).resize(function() {
      $('#skybox').center();
      $('#overlay').width($(window).width()).height($(window).height());
    });

    alert('page is loaded');
});

function addqs(param) {
    var qs, hash = '';
    uri = location.href;
    //console.log(uri);
    h = uri.indexOf('#');
    if (h > 0) {
        hash = uri.substring(h);
        uri = uri.substring(0,h);
        //console.log(uri);
    }
    q = uri.indexOf('?');
    if (q > 0) {
        qs = uri.substring(q) + '&' + param;
    } else {
        qs = '?' + param;
    }
    return qs + hash;
}

(function($){

    $.skybox = function(a) {
        if ( a == null ) {
            $('#overlay').fadeTo('normal', 0);
        } else {
            url = a;
            //var html = $.post(url,function(html){
            //    $('#skybox').html(html);
            //});
            History.pushState({skybox:1},'test','?skybox');

            $('#skybox').width(200).height(200).css('display','block').css('backgroundColor','#fff').css('opacity', 0).show().fadeTo('normal', 1);
            $('#skybox').center();
            $('#overlay').width($(window).width()).height($(window).height()).css('backgroundColor','#000').css('opacity', 0).show().fadeTo('normal', 0.75);
        }
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

})( jQuery );

