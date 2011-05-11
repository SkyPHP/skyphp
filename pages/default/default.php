<?

$p->title = 'Welcome to SkyPHP';

$p->template('html5','top');
?>

<h1><?=$p->title?></h1>

<h2>Getting Started</h2>
<p>
    To replace this default homepage, create the following file in your codebase:<br />
    pages/default/default.php
</p>

<h2>Documentation</h2>
<ul>
    <li><a href="http://www.skyphp.org/doc" target="_blank">SkyPHP Documentation Wiki</a></li>
    <li><a href="https://github.com/SkyPHP/skyphp/wiki" target="_blank">SkyPHP Documentation Wiki on GitHub</a></li>
</ul>

<h2>$p</h2>
<pre>
<?
print_pre($p);
?>
</pre>

<?
$p->template('html5','bottom');