<?php

namespace App\Models;

use App\Core\Model;
use App\Helpers\PluginHelper;

class Plugin extends Model
{
    /**
     * Override core save method so we can ensure the plugin cache is rebuilt
     * on every save.
     */
    public function save() {
        // first do parent action
        $rs = parent::save();
                
        // make sure the plugin cache is refreshed
        PluginHelper::loadPluginConfigurationFiles(true);
    }
}
