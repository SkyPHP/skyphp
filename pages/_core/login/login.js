$(function(){

    login_skybox();

});

function login_skybox(url,historyOff) {
	if (!url) url = window.location.href;
	$.skybox('/_core/login?url='+url,500,null,focus_username,historyOff);
    History.pushState({login:1}, "Login", "?login");
}

var focus_username = function () {
	document.getElementById('login_username').focus();
}


function login_submit(theform,url) {
	$('#login_loading').html('<div align="center"><img src="/images/loading.gif"><br /><br />authenticating...</div>');
    $.post(
        '/_core/login/authenticate',
        $(theform).serialize(),
        function(data){
			if (data=='true') {
				if ( !url ) url = window.location.href;
				tmp = url.indexOf('?logout=1');
				if ( tmp > -1 )
					url = url.substring(0,tmp);
					
				tmp = url.indexOf('#/skybox');
				if ( tmp > -1 )
					url = url.substring(0,tmp);
					
				tmp = url.indexOf('#skybox');
				if ( tmp > -1 )
					url = url.substring(0,tmp);
				//alert(url); return;
				window.location.href = url;
			} else if (data=='false') {
				document.getElementById('login_password').value = '';
				document.getElementById('login_loading').innerHTML = '';
				document.getElementById('incorrect_login').innerHTML = '<font color="red">Incorrect login.  Try again.</font>';
			} else {
				document.getElementById('login_password').value = '';
				document.getElementById('login_loading').innerHTML = '';
				document.getElementById('incorrect_login').innerHTML = req.responseText;
			}
		}
	);	
}
