<?php

$this->title = 'Welcome to SkyPHP';

$this->head = "
<style>
    body { padding: 10px; }
    h1 { font-size: 36px; }
    h2 { margin: 10px 0; font-size: 24px; }
    code { display: block; margin-bottom: 10px; padding: 5px; background-color: #f0f0f0; }
</style>
";

$this->template('html5','top');

?>

<h1><?=$this->title?></h1>

<h2>Getting Started</h2>
<p>
    To replace this default homepage, create the following file in your codebase:
</p>
<code>
    pages/default/default.php
</code>

<h2>Documentation</h2>
<ul>
    <li>
        <a href="http://www.skyphp.org/doc" target="_blank">
            SkyPHP Documentation Wiki
        </a>
    </li>
    <li>
        <a href="https://github.com/SkyPHP/skyphp/wiki" target="_blank">
            SkyPHP Documentation Wiki on GitHub
        </a>
    </li>
</ul>

<?php

$this->template('html5','bottom');
