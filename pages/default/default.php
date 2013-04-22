<?php

$this->title = 'Welcome to SkyPHP 3';

$this->head = "
<style>
    body {
        padding: 10px;
    }
    h2 {
        margin: 25px 0 10px;
        font-size: 24px;
    }
    code {
        display: block;
        margin: 10px 0;
        padding: 5px;
        background-color: #f0f0f0;
    }
</style>
";

$this->template('html5','top');

?>

<img src="/pages/default/skyphp-banner.png"
     alt="SkyPHP - A lightweight PHP5 framework for building scalable HTML5 websites" />

<h2>Getting Started</h2>
<p>
    To replace this default homepage, create the following file in
    your codebase:
</p>
<code>
    pages/default/default.php
</code>

<h2>Documentation</h2>
<ul>
    <li>
        <a href="https://github.com/SkyPHP/skyphp">SkyPHP on GitHub</a>
    </li>
</ul>

<?php

$this->template('html5','bottom');
