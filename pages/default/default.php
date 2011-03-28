<?

$p->title = 'Welcome to SkyPHP';
$p->lib[] = 'uploader';
$p->css[] = '/lib/tinymce/test.css';
$p->js[] = '/lib/js/test.js';

$p->template('demo','top');
?>

<h1><?=$p->title?></h1>

<pre>
<?
print_r($p);
?>
</pre>

<?
$p->template('demo','bottom');