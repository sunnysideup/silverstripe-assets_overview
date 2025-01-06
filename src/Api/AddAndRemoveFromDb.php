<?php

namespace Sunnysideup\AssetsOverview\Api;

use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class AddAndRemoveFromDb
{
    use Injectable;
    use Configurable;

    private static $publish_recursive = true;

    public function run(array $oneFileInfoArray, ?string $mode = null)
    {
        if ($mode !== 'add' && $mode !== 'remove' && $mode !== null) {
            user_error('Mode must be either "add" or "remove" or not set at all', E_USER_ERROR);
        }
        $pathFromAssetsFolder = $oneFileInfoArray['PathFromAssetsFolder'];
        $localPath = $oneFileInfoArray['Path'];
        if ($oneFileInfoArray['IsDir']) {
            DB::alteration_message('Skipping ' . $pathFromAssetsFolder . ' as this is a folder', '');
        } elseif (! empty($oneFileInfoArray['IsResizedImage'])) {
            if (file_exists($localPath)) {
                DB::alteration_message('Deleting ' . $pathFromAssetsFolder, 'deleted');
                //unlink($localPath);
            }
        } elseif ($oneFileInfoArray['ErrorDBNotPresent'] && $mode !== 'remove') {
            DB::alteration_message('Adding file to database ' . $pathFromAssetsFolder, 'created');
            $this->addFileToDb($oneFileInfoArray);
        } elseif ($oneFileInfoArray['ErrorIsInFileSystem'] && $mode !== 'add') {
            DB::alteration_message('Removing from database ' . $pathFromAssetsFolder, 'deleted');
            $this->removeFileFromDb($oneFileInfoArray);
        }
    }

    public function removeFileFromDb(array $oneFileInfoArray)
    {
        $file = File::get()->byID($oneFileInfoArray['DBID']);
        if ($file) {
            $file->DeleteFromStage(Versioned::LIVE);
            $file->DeleteFromStage(Versioned::DRAFT);
        }
    }

    public function addFileToDb(array $oneFileInfoArray)
    {
        $localPath = $oneFileInfoArray['Path'];
        $pathFromAssetsFolder = $oneFileInfoArray['PathFromAssetsFolder'];
        $extension = File::get_file_extension($localPath);
        $newClass = File::get_class_for_file_extension($extension);
        $newFile = Injector::inst()->create($newClass);
        $newFile->setFromLocalFile($localPath, $pathFromAssetsFolder);
        $newFile->write();

        // If file is an image, generate thumbnails
        if (is_a($newFile, Image::class)) {
            $admin = AssetAdmin::create();
            $admin->generateThumbnails($newFile, true);
        }

        if ($this->Config()->publish_recursive) {
            $newFile->publishRecursive();
        }
    }
}
