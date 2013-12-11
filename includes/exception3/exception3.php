<style>
    #skyExceptionBottom {
        padding: 10px;
        background-color: black;
        color: red;
    }
    #skyExceptionTop {
        margin: 50px;
    }
    #skyExceptionHeader {
        font-size: 33px;
    }
    #skyExceptionClose {
        display: block;
        float: right;
    }
    #skyExceptionBar {
        overflow: hidden;
    }
    #skyExceptionButton {
        color: #fff;
    }
</style>


<div id="skyExceptionTop" style="display: none; background-color: white;">
    <div id="skyExceptionBar">
        <div id="skyExceptionHeader"><?=$e->getMessage()?></div>
        <a href="javascript:void(null)" id="skyExceptionClose">close</a>
    </div>
    <div><?=$e->getFile()?> line <?=$e->getLine()?></div>
<?php
    if (method_exists($e, 'getErrors')) {
        $errors = $e->getErrors();
        d($errors);
    }
?>
    <div id="skyExceptionHeader">Stack Trace:</div>
<?php
    Kint::trace($e->getTrace());
?>
</div>


<div id="skyExceptionBottom">
    There is a problem with this webpage.
    <a id="skyExceptionButton" href="javascript:void(null)">Details</a>
</div>

<?php
global $jquery_version;
?>
<script>!window.jQuery && document.write(unescape('%3Cscript src="/lib/js/jquery-<?=$jquery_version?>.min.js"%3E%3C/script%3E'))</script>
<script type="text/javascript">
    $("#skyExceptionTop").prependTo($('body'));
    $("#skyExceptionButton").on('click', function(){
        $(window).scrollTop(0);
        $("#skyExceptionTop").slideDown();
    });
    $("#skyExceptionClose").on('click', function(){
        $("#skyExceptionTop").slideUp();
    })
</script>
