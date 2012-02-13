<?

global $dev;

if ( $template_area == 'top' ) {

    $this->template_js[] = '/lib/history.js-090911-edge/history.js';
    $this->template_js[] = '/lib/history.js-090911-edge/history.html4.js';
    $this->template_js[] = '/lib/history.js-090911-edge/history.adapter.jquery.js';
    $this->template_js[] = '/lib/js/jquery.livequery.min.js';

    $attrs  = '';
    if ($this->html_attrs) {
        foreach ($this->html_attrs as $k => $v) {
            $attrs .= " {$k}=\"{$v}\"";
        }
    }

?>
<!doctype html>
<!--[if lt IE 7 ]> <html <?=$attrs?> lang="en" class="no-js ie6"> <![endif]-->
<!--[if IE 7 ]>    <html <?=$attrs?> lang="en" class="no-js ie7"> <![endif]-->
<!--[if IE 8 ]>    <html <?=$attrs?> lang="en" class="no-js ie8"> <![endif]-->
<!--[if IE 9 ]>    <html <?=$attrs?> lang="en" class="no-js ie9"> <![endif]-->
<!--[if (gt IE 9)|!(IE)]><!--> <html <?=$attrs?> lang="en" class="no-js"> <!--<![endif]-->
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1" />
    <title><?=$this->title?></title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

<? 
    if ($p->seo) { 
?>
    <meta name="title" content="<?=$p->seo['meta_title']?>" />
    <meta name="description" content="<?=$p->seo['meta_description']?>" />
    <meta name="subject" content="<?=$p->seo['meta_subject']?>" />
    <meta name="keywords" content="<?=$p->seo['meta_keywords']?>" />
    <meta name="copyright" content="<?=$p->seo['domain']?>" />
    <meta name="ICBM" content="<?=$p->seo['ICBM']?>" />
    <meta name="geo.position" content="<?=$p->seo['ICBM']?>" />
    <meta name="geo.placename" content="<?=$p->seo['placename']?>" />
    <meta name="geo.region" content="<?=$p->seo['geo-region']?>" />
<? 
        if($p->seo['zipcode']) { 
?> 
    <meta name="zipcode" content="<?=$p->seo['zipcode']?>" /> 
<? 
        } 
?>
    <meta name="city" content="<?=$p->seo['city']?>" />
    <meta name="state" content="<?=$p->seo['state']?>" />
    <meta name="country" content="<?=$p->seo['country']?>" />

<? 
        if($p->seo['google_site_verification']) { 
?> 
    <meta name="google-site-verification" content="<?=$p->seo['google_site_verification']?>" /> 
<? 
        }  
    } 
?>
    <meta http-equiv="imagetoolbar" content="no" />
<?
	if($p->favicon) {
?>
    <link rel="shortcut icon" href="<?=$p->favicon?>" />
<?
	} else { 
?>
    <link rel="shortcut icon" href="/favicon.ico" />
<?
	}
?>
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
<?
    if ( true ) echo $this->stylesheet();
    else echo $this->consolidated_stylesheet();

    global $jquery_version;
?>
    <script src="//ajax.googleapis.com/ajax/libs/jquery/<?=$jquery_version?>/jquery.min.js"></script>
    <script>!window.jQuery && document.write(unescape('%3Cscript src="/lib/js/jquery-<?=$jquery_version?>.min.js"%3E%3C/script%3E'))</script>


    <!--[if (lt IE 9) & (!IEMobile)]>
        <script src="/lib/js/jquery-extended-selectors.js"></script>
        <script src="/lib/js/selectivizr-min.js"></script>
    <![endif]-->

<?
    // echo the items in the $head_arr
	if (is_array($this->head)) {
        foreach ($this->head as $head_item) {
            echo $head_item . "\n";
        }
	} else if ( $this->head ) {
        echo $this->head . "\n";
    }

    /** 
    MODERNIZER IS CUSTOMIZED BY ADDING 'uploader' to the list of new tags, when updating it, iff updating the file, add it to the string of tag names.
    **/
?>
    <script src="/lib/js/modernizr-1.7.min.js"></script>
</head>
<body>
<div id="skybox" style="display:none;position:absolute;z-index:9999;"></div>
<div id="overlay" style="display:none;position:absolute;z-index:5000"></div>
<div id="body">

<?

} else if ( $template_area == 'bottom' ) {

?>

</div>

<script>if ( typeof window.JSON === 'undefined' ) { document.write('<script src="/lib/history.js-1.5/json2.min.js"><\/script>'); }</script>
<?
    $css = array_diff($this->css, $this->css_added);
    foreach ($css as $file) {
        if (in_array($file, $this->css_added)) continue;
        $this->css_added[] = $file;
        if ( file_exists_incpath($file) ) {
?>
    <link rel="stylesheet" href="<?=$file?>" />
<?
        }
    }
    if (true) echo $this->javascript();
    else echo $this->consolidated_javascript();
?>
<!--[if lt IE 7 ]>
<script src="/lib/js/dd_belatedpng.js"></script>
<script> DD_belatedPNG.fix('img, .png_bg');</script>
<![endif]-->
<?
    if ( $google_analytics_account ) {
?>
<script>
    var _gaq=[['_setAccount','<?=$google_analytics_account?>'],['_trackPageview']]; // Change UA-XXXXX-X to be your site's ID
    (function(d,t){var g=d.createElement(t),s=d.getElementsByTagName(t)[0];g.async=1;
    g.src=('https:'==location.protocol?'//ssl':'//www')+'.google-analytics.com/ga.js';
    s.parentNode.insertBefore(g,s)}(document,'script'));
</script>
<?
    }//google analytics
?>

<?
   global $db, $dbw, $db_host, $dbw_host;
?>

<!-- web: <?=$_SERVER['SERVER_ADDR']?> -->
<!-- db:  <?= substr($db->host,0,strpos($db->host,'.')) ?> -->
<!-- dbw: <?= substr($dbw->host,0,strpos($dbw->host,'.')) ?> -->
</body>
</html>
<?
}//bottom
