<?

$p->head[] = "<style>body { background-color:blue; }</style>";
$p->template('html5','top');



?>
    <a href="/test-history">test history</a>

    <input type="button" value="open skybox" onclick="$.skybox('/');" />
<?



$p->template('html5','bottom');