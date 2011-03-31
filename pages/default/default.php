<?

$p->title = 'Welcome to SkyPHP';

$p->template('demo','top');
?>

<h1><?=$p->title?></h1>

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