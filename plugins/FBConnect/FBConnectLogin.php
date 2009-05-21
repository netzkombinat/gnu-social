<?php
/**
 * Laconica, the distributed open-source microblogging tool
 *
 * Plugin to enable Facebook Connect
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @category  Plugin
 * @package   Laconica
 * @author    Zach Copley <zach@controlyourself.ca>
 * @copyright 2009 Control Yourself, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://laconi.ca/
 */

require_once INSTALLDIR . '/plugins/FBConnect/FBConnectLogin.php';
require_once INSTALLDIR . '/lib/facebookutil.php';

class FBConnectloginAction extends Action
{

    var $fbuid      = null;
    var $fb_fields  = null;

    function prepare($args) {
        parent::prepare($args);

        $this->fbuid = getFacebook()->get_loggedin_user();
        $this->fb_fields = $this->getFacebookFields($this->fbuid,
            array('first_name', 'last_name', 'name'));

        return true;
    }

    function handle($args)
    {
        parent::handle($args);

        if (common_is_real_login()) {
            $this->clientError(_('Already logged in.'));
        } else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            $token = $this->trimmed('token');
            if (!$token || $token != common_session_token()) {
                $this->showForm(_('There was a problem with your session token. Try again, please.'));
                return;
            }
            if ($this->arg('create')) {
                if (!$this->boolean('license')) {
                    $this->showForm(_('You can\'t register if you don\'t agree to the license.'),
                                    $this->trimmed('newname'));
                    return;
                }
                $this->createNewUser();
            } else if ($this->arg('connect')) {
                $this->connectUser();
            } else {
                common_debug(print_r($this->args, true), __FILE__);
                $this->showForm(_('Something weird happened.'),
                                $this->trimmed('newname'));
            }
        } else {
            $this->tryLogin();
        }
    }

    function showPageNotice()
    {
        if ($this->error) {
            $this->element('div', array('class' => 'error'), $this->error);
        } else {
            $this->element('div', 'instructions',
                           sprintf(_('This is the first time you\'ve logged into %s so we must connect your Facebook to a local account. You can either create a new account, or connect with your existing account, if you have one.'), common_config('site', 'name')));
        }
    }

    function title()
    {
        return _('Facebook Account Setup');
    }

    function showForm($error=null, $username=null)
    {
        $this->error = $error;
        $this->username = $username;

        $this->showPage();
    }

    function showPage()
    {
        parent::showPage();
    }

    function showContent()
    {
        if (!empty($this->message_text)) {
            $this->element('p', null, $this->message);
            return;
        }

        $this->elementStart('form', array('method' => 'post',
                                          'id' => 'account_connect',
                                          'action' => common_local_url('fbconnectlogin')));
        $this->hidden('token', common_session_token());
        $this->element('h2', null,
                       _('Create new account'));
        $this->element('p', null,
                       _('Create a new user with this nickname.'));
        $this->input('newname', _('New nickname'),
                     ($this->username) ? $this->username : '',
                     _('1-64 lowercase letters or numbers, no punctuation or spaces'));
        $this->elementStart('p');
        $this->element('input', array('type' => 'checkbox',
                                      'id' => 'license',
                                      'name' => 'license',
                                      'value' => 'true'));
        $this->text(_('My text and files are available under '));
        $this->element('a', array('href' => common_config('license', 'url')),
                       common_config('license', 'title'));
        $this->text(_(' except this private data: password, email address, IM address, phone number.'));
        $this->elementEnd('p');
        $this->submit('create', _('Create'));
        $this->element('h2', null,
                       _('Connect existing account'));
        $this->element('p', null,
                       _('If you already have an account, login with your username and password to connect it to your Facebook.'));
        $this->input('nickname', _('Existing nickname'));
        $this->password('password', _('Password'));
        $this->submit('connect', _('Connect'));
        $this->elementEnd('form');
    }

    function message($msg)
    {
        $this->message_text = $msg;
        $this->showPage();
    }

    function createNewUser()
    {

        if (common_config('site', 'closed')) {
            $this->clientError(_('Registration not allowed.'));
            return;
        }

        $invite = null;

        if (common_config('site', 'inviteonly')) {
            $code = $_SESSION['invitecode'];
            if (empty($code)) {
                $this->clientError(_('Registration not allowed.'));
                return;
            }

            $invite = Invitation::staticGet($code);

            if (empty($invite)) {
                $this->clientError(_('Not a valid invitation code.'));
                return;
            }
        }

        $nickname = $this->trimmed('newname');

        if (!Validate::string($nickname, array('min_length' => 1,
                                               'max_length' => 64,
                                               'format' => VALIDATE_NUM . VALIDATE_ALPHA_LOWER))) {
            $this->showForm(_('Nickname must have only lowercase letters and numbers and no spaces.'));
            return;
        }

        if (!User::allowed_nickname($nickname)) {
            $this->showForm(_('Nickname not allowed.'));
            return;
        }

        if (User::staticGet('nickname', $nickname)) {
            $this->showForm(_('Nickname already in use. Try another one.'));
            return;
        }

        $fullname = trim($this->fb_fields['firstname'] .
            ' ' . $this->fb_fields['lastname']);

        $args = array('nickname' => $nickname, 'fullname' => $fullname);

        if (!empty($invite)) {
            $args['code'] = $invite->code;
        }

        $user = User::register($args);

        $result = $this->flinkUser($user->id, $this->fbuid);

        if (!$result) {
            $this->serverError(_('Error connecting user to Facebook.'));
            return;
        }

        common_set_user($user);
        common_real_login(true);

        common_debug("Registered new user $user->id from Facebook user $this->fbuid");

        common_redirect(common_local_url('showstream', array('nickname' => $user->nickname)),
                        303);
    }

    function connectUser()
    {
        $nickname = $this->trimmed('nickname');
        $password = $this->trimmed('password');

        if (!common_check_user($nickname, $password)) {
            $this->showForm(_('Invalid username or password.'));
            return;
        }

        $user = User::staticGet('nickname', $nickname);

        if ($user) {
            common_debug("Legit user to connect to Facebook: $nickname");
        }

        $result = $this->flinkUser($user->id, $this->fbuid);

        if (!$result) {
            $this->serverError(_('Error connecting user to Facebook.'));
            return;
        }

        common_debug("Connected Facebook user $this->fbuid to local user $user->id");

        common_set_user($user);
        common_real_login(true);

        $this->goHome($user->nickname);
    }

    function tryLogin()
    {
        common_debug("Trying Facebook Login...");

        $flink = Foreign_link::getByForeignID($this->fbuid, FACEBOOK_SERVICE);

        if ($flink) {
            $user = $flink->getUser();

            if ($user) {

                common_debug("Logged in Facebook user $flink->foreign_id as user $user->id ($user->nickname)");

                common_set_user($user);
                common_real_login(true);
                $this->goHome($user->nickname);
            }

        } else {
            $this->showForm(null, $this->bestNewNickname());
        }
    }

    function goHome($nickname)
    {
        $url = common_get_returnto();
        if ($url) {
            // We don't have to return to it again
            common_set_returnto(null);
        } else {
            $url = common_local_url('all',
                                    array('nickname' =>
                                          $nickname));
        }

        common_redirect($url, 303);
    }

    function flinkUser($user_id, $fbuid)
    {
        $flink = new Foreign_link();
        $flink->user_id = $user_id;
        $flink->foreign_id = $fbuid;
        $flink->service = FACEBOOK_SERVICE;
        $flink->created = common_sql_now();

        $flink_id = $flink->insert();

        return $flink_id;
    }

    function bestNewNickname()
    {
        if (!empty($this->fb_fields['name'])) {
            $nickname = $this->nicknamize($this->fb_fields['name']);
            if ($this->isNewNickname($nickname)) {
                return $nickname;
            }
        }

        // Try the full name

        $fullname = trim($this->fb_fields['firstname'] .
            ' ' . $this->fb_fields['lastname']);

        if (!empty($fullname)) {
            $fullname = $this->nicknamize($fullname);
            if ($this->isNewNickname($fullname)) {
                return $fullname;
            }
        }

        return null;
    }

     // Given a string, try to make it work as a nickname

     function nicknamize($str)
     {
         $str = preg_replace('/\W/', '', $str);
         return strtolower($str);
     }

    function isNewNickname($str)
    {
        if (!Validate::string($str, array('min_length' => 1,
                                          'max_length' => 64,
                                          'format' => VALIDATE_NUM . VALIDATE_ALPHA_LOWER))) {
            return false;
        }
        if (!User::allowed_nickname($str)) {
            return false;
        }
        if (User::staticGet('nickname', $str)) {
            return false;
        }
        return true;
    }

    // XXX: Consider moving this to lib/facebookutil.php
    function getFacebookFields($fb_uid, $fields) {
        try {
            $infos = getFacebook()->api_client->users_getInfo($fb_uid, $fields);

            if (empty($infos)) {
                return null;
            }
            return reset($infos);

        } catch (Exception $e) {
            error_log("Failure in the api when requesting " . join(",", $fields)
                  ." on uid " . $fb_uid . " : ". $e->getMessage());
              return null;
        }
    }

}