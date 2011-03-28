<?
if ( $template_area == 'top' ) {

    $this->template('html5','top');

?>
    <div id="website">
<?

} else if ( $template_area == 'bottom' ) {

?>
    </div>
<?

    $this->template('html5','bottom');

}
?>
