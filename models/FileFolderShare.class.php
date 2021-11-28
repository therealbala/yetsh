<?php

namespace App\Models;

use App\Core\Model;
use App\Models\FileFolderShareItem;

class FileFolderShare extends Model
{
    public function getFullSharingUrl() {
        return WEB_ROOT . '/shared/' . $this->access_key;
    }
    
    public function getFileFolderShareItems() {
        return FileFolderShareItem::loadByClause('file_folder_share_id = :file_folder_share_id', array(
            'file_folder_share_id' => $this->id,
        ));
    }
}
