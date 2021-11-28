<?php

namespace App\Models;

use App\Core\Model;
use App\Helpers\AdminApiHelper;
use App\Helpers\CacheHelper;
use App\Helpers\CoreHelper;
use App\Helpers\FileHelper;
use App\Helpers\LogHelper;
use App\Helpers\UserHelper;

class FileServer extends Model
{

    public function getCacheFolderPath() {
        return $this->scriptRootPath . '/' . CACHE_DIRECTORY_NAME;
    }
    
    public function purgeApplicationCache() {
        // if local server, simply call the purge request, no need for API
        if($this->serverType === 'local') {
            CacheHelper::removeCoreApplicationCache();
            
            return array(
                'error' => false,
                'msg' => 'Application cache on local server purged.',
            );
        }
        
        // get an admin API details for remote calls
        $apiCredentials = UserHelper::getAdminApiDetails();
        if ($apiCredentials === false) {
            // log
            $error = 'Failed getting any admin API credentials in order to make a request to purge application cache.';
            LogHelper::error($error);

            return array(
                'error' => true,
                'msg' => $error,
            );
        }

        // call purge cache over internal API
        $correctServerPath = FileHelper::getFileDomainAndPath(null, $this->id, true);
        $url = AdminApiHelper::createApiUrl($correctServerPath, $apiCredentials['apikey'], $apiCredentials['username'], 'purgecache');
        $rsJson = CoreHelper::getRemoteUrlContent($url);
        if (strlen($rsJson) === 0) {
            // log
            $error = 'Could not contact file server to purge the application cache ('.$correctServerPath.').';
            LogHelper::error($error);
            
            return array(
                'error' => true,
                'msg' => $error,
            );
        }
        
        // attempt to convert to array
        $rsArr = json_decode($rsJson, true);
        if(json_last_error() !== JSON_ERROR_NONE) {
            // log
            $error = 'Failed reading response from request to clear application cache ('.$correctServerPath.').';
            LogHelper::error($error);
            
            return array(
                'error' => true,
                'msg' => $error,
            );
        }
        
        return array(
                'error' => $rsArr['error'],
                'msg' => $rsArr['error']?$rsArr['error_msg']:'Cache for "'.$this->serverLabel.'" purged.',
                'request_reponse' => json_decode($rsJson, true),
            );
    }

}
