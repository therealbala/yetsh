<?php

namespace App\Core;

use App\Helpers\AuthHelper;
use App\Helpers\BannedIpHelper;
use App\Helpers\CoreHelper;
use App\Helpers\UserHelper;
use App\Helpers\SessionHelper;
use App\Models\User;
use App\Services\Password;

class Auth
{
    public $id;
    public $username;
    public $level_id;
    public $user = null; // DBObject User object (if available)
    public $lastLoginError = '';

    // Call with no arguments to attempt to restore a previous logged in session
    // which then falls back to a guest user (which can then be logged in using
    // $this->login($username, $rawPassword). Or pass a user_id to simply login that user. The
    // $seriously is just a safeguard to be certain you really do want to blindly
    // login a user. Set it to true.

    public function __construct($userToImpersonate = null) {
        $this->id = null;
        $this->username = null;
        $this->level_id = 0;
        $this->package_id = 0;
        $this->level = UserHelper::getLevelLabel($this->level_id);
        $this->user = null;
        $this->lastLoginError = '';

        if (!is_null($userToImpersonate)) {
            return $this->impersonate($userToImpersonate);
        }

        if ($this->attemptSessionLogin()) {
            return;
        }
    }

    // You'll typically call this function when a user logs in using
    // a form. Pass in their username and password.
    // Takes a username and a *plain text* password
    public function login($username, $rawPassword, $fromLoginForm = false, $accessCode2fa = null) {
        $rs = $this->convertPassword($username, $rawPassword);
        if ($rs === false) {
            return false;
        }

        return $this->attemptLogin($username, $rawPassword, false, $fromLoginForm, $accessCode2fa);
    }

    // manage convertions to sha256, this code is only for migration
    public function convertPassword($username, $rawPassword) {
        // check for existing user
        $user = User::loadOne('username', $username);
        if ($user === false) {
            return false;
        }

        // see if it matches the one entered
        if ($user->password === md5($rawPassword)) {
            // create new password
            $sha256Password = Password::createHash($rawPassword);

            // update user with new
            $user->password = $sha256Password;
            $user->save();
        }

        return true;
    }

    public function logout() {
        $this->id = null;
        $this->username = null;
        $this->level_id = 0;
        $this->package_id = 0;
        $this->level = 'guest';
        $this->user = null;
        $this->lastLoginError = '';

        $_SESSION['user'] = '';
        unset($_SESSION['user']);
        if (isset($_SESSION['_old_user'])) {
            // revert old session if this is a logout of impersonate user
            $_SESSION['user'] = $_SESSION['_old_user'];
            $_SESSION['_old_user'] = '';
            unset($_SESSION['_old_user']);

            // redirect to file manager as old user
            CoreHelper::redirect(WEB_ROOT . '/account_home.html');
        }
        else {
            // clear session
            session_destroy();
            setcookie('spf', '.', time() - 3600, '/', _CONFIG_SITE_HOST_URL);
        }
    }

    // Is a user logged in? This was broken out into its own function
    // in case extra logic is ever required beyond a simple bool value.
    public function loggedIn() {
        return $this->user !== null;
    }

    // Helper function that redirects away from 'admin only' pages
    public function requireAdmin() {
        // check for login attempts
        if (isset($_REQUEST['username']) && isset($_REQUEST['password'])) {
            $this->login($_REQUEST['username'], $_REQUEST['password']);
        }

        // ensure it's an admin user
        $this->requireAccessLevel(20, ADMIN_WEB_ROOT . "/login");
    }

    // Helper function that redirects away from 'member only' pages
    public function requireUser($url) {
        $this->requireAccessLevel(1, $url);
    }

    /*
     * Function to handle access rights and minimum permission levels for access.
     * The higher the number the greater the permission requirement. See the
     * database table called 'user_level' for the permission level_ids.
     * 
     * @param type $level
     */

    public function requireAccessLevel($minRequiredLevel = 0, $redirectOnFailure = 'login.php') {
        // store the current url for redirects later
        $_SESSION['_redirect_url'] = CoreHelper::getCurrentBrowserUrl();

        // check for login attempts
        if (isset($_REQUEST['username']) && isset($_REQUEST['password'])) {
            $this->login($_REQUEST['username'], $_REQUEST['password']);
        }

        $userType = UserHelper::getLevelTypeFromPackageId($this->package_id);
        switch ($minRequiredLevel) {
            case 0:
                if (in_array($userType, array('moderator', 'free', 'paid', 'admin', 'guest', 'nonuser'))) {
                    return true;
                }
                break;
            case 1:
                if (in_array($userType, array('moderator', 'free', 'paid', 'admin'))) {
                    return true;
                }
                break;
            case 20:
                if (in_array($userType, array('admin'))) {
                    return true;
                }
                break;
            case 10:
                if (in_array($userType, array('moderator', 'admin'))) {
                    return true;
                }
                break;
            case 2:
                if (in_array($userType, array('moderator', 'paid', 'admin'))) {
                    return true;
                }
                break;
        }

        if (strlen($redirectOnFailure)) {
            CoreHelper::redirect($redirectOnFailure);
        }

        return false;
    }

    public function hasAccessLevel($minRequiredLevel = 0) {
        return $this->requireAccessLevel($minRequiredLevel, null);
    }

    /*
     * Used to check if the current user is an admin user
     */

    public function isAdmin() {
        return $this->hasAccessLevel(20);
    }

    // Login a user simply by passing in their username or id. Does
    // not check against a password. Useful for allowing an admin user
    // to temporarily login as a standard user for troubleshooting.
    // Takes an id or username
    public function impersonate($userIdOrUsername, $accessCode2fa = null) {
        if (is_numeric($userIdOrUsername)) {
            $user = User::loadOneById($userIdOrUsername);
        }
        else {
            $user = User::loadOneByClause('username = :username', array(
                'username' => $userIdOrUsername,
            ));
        }
        
        if(!$user) {
            return false;
        }
        
        // check for 2fa, if enabled
        if (SITE_CONFIG_ENABLE_2FA === 'yes' 
                && (int) $user->login_2fa_enabled === 1) {
            if ($accessCode2fa === null) {
                $this->lastLoginError = '2fa_check_needed';

                return false;
            }
            else {
                // check 2fa code
                $secret = CoreHelper::base32Encode($user->username . $user->get2FASalt());
                $g = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();

                // check submitted code
                if (!$g->checkCode($secret, $accessCode2fa)) {
                    $this->lastLoginError = 'invalid_2fa_code';

                    return false;
                }
            }
        }

        // store within the Auth object
        $this->id = $user->id;
        $this->username = $user->username;
        $this->email = $user->email;
        $this->package_id = $user->level_id;
        $this->level_id = UserHelper::getLevelIdFromPackageId($this->package_id);
        $this->level = UserHelper::getLevelLabel($user->level_id);
        $this->paidExpiryDate = $user->paidExpiryDate;
        $this->paymentTracker = $user->paymentTracker;

        // load any additional user info
        $this->user = $user;
        $this->lastLoginError = '';

        // store in session
        $this->storeSessionData();

        return true;
    }

    // Attempt to login using data stored in the current session
    private function attemptSessionLogin() {
        if (isset($_SESSION['user'])) {
            $sessionAuth = unserialize($_SESSION['user']);
            if (is_object($sessionAuth)) {
                foreach ($sessionAuth AS $k => $v) {
                    $this->$k = $v;
                }
            }

            return true;
        }

        return false;
    }

    // The function that actually verifies an attempted login and
    // processes it if successful.
    // Takes a username and a raw password
    public function attemptLogin($username, $rawPassword, $sessionLogin = false, $fromLoginForm = false, $accessCode2fa = null, $skip2fa = false) {
        // check for openssl, required for login
        if (!extension_loaded('openssl')) {
            $this->lastLoginError = 'no_openssl_module';

            return false;
        }

        // load our user
        $user = User::loadOne('username', $username);
        if ($user === false) {
            // log failure
            AuthHelper::logFailedLoginAttempt(CoreHelper::getUsersIPAddress(), $username);
            $this->lastLoginError = 'username_not_found';

            return false;
        }

        // validate password
        if ($sessionLogin === false) {
            if (Password::validatePassword($rawPassword, $user->password) === false) {
                // log failure
                AuthHelper::logFailedLoginAttempt(CoreHelper::getUsersIPAddress(), $username);
                $this->lastLoginError = 'password_invalid';

                return false;
            }
        }

        // make sure account is active
        if ($user->status !== "active") {
            $this->lastLoginError = 'account_not_active';

            return false;
        }
        else {
            // check user isn't banned from logging in
            $bannedIp = BannedIpHelper::getBannedIPData();
            if ($bannedIp) {
                if ($bannedIp->banType == 'Login') {
                    $this->lastLoginError = 'ip_banned';

                    return false;
                }
            }
        }

        // check for 2fa, if enabled
        if (SITE_CONFIG_ENABLE_2FA === 'yes' 
                && $fromLoginForm === true 
                && (int) $user->login_2fa_enabled === 1
                && $skip2fa === false) {
            // store username and password for later login
            $_SESSION['_2faUser'] = array(
                'username' => $username,
                'rawPassword' => $rawPassword,
            );
            
            if ($accessCode2fa === null) {
                $this->lastLoginError = '2fa_check_needed';

                return false;
            }
            else {
                // check 2fa code
                $secret = CoreHelper::base32Encode($user->username . $user->get2FASalt());
                $g = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();

                // check submitted code
                if (!$g->checkCode($secret, $accessCode2fa)) {
                    $this->lastLoginError = 'invalid_2fa_code';

                    return false;
                }
            }
        }

        // stop account sharing
        if ($fromLoginForm == true) {
            SessionHelper::clearSessionByUserId($user->id);
        }

        $this->id = $user->id;
        $this->username = $user->username;
        $this->email = $user->email;
        $this->package_id = $user->level_id;
        $this->level_id = UserHelper::getLevelIdFromPackageId($this->package_id);
        $this->level = UserHelper::getLevelLabel($user->level_id);
        $this->paidExpiryDate = $user->paidExpiryDate;
        $this->paymentTracker = $user->paymentTracker;

        // load any additional user info
        $this->user = $user;
        $this->lastLoginError = '';

        // update lastlogindate
        $iPAddress = CoreHelper::getUsersIPAddress();
        if ($sessionLogin === false) {
            $user->lastlogindate = CoreHelper::sqlDateTime();
            $user->lastloginip = $iPAddress;
            $user->save();
        }

        // remove any failed logins for the IP address
        AuthHelper::clearAllLoginAttemptsForIp($iPAddress);

        // log IP address for login
        if ($fromLoginForm == true) {
            AuthHelper::logSuccessfulLogin($this->id, $iPAddress);
        }

        // delete old session data
        $this->purgeOldSessionData();

        // setup session
        $this->storeSessionData();
        if (($sessionLogin == false) && (SITE_CONFIG_LANGUAGE_USER_SELECT_LANGUAGE == 'yes')) {
            $this->setSessionLanguage();
        }

        $this->lastLoginError = '';
        $this->handleSuccessfullLoginRedirect();

        return true;
    }

    public function handleSuccessfullLoginRedirect() {
        if (!isset($_SESSION['_redirect_url'])) {
            return false;
        }

        if (strlen($_SESSION['_redirect_url']) === 0) {
            return false;
        }

        // don't redirect login pages
        $redirectUrl = $_SESSION['_redirect_url'];
        unset($_SESSION['_redirect_url']);
        if ((strpos($redirectUrl, 'login') === false) && (strpos($redirectUrl, ADMIN_FOLDER_NAME) !== false)) {
            CoreHelper::redirect($redirectUrl);
        }

        return false;
    }

    public function purgeOldSessionData() {
        // get database
        $db = Database::getDatabase();

        // delete old session data
        $db->query('DELETE FROM `sessions` '
                . 'WHERE `updated_on` < :updated_on', array('updated_on' => time() - (60 * 60 * 24 * 2))); // 2 days
    }

    // reload session, used for account upgrades
    public function reloadSession() {
        if ($this->id == 0) {
            return false;
        }

        // reload the user object
        $this->user = User::loadOneById($this->id);

        // update the auth object
        $this->id = $this->user->id;
        $this->username = $this->user->username;
        $this->email = $this->user->email;
        $this->package_id = $this->user->level_id;
        $this->level_id = UserHelper::getLevelIdFromPackageId($this->package_id);
        $this->level = UserHelper::getLevelLabel($this->user->level_id);
        $this->lastLoginError = '';

        // setup session
        $this->storeSessionData();
    }

    public function getAccountScreenName() {
        return $this->user->getAccountScreenName();
    }

    private function setSessionLanguage() {
        $db = Database::getDatabase();
        $language = $db->getRow("SELECT languageName, flag "
                . "FROM language "
                . "WHERE isActive = 1 "
                . "AND id = :id "
                . "LIMIT 1", array(
            'id' => $this->user->languageId,
        ));
        if ($language) {
            $_SESSION['_t'] = $language['languageName'];
            $_SESSION['_tFlag'] = $languageName['flag'];
        }
    }

    // stores current object in session
    private function storeSessionData() {
        $_SESSION['user'] = serialize($this);
    }

    private function createHashedPassword($rawPassword) {
        return MD5($rawPassword);
    }

    // The function that actually verifies an attempted login and
    // processes it if successful.
    // Takes an API key pair
    // @TODO - merge this with the attemptLogin function above
    public function loginUsingApiPair($key1, $key2) {
        $db = Database::getDatabase();

        // check the api keys
        $foundKeys = (int) $db->getValue('SELECT user_id FROM apiv2_api_key WHERE key_public = :key_public AND key_secret = :key_secret LIMIT 1', array(
                    'key_public' => $key1,
                    'key_secret' => $key2,
        ));
        if (!$foundKeys) {

            // log failure
            AuthHelper::logFailedLoginAttempt(CoreHelper::getUsersIPAddress());

            return false;
        }

        // we found the user, setup the session
        $row = $db->getRow('SELECT * FROM users WHERE id = ' . (int) $foundKeys . ' LIMIT 1');
        if ($row === false) {
            return false;
        }

        // make sure account is active
        if ($row['status'] != "active") {
            return false;
        }
        else {
            // check user isn't banned from logging in
            $bannedIp = BannedIpHelper::getBannedIPData();
            if ($bannedIp) {
                if ($bannedIp->banType == 'Login') {
                    return false;
                }
            }
        }

        $this->id = $row['id'];
        $this->username = $row['username'];
        $this->email = $row['email'];
        $this->package_id = $row['level_id'];
        $this->level_id = UserHelper::getLevelIdFromPackageId($this->package_id);
        $this->level = UserHelper::getLevelLabel($row['level_id']);
        $this->paidExpiryDate = $row['paidExpiryDate'];
        $this->paymentTracker = $row['paymentTracker'];

        // load any additional user info
        $this->user = User::loadOneById($row['id']);
        $this->lastLoginError = '';

        // update lastlogindate
        $iPAddress = CoreHelper::getUsersIPAddress();
        $db->query('UPDATE users SET lastlogindate = NOW(), lastloginip = :ip WHERE id = :id LIMIT 1', array('ip' => $iPAddress, 'id' => $this->id));

        // remove any failed logins for the IP address
        AuthHelper::clearAllLoginAttemptsForIp($iPAddress);

        // log IP address for login
        AuthHelper::logSuccessfulLogin($this->id, $iPAddress);

        // delete old session data
        $this->purgeOldSessionData();

        // setup session
        $this->storeSessionData();

        return true;
    }

}
