<?php
namespace App\Core;
use App\Core\Database;
use App\Helpers\BannedIpHelper;
use App\Helpers\CoreHelper;
use App\Helpers\PluginHelper;
use App\Helpers\LogHelper;
use App\Helpers\RouteHelper;
use App\Helpers\SessionHelper;
class Framework
{
    const VERSION_NUMBER = '5.2.0';
    public static function run()
    {
        self::init();
        self::autoload();
        LogHelper::initErrorHandler();
        self::registerSession();
        self::postInit();
        self::dispatch();
    }
    public static function runLight()
    {
        self::init();
        self::autoload();
    }
    private static function init()
    {
        define('DS', DIRECTORY_SEPARATOR);
        define('DOC_ROOT', realpath(dirname(__FILE__) . '/../../'));
        define('APP_ROOT', DOC_ROOT . '/app');
        define('CORE_FRAMEWORK_ROOT', APP_ROOT . '/core');
        define('CORE_FRAMEWORK_HELPERS_ROOT', APP_ROOT . '/helpers');
        define('CORE_FRAMEWORK_LIBRARIES_ROOT', APP_ROOT . '/libraries');
        define('CORE_APPLICATION_CONTROLLERS_ROOT', APP_ROOT . '/controllers');
        define('CORE_APPLICATION_TEMPLATES_PATH', APP_ROOT . '/views');
        define('CORE_FRAMEWORK_SERVICES_ROOT', APP_ROOT . '/services');
        define('LOCAL_SITE_CONFIG_BASE_LOG_PATH', DOC_ROOT . '/logs/');
        require DOC_ROOT . '/_config.inc.php';
        if (!ini_get('date.timezone'))
        {
            date_default_timezone_set('GMT');
        }
        self::iniSets();
        define('WEB_ROOT', _CONFIG_SITE_PROTOCOL . '://' . _CONFIG_SITE_FULL_URL);
        define('CORE_WEB_ROOT', _CONFIG_SITE_PROTOCOL . '://' . _CONFIG_CORE_SITE_FULL_URL);
        include_once CORE_FRAMEWORK_ROOT . '/Database.class.php';
        include_once CORE_FRAMEWORK_ROOT . '/BaseController.class.php';
        include_once CORE_FRAMEWORK_ROOT . '/Auth.class.php';
        self::initConfigIntoMemory();
        define('PLUGIN_DIRECTORY_NAME', 'plugins');
        define('PLUGIN_DIRECTORY_ROOT', DOC_ROOT . '/' . PLUGIN_DIRECTORY_NAME . '/');
        define('PLUGIN_WEB_ROOT', WEB_ROOT . '/' . PLUGIN_DIRECTORY_NAME);
        define('CORE_APPLICATION_WEB_ROOT', WEB_ROOT . '');
        define('DOWNLOAD_TRACKER_UPDATE_FREQUENCY', 15);
        define('DOWNLOAD_TRACKER_PURGE_PERIOD', 7);
        define('ADMIN_FOLDER_NAME', 'admin');
        define('ADMIN_WEB_ROOT', WEB_ROOT . '/' . ADMIN_FOLDER_NAME);
        define('ACCOUNT_WEB_ROOT', WEB_ROOT . '/account');
        define('CACHE_DIRECTORY_NAME', 'cache');
        define('CACHE_DIRECTORY_ROOT', DOC_ROOT . '/' . CACHE_DIRECTORY_NAME);
        define('CACHE_WEB_ROOT', WEB_ROOT . '/' . CACHE_DIRECTORY_NAME);
        define('SITE_THEME_DIRECTORY_NAME', 'themes');
        define('SITE_THEME_DIRECTORY_ROOT', DOC_ROOT . '/' . SITE_THEME_DIRECTORY_NAME . '/');
        define('SITE_THEME_WEB_ROOT', WEB_ROOT . '/' . SITE_THEME_DIRECTORY_NAME . '/');
        define('CORE_ASSETS_WEB_ROOT', CORE_APPLICATION_WEB_ROOT . '/app/assets');
        define('CORE_ASSETS_ADMIN_WEB_ROOT', CORE_ASSETS_WEB_ROOT . '/admin');
        define('CORE_ASSETS_ADMIN_DIRECTORY_ROOT', APP_ROOT . '/assets/admin');
    }
    private static function postInit()
    {
        CoreHelper::checkMaintenanceMode(_INT_PAGE_URL);
        $db = Database::getDatabase();
        $currentLanguage = isset($_SESSION['_t']) ? $_SESSION['_t'] : SITE_CONFIG_SITE_LANGUAGE;
        $languageImagePath = '';
        $languageDirection = 'LTR';
        $languageDetails = $db->getRow("SELECT id, flag, direction " . "FROM language " . "WHERE isActive = 1 " . "AND languageName = :languageName " . "LIMIT 1", array(
            'languageName' => $currentLanguage,
        ));
        if ($languageDetails)
        {
            $languageDirection = $languageDetails['direction'];
            if (SITE_CONFIG_LANGUAGE_SEPARATE_LANGUAGE_IMAGES == 'yes')
            {
                $languageImagePath = $languageDetails['flag'] . '/';
            }
            define('SITE_CURRENT_LANGUAGE_ID', (int)$languageDetails['id']);
            $_SESSION['_tFlag'] = $languageDetails['flag'];
        }
        define('SITE_LANGUAGE_DIRECTION', $languageDirection);
        $siteTheme = SITE_CONFIG_SITE_THEME;
        if ((isset($_SESSION['_current_theme'])) && (strlen($_SESSION['_current_theme'])))
        {
            $siteTheme = $_SESSION['_current_theme'];
        }
        define('SITE_CURRENT_THEME_DIRECTORY_ROOT', SITE_THEME_DIRECTORY_ROOT . $siteTheme);
        define('SITE_IMAGE_DIRECTORY_ROOT', SITE_CURRENT_THEME_DIRECTORY_ROOT . '/assets/' . $languageImagePath . 'images');
        define('SITE_CSS_DIRECTORY_ROOT', SITE_CURRENT_THEME_DIRECTORY_ROOT . '/assets/' . $languageImagePath . 'styles');
        define('SITE_JS_DIRECTORY_ROOT', SITE_CURRENT_THEME_DIRECTORY_ROOT . '/assets/' . $languageImagePath . 'js');
        define('SITE_TEMPLATES_PATH', SITE_CURRENT_THEME_DIRECTORY_ROOT . '/views');
        define('SITE_THEME_PATH', SITE_THEME_WEB_ROOT . $siteTheme);
        define('SITE_IMAGE_PATH', SITE_THEME_PATH . '/assets/' . $languageImagePath . 'images');
        define('SITE_CSS_PATH', SITE_THEME_PATH . '/assets/' . $languageImagePath . 'styles');
        define('SITE_JS_PATH', SITE_THEME_PATH . '/assets/' . $languageImagePath . 'js');
        $bannedIP = BannedIpHelper::getBannedType();
        if (strtolower($bannedIP) === "whole site")
        {
            header('HTTP/1.1 404 Not Found');
            die();
        }
        CoreHelper::setupOldPaymentConstants();
        if (_CONFIG_DEMO_MODE == true)
        {
            if (isset($_REQUEST['_p']))
            {
                $_SESSION['_plugins'] = false;
                if ((int)$_REQUEST['_p'] == 1)
                {
                    $_SESSION['_plugins'] = true;
                }
                PluginHelper::loadPluginConfigurationFiles(true);
            }

            if (!isset($_SESSION['_plugins']))
            {
                $_SESSION['_plugins'] = false;
                PluginHelper::loadPluginConfigurationFiles(true);
            }
        }
        PluginHelper::loadPluginConfigurationFiles();
        PluginHelper::callHook('postFrameworkInit');
    }
    private static function iniSets()
    {
        @ini_set('memory_limit', '512M');
    }
    private static function registerSession()
    {
        if (SITE_CONFIG_USER_SESSION_TYPE === 'Database Sessions')
        {
            SessionHelper::register();
        }
        session_name('filehosting');
        session_set_cookie_params((int)SITE_CONFIG_SESSION_EXPIRY);
        session_start();
    }
    private static function autoload()
    {
        spl_autoload_register(array(
            __CLASS__,
            'load'
        ));
        require_once (APP_ROOT . '/libraries/vendor/autoload.php');
    }
    private static function load($className)
    {
        if (!strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
        {
            $className = basename($className);
        }
        $className = str_replace('\\', DS, $className);
        $filename = basename($className) . '.class.php';
        $className = strtolower(dirname($className)) . DS . $filename;
        if (file_exists(DOC_ROOT . DS . $className))
        {
            require_once (DOC_ROOT . DS . $className);
            return;
        }
        else
        {
            $error = 'Error: Could not auto load class: ' . $className . '<br/><br/>Ensure you\'ve set a "use" statement at the top of your code.<br/><br/>';
            $e = new \Exception();
            $error .= nl2br($e->getTraceAsString());
        }
    }
    private static function dispatch()
    {
        RouteHelper::processRoutes();
    }
    private static function initConfigIntoMemory()
    {
        $db = Database::getDatabase();
        $rows = $db->getRows('SELECT config_key, config_value ' . 'FROM site_config ' . 'ORDER BY config_group, config_key');
        if (COUNT($rows))
        {
            foreach ($rows AS $row)
            {
                $constantName = 'SITE_CONFIG_' . strtoupper($row['config_key']);
                if (!defined($constantName))
                {
                    define($constantName, $row['config_value']);
                }
            }
        }
    }
}