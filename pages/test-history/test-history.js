
$('#test1').click(function(){
    History.pushState({state:1}, 'State 1', '#'+Math.random());
});

$('#test2').click(function(){
    rand = Math.random();
    History.pushState({state:rand}, null, addqs('skybox='+rand));
});