<h1 class="module-bar">Sign In<a class="close" href="javascript:history.back();">[x]</a></h1>
<div class="module-body">
    <div id="login_body">
        <div style="float:left; display:inline; position:relative; width:300px;">
            <div id="incorrect_login"></div>
            <form method="post">
            <table>
                <tr>
                    <td><h2>username</h2></td>
                    <td><input name="login_username" type="text" id="login_username" autocomplete="off" /></td>
                </tr>
                <tr>
                    <td><h2>password</h2></td>
                    <td><input name="login_password" type="password" id="login_password" /></td>
                </tr>
				<tr>
					<td><h2>remember<br>me</h2></td>
					<td><input type="checkbox" id="remember_me" name="remember_me" /></td>
				</tr>
            </table>
            <div id="login_button">
                <input type="button" onclick="login_submit(this.form);" value="Sign In &raquo;">
            </div>
            <div id="login_help">
                <a href="javascript:forgotpw();">I forgot my password.</a>
            </div>
            </form>
        </div>

        <div id="login_loading" style="float:left; display:inline; position:relative; width:120px;">
        </div>

        <div class="clear"></div>

    </div>
</div>