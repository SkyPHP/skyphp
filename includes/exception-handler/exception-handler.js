
$(function() {

    var displayHtml = function(inner) {

        var html = '<pre class="arg-display"><code>' + $.trim(inner) + '</code></pre>';

        return '<div class="arg-display-container">' +
            html +
            '<div class="back"><a href="">Close</a>' +
            '</div></div>';
    };

    $('#stack-trace').on('click', '.argument', function() {

        var $t = $(this),
            d = $t.parent().find('.data-content').html();



        $.skyboxShow(displayHtml(d));

        return false;
    });

    $('#skybox').on('click', '.back a', function() {
        $.skyboxHide();
        return false;
    });

});
