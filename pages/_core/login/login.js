$(function(){

    login_skybox();

});

function login_skybox(url,historyOff) {
	if (!url) url = window.location.href;
	skybox('/_core/login?url='+url,500,null,focus_username,historyOff);
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

function activation(ide) {
	AjaxRequest.post({
		'url' : '/login/activation_email?ide='+ide,
		'onSuccess':function(req){ 
			if (!req.responseText) {
				document.getElementById('incorrect_login').innerHTML = '<font color="green">Activation email sent.</font>';
			} else {
				document.getElementById('incorrect_login').innerHTML = req.responseText;
			}
		}
	});	
}

function forgotpw_submit(theform) {
	theform.action = '/login/forgotpw';
	theform.method = 'post';
	AjaxRequest.submit(theform,{
		'onSuccess':function(req){
			document.getElementById('skybox').innerHTML = req.responseText;
		}
	});
}

function forgotpw() {
	AjaxRequest.post({
		'url' : '/login/forgotpw',
		'onSuccess':function(req){ 
			document.getElementById('skybox').innerHTML = req.responseText;
			focus_username();
		}
	});	
}

function back_to_login() {
	AjaxRequest.post({
		'url' : '/login/login-skybox',
		'onSuccess':function(req){ 
			 document.getElementById('skybox').innerHTML = req.responseText;
			 focus_username();
		}
	});	
}

function logout(url) {
	AjaxRequest.post({
		'url' : '/login/logout',
		'onSuccess':function(req){ 
			window.location.href = url;
		}
	});	
}