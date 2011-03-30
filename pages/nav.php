<?
$nav = array(
    //'A' => '/a',
    'B' => '/b',
    'C' => '/c',
    'D' => '/d',
);
?>
Main Nav:
<ul>
<?
    foreach ( $nav as $item => $href ) {
?>
    <li class="<?=$class?>">
<?
        if ( $p->uri == $href ) {
?>
        <span style="font-weight:bold;"><?=$item?></span>
<?
        } else {
?>
        <a href="<?=$href?>"><?=$item?></a></li>
<?
        }
    }
?>
</ul>
<input type="button" value="new skybox" onclick="$.skybox('/');" />