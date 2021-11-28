<?php

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use App\Models\FileFolderShare;
use App\Models\FileFolderShareItem;
use App\Helpers\CoreHelper;
use App\Helpers\FileHelper;
use App\Helpers\FileFolderHelper;

class FileFolder extends Model
{
   public function getFolderUrl() {
        return WEB_ROOT . '/folder/' . $this->urlHash . '/' . $this->getSafeFoldernameForUrl();
    }

    public function getAlbumUrl() {
        return $this->getFolderUrl();
    }

    public function getSafeFoldernameForUrl() {
        return str_replace(array(" ", "\"", "'", ";", "#", "%"), "_", strip_tags($this->folderName));
    }

    public function getCoverData() {
        $db = Database::getDatabase();

        // get convert id
        $coverImageId = $this->coverImageId;
        if ($coverImageId == null) {
            // load new and set in the db
            $coverImageData = $db->getRow('SELECT id, unique_hash '
                    . 'FROM file '
                    . 'WHERE folderId = :folderId '
                    . 'AND status = "active" '
                    . 'AND extension IN(' . FileHelper::getImageExtStringForSql() . ') '
                    . 'LIMIT 1', array(
                        'folderId' => $this->id,
                    ));
            if ($coverImageData) {
                $this->setCoverId($coverImageData['id']);
            }

            // make sure we have the file hash
            $uniqueHash = $coverImageData['unique_hash'];
            if (strlen($uniqueHash) == 0) {
                $uniqueHash = FileHelper::createUniqueFileHash($coverImageData['id']);
            }

            return array('file_id' => $coverImageData['id'], 'unique_hash' => $uniqueHash);
        }

        // make sure cover image exists, update to new if not
        $coverImageData = $db->getRow('SELECT id, unique_hash '
                . 'FROM file '
                . 'WHERE id = :id '
                . 'AND status = "active" '
                . 'AND extension IN(' . FileHelper::getImageExtStringForSql() . ') '
                . 'LIMIT 1', array(
                    'id' => (int) $coverImageId,
                ));
        if (!$coverImageData) {
            $coverImageData = $db->getRow('SELECT id, unique_hash '
                    . 'FROM file '
                    . 'WHERE folderId = :folderId '
                    . 'AND status = "active" '
                    . 'AND extension IN(' . FileHelper::getImageExtStringForSql() . ') '
                    . 'LIMIT 1', array(
                        'folderId' => (int) $this->id,
                    ));
            if ($coverImageData) {
                $this->setCoverId($coverImageData['id']);
            }
        }

        // make sure we have the file hash
        $uniqueHash = $coverImageData['unique_hash'];
        if (strlen($uniqueHash) == 0) {
            $uniqueHash = FileHelper::createUniqueFileHash($coverImageData['id']);
        }

        return array('file_id' => $coverImageData['id'], 'unique_hash' => $uniqueHash);
    }

    public function setCoverId($coverId) {
        $db = Database::getDatabase();
        return $db->query('UPDATE file_folder '
                . 'SET coverImageId = :coverImageId, date_updated = NOW() '
                . 'WHERE id = :id '
                . 'LIMIT 1', array(
                    'coverImageId' => $coverId,
                    'id' => (int) $this->id,
                ));
    }

    public function isPublic($publicId = 1) {
        return (($this->isPublic) >= (int) $publicId);
    }

    public function getOwner() {
        return User::loadOneById($this->userId);
    }

    public function getTotalViews() {
        $db = Database::getDatabase();
        return (int) $db->getValue('SELECT SUM(visits) AS total FROM file WHERE folderId = ' . (int) $this->id);
    }

    public function getTotalLikes() {
        $db = Database::getDatabase();
        return (int) $db->getValue('SELECT SUM(total_likes) AS total FROM file WHERE folderId = ' . (int) $this->id);
    }

    public function totalChildFolderCount() {
        $db = Database::getDatabase();
        return (int) $db->getValue('SELECT COUNT(id) AS total FROM file_folder WHERE parentId = ' . (int) $this->id);
    }

    public function totalFileCount() {
        $db = Database::getDatabase();
        return (int) $db->getValue('SELECT COUNT(id) AS total FROM file WHERE status = "active" AND folderId ' . ($this->id == null ? 'is null' : ('= ' . (int) $this->id)) . ' AND userId = ' . (int) $this->userId);
    }

    /**
     * Method to set folder
     */
    public function updateParentFolder($parentId = NULL) {
        $db = Database::getDatabase();
        $parentId = (int) $parentId;
        $sQL = 'UPDATE file_folder SET parentId = ';
        if ($parentId == 0) {
            $sQL .= 'NULL';
        }
        else {
            $sQL .= (int) $parentId;
        }
        $sQL .= ', date_updated = NOW() WHERE id = :id';
        $db->query($sQL, array('id' => $this->id));
    }

    /**
     * Remove by user
     */
    public function trashByUser() {
        // trigger trash static method
        return FileFolderHelper::trashFolder($this->id);
    }

    /**
     * Restore folder from trash
     */
    public function restoreFromTrash($restoreFolderId = null) {
        // trigger trash static method
        return FileFolderHelper::untrashFolder($this->id, $restoreFolderId);
    }

    /**
     * Remove by user
     */
    public function removeByUser($recursive = true) {
        // trigger delete static method
        return FileFolderHelper::deleteFolder($this->id, $recursive);
    }

    /**
     * Remove by system
     */
    public function removeBySystem($recursive = true) {
        // trigger delete static method
        return FileFolderHelper::deleteFolder($this->id, $recursive);
    }
}
