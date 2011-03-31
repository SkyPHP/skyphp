<?

$p->title = 'Welcome to SkyPHP';

$p->template('demo','top');
?>

<h1><?=$p->title?></h1>

<a href="/b" id="test">test</a>
<br />
<a href="/c" id="test2">test2</a>
<br />
<a href="/d">no handler</a>
<br />
<input type="button" id="test-button" />

<?
/*
?>
<uploader
    vfolder=""
    thumbnail_width=""
    thumbnail_height=""
/>
<?
media::uploader(array(
    'vfolder' => ''
));
*/
?>

<pre>
<?
print_r($p);
?>
</pre>

<?
$p->template('demo','bottom');