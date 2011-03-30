<?
if ( $template_area == 'top' ) {
    $this->template('html5','top');
?>

    <div id="nav">
<?
        include('pages/nav.php');
?>
    </div>

    <div id="page">

<?
} else if ( $template_area == 'bottom' ) {
?>

    </div>

<?
    $this->template('html5','bottom');
}
?>
