
$('#test').live('click',function(){
    console.log('test .live(click)');
    return false;
});

$('#test-button').live('click',function(){
    console.log('test-button .live(click)');
});

$('#test2').click(function(){
    console.log('test2 .click');
    return false;
});