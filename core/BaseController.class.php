<?php

namespace App\Core;

use App\Helpers\AuthHelper;
use App\Helpers\CoreHelper;
use App\Helpers\PluginHelper;
use App\Helpers\TemplateHelper;
use App\Helpers\UserHelper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

// Base Controller
class BaseController
{

    public function __construct() {
        // does nothing yet, can be implemented by the extending class
    }

    public function getAuth() {
        return AuthHelper::getAuth();
    }

    public function render($template, $params = array(), $templatePath = null, $includeCoreTemplateParam = true) {
        // add on core template params
        if ($includeCoreTemplateParam === true) {
            $params = array_merge($params, $this->getCoreTemplateParams());
        }

        return new Response(
                $this->getRenderedTemplate($template, $params, $templatePath)
        );
    }

    public function getRenderedTemplate($template, $params = array(), $templatePath = null) {
        return TemplateHelper::render($template, $params, $templatePath);
    }

    public function renderJson($arr) {
        $response = $this->renderContent(json_encode($arr));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    public function renderContent($str) {
        $response = new Response();
        $response->setContent($str);

        return $response;
    }

    public function redirect($url) {
        return new RedirectResponse($url);
    }

    public function renderFileContent($fileContent, $headers = array()) {
        return new Response($fileContent, 200, $headers);
    }

    public function renderDownloadFile($fileContent, $filename = 'file.csv') {
        $headers = array(
            'Cache-Control' => 'private',
            'Content-length' => strlen($fileContent),
            'Content-Disposition' => 'attachment; filename="' . $filename . '";'
        );

        return $this->renderFileContent($fileContent, $headers);
    }

    public function renderDownloadFileFromPath($filePath, $filename = 'file.csv') {
        // clear any buffering to stop possible memory issues with readfile()
        @ob_end_clean();

        // this should return the file to the browser as response
        $response = new BinaryFileResponse($filePath);

        // prepare needs to be called otherwise downloads have zero content
        $response->prepare(Request::createFromGlobals());

        // set content disposition inline of the file
        $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename
        );

        return $response;
    }

    public function renderEmpty200Response() {
        return $this->renderContent('');
    }

    public function render403() {
        $response = $this->render('error/403.html');
        $response->setStatusCode(403);

        return $response;
    }

    public function render404($show404Page = true) {
        if ($show404Page === true) {
            $response = $this->render('error/404.html');
        }
        else {
            $response = new Response();
        }

        $response->setStatusCode(404);

        return $response;
    }

    public function getRequest() {
        return Request::createFromGlobals();
    }

    public function requireLogin($redirectUrl = '/account/login', $minLevelId = 0) {
        // make sure the user is logged in
        $Auth = $this->getAuth();
        if (!$Auth->loggedIn()) {
            return $this->redirect(CoreHelper::getCoreSitePath() . $redirectUrl);
        }

        // check level
        if ($minLevelId > 0) {
            if ($Auth->level_id < $minLevelId) {
                return $this->redirect(CoreHelper::getCoreSitePath() . $redirectUrl);
            }
        }

        return false;
    }

    public function getCurrentRoute() {
        $uri = $_SERVER['REQUEST_URI'];
        if (strlen($uri) === 0) {
            return false;
        }

        // get the full path to the install, minus the host
        $basePath = str_replace(_CONFIG_SITE_HOST_URL, '', _CONFIG_SITE_FULL_URL);

        // if $basePath is greater than 1 character in length, replace the path
        if (strlen($basePath) > 0) {
            $uri = str_replace($basePath, '', $uri);
        }

        return $uri;
    }

    public function getCoreTemplateParams() {
        // preload any additional plugin navigation items
        $siteHeaderPluginNavigation = PluginHelper::callHookRecursive('siteHeaderNav');
        ksort($siteHeaderPluginNavigation);
        $siteFooterPluginNavigation = PluginHelper::callHookRecursive('siteFooterNav');
        ksort($siteFooterPluginNavigation);
        $siteFooterPluginBelowNavigation = PluginHelper::callHookRecursive('siteFooterBelowNav');
        ksort($siteFooterPluginBelowNavigation);

        // checker whether we should show the adblocker notice or not
        $adblockPage = false;
        if (defined('SITE_CONFIG_ADBLOCK_LIMITER') && strlen(SITE_CONFIG_ADBLOCK_LIMITER) && SITE_CONFIG_ADBLOCK_LIMITER != 'Disabled') {
            // make sure we should be showing ads for this user type
            if (UserHelper::showSiteAdverts() == true) {
                if (SITE_CONFIG_ADBLOCK_LIMITER == 'Block Download Pages') {
                    // see if this is a download request
                    if (defined('_INT_DOWNLOAD_REQ') && (_INT_DOWNLOAD_REQ == true)) {
                        // only show on download pages
                        $adblockPage = true;
                    }
                }
                else {
                    $adblockPage = true;
                }
            }
        }

        return array(
            'Auth' => $this->getAuth(),
            'sessionId' => session_id(),
            'cTracker' => md5(microtime()),
            'siteHeaderPluginNavigation' => $siteHeaderPluginNavigation,
            'siteFooterPluginNavigation' => $siteFooterPluginNavigation,
            'siteFooterPluginBelowNavigation' => $siteFooterPluginBelowNavigation,
            'adblockPage' => $adblockPage,
        );
    }

}
