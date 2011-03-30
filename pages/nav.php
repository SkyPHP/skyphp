<?
$nav = array(
    'A' => '/a',
    'B' => '/b',
    'C' => '/c'
);
?>
Main Nav:
<ul>
<?
    foreach ( $nav as $item => $href ) {
        if ( $p->uri == $href ) $class = 'bold';
        else $class = '';
?>
    <li class="<?=$class?>"><?=$item?></li>
<?
    }
?>
</ul>