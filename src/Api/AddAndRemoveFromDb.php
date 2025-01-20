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

    protected $dryRun = true;

    public function __construct(?bool $dryRun = true)
    {
        $this->dryRun = $dryRun;
    }

    public function setIsDryRun(bool $b): static
    {
        $this->dryRun = $b;
        return $this;
    }



    public function run(array $oneFileInfoArray, ?string $mode = null)
    {
        if ($mode !== 'add' && $mode !== 'remove' && $mode !== null) {
            user_error('Mode must be either "add" or "remove" or not set at all', E_USER_ERROR);
        }
        $pathFromAssetsFolder = $oneFileInfoArray['Path'];
        $absolutePath = $oneFileInfoArray['AbsolutePath'];

        // Usage
        if ($oneFileInfoArray['IsDir']) {
            $this->logMessage('Skipping [FOLDER]', $pathFromAssetsFolder);
        } elseif (isset($oneFileInfoArray['IsResizedImage']) && $oneFileInfoArray['IsResizedImage'] === true) {
            if (file_exists($absolutePath)) {
                $this->logMessage('Deleting', $pathFromAssetsFolder, 'deleted');
                if ($this->dryRun === false) {
                    unlink($absolutePath);
                }
            }
            $this->logMessage('Skipping [RESIZED IMAGE]', $pathFromAssetsFolder);
        } elseif ($oneFileInfoArray['ErrorDBNotPresent'] === true && $mode !== 'remove') {
            print_r($oneFileInfoArray);

            $this->logMessage('+++ Adding to DB', $pathFromAssetsFolder, 'created');
            if ($this->dryRun === false) {
                $this->addFileToDb($oneFileInfoArray);
            }
        } elseif ($oneFileInfoArray['ErrorIsInFileSystem'] === true && $mode !== 'add') {
            print_r($oneFileInfoArray);

            $this->logMessage('--- Removing from DB', $pathFromAssetsFolder, 'deleted');
            if ($this->dryRun === false) {
                $this->removeFileFromDb($oneFileInfoArray);
            }
        } else {
            $this->logMessage('Skipping [ALL OK]', $pathFromAssetsFolder);
        }
    }

    private function logMessage(string $action, string $path, string $type = ''): void
    {

        $action = strtoupper($action);
        $reasonPadding = 15; // Adjust padding as needed for alignment
        $formattedAction = str_pad($action, $reasonPadding);
        $path .= ($this->dryRun ? ' (DRY RUN)' : ' (FOR REAL)');
        DB::alteration_message($formattedAction . ': ' . $path, $type);
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
        $absolutePath = $oneFileInfoArray['AbsolutePath'];
        $pathFromAssetsFolder = $oneFileInfoArray['Path'];
        $extension = File::get_file_extension($absolutePath);
        $newClass = File::get_class_for_file_extension($extension);
        $newFile = Injector::inst()->create($newClass);
        if (file_exists($absolutePath)) {
            $newFile->setFromLocalFile($absolutePath, $pathFromAssetsFolder);
            try {
                $newFile->write();
            } catch (\Exception $e) {
                DB::alteration_message('Could not write file ' . $pathFromAssetsFolder . ' because ' . $e->getMessage(), 'deleted');
                return;
            }
        } else {
            return;
        }

        // If file is an image, generate thumbnails
        if (is_a($newFile, Image::class)) {
            $admin = AssetAdmin::create();
            $admin->generateThumbnails($newFile, true);
        }

        if ($this->Config()->publish_recursive) {
            $newFile->publishRecursive();
        }
        $newFile->publishSingle();
    }
}
