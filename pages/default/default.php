<?

$p->title = 'Welcome to SkyPHP';

$p->head = "
<style>
    body { padding: 10px; }
    h1 { margin-bottom: 10px; font-size: 22px; }
    h2 { margin-bottom: 10px; font-size: 15px; }
</style>
";

$p->template('html5','top');
?>


<h1><?=$p->title?></h1>

<h2>Getting Started</h2>
<p>
    To replace this default homepage, create the following file in your codebase:
    <code>
    pages/default/default.php
    </code>
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