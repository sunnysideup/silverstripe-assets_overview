<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use SilverStripe\Versioned\Versioned;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;
use Sunnysideup\AssetsOverview\Files\OneFileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;
use Sunnysideup\AssetsOverview\Traits\Cacher;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;


class Fix extends ContentController
{
    use FilesystemRelatedTraits;

    private static $allowed_actions = [
        'fix' => 'ADMIN',
    ];

    protected $intel = [];

    private const ALL_FILES_INFO_CLASS = AllFilesInfo::class;

    private const ONE_FILE_INFO_CLASS = OneFileInfo::class;

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
                        $method = 'fix'.$key;
                        if ($value === true) {
                            $this->runMethod($key);
                        }
                    }
                }
            }
        }
    }

    protected function runMethod($method)
    {
        $method = 'fix'.$method;
        if (substr($method, 0, 5) === 'Error' && $this->hasMethod($method)) {
            $this->$method();
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

        return $this->getUncachedIntel($absoluteLocation);
    }

    protected function fixErrorDBNotPresent()
    {
        $className = File::get_class_for_file_extension();
        $obj = $className::create()->setFromLocalFile($this->path);
        $obj->writeToStage(Versioned::DRAFT);
        $obj->publishRecursive();
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
            $newFileName = $this->intel['PathFolderFromAssets'] .DIRECTORY_SEPARATOR .
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
        DB::query('UPDATE "File" SET "Filename" = "FileFileName" WHERE ID ='.$this->intel['DBID']);
        DB::query('UPDATE "File_Live" SET "Filename" = "FileFileName" WHERE ID ='.$this->intel['DBID']);

        return true;
    }

    protected function fixErrorParentID()
    {

    }


    protected function fixFileInDB(){
        $file = $this->getFileObject();
        if($file) {
            $this->updateFilesystem();
        } else {
            user_error('Can not find file ID');
        }
    }

    protected function getFileObject() : ?File
    {
        return File::get()->byID($this->intel['DBID']);
    }
}
