<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\Assets\File;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;
use Sunnysideup\AssetsOverview\Files\OneFileInfo;

use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;

class Fix extends ContentController
{
    use FilesystemRelatedTraits;

    private const ALL_FILES_INFO_CLASS = AllFilesInfo::class;

    private const ONE_FILE_INFO_CLASS = OneFileInfo::class;

    protected $intel = [];

    private static $allowed_actions = [
        'fix' => 'ADMIN',
    ];

    public function init()
    {
        parent::init();
        if (! Permission::check('ADMIN')) {
            return Security::permissionFailure($this);
        }
        Requirements::clear();
        ini_set('memory_limit', '1024M');
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo(7200);
        SSViewer::config()->update('theme_enabled', false);
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function fix($request)
    {
        $path = $this->request->getVar('path');
        $error = $this->request->getVar('error');
        if (empty($path)) {
            $paths = $this->getRawData();
        } else {
            $paths = [$path];
        }
        foreach ($paths as $path) {
            if ($path) {
                $this->intel = $this->getDataAboutOneFile($path);
                if ($error) {
                    $this->runMethod($error);
                } else {
                    foreach ($this->intel as $key => $value) {
                        if ($value === true) {
                            $method = 'fix' . $key;
                            $this->runMethod($method);
                        }
                    }
                }
            }
        }
    }

    protected function runMethod($method)
    {
        $method = 'fix' . $method;
        if (substr($method, 0, 5) === 'Error' && $this->hasMethod($method)) {
            $this->{$method}();
        }
    }

    protected function getRawData(): array
    {
        //get data
        $class = self::ALL_FILES_INFO_CLASS;
        $obj = new $class($this->getAssetsBaseFolder());

        return $obj->toArray();
    }

    protected function getDataAboutOneFile(string $absoluteLocation): array
    {
        $class = self::ONE_FILE_INFO_CLASS;
        $obj = new $class($absoluteLocation);
        print_r($obj);

        return $this->getUncachedIntel($absoluteLocation);
    }

    protected function fixErrorDBNotPresent()
    {
        $pathArray = pathinfo($this->path);
        $ext = $pathArray['extension'];
        $className = File::get_class_for_file_extension($ext);
        if (class_exists($className)) {
            $obj = $className::create()->setFromLocalFile($this->path);
            $obj->writeToStage(Versioned::DRAFT);
            $obj->publishRecursive();
        }
    }

    protected function fixErrorDBNotPresentLive()
    {
    }

    protected function fixErrorDBNotPresentStaging()
    {
    }

    protected function fixErrorExtensionMisMatch()
    {
        $file = $this->getFileObject();
        if ($file) {
            $newFileName = $this->intel['PathFolderFromAssets'] . DIRECTORY_SEPARATOR .
                $this->intel['PathFileName'] . '.' . $this->intel['PathExtensionAsLower'];
            $file = $this->getFileObject();
            if ($file) {
                $file->renameFile($newFileName);
            }
        }
    }

    protected function fixErrorFindingFolder()
    {
        $this->fixFileInDB();
    }

    protected function fixErrorInFilename()
    {
        $this->fixFileInDB();
    }

    protected function fixErrorInSs3Ss4Comparison()
    {
        DB::query('UPDATE "File" SET "Filename" = "FileFileName" WHERE ID =' . $this->intel['DBID']);
        DB::query('UPDATE "File_Live" SET "Filename" = "FileFileName" WHERE ID =' . $this->intel['DBID']);

        return true;
    }

    protected function fixErrorParentID()
    {
    }

    protected function fixFileInDB()
    {
        $file = $this->getFileObject();
        if ($file) {
            $this->updateFilesystem();
        } else {
            user_error('Can not find file ID');
        }
    }

    protected function getFileObject(): ?File
    {
        return File::get()->byID($this->intel['DBID']);
    }
}
