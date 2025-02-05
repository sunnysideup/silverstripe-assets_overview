<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\Assets\File;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;
use Sunnysideup\AssetsOverview\Files\OneFileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;

/**
 * Class \Sunnysideup\AssetsOverview\Control\Fix
 *
 * @property \Sunnysideup\AssetsOverview\Control\Fix $dataRecord
 * @method \Sunnysideup\AssetsOverview\Control\Fix data()
 * @mixin \Sunnysideup\AssetsOverview\Control\Fix
 */
class Fix extends ContentController
{
    use FilesystemRelatedTraits;

    protected $intel = [];

    private static $allowed_actions = [
        'fix' => 'ADMIN',
    ];

    public function fix($request)
    {
        $path = $this->request->getVar('path');
        $error = $this->request->getVar('error');
        $paths = empty($path) ? $this->getRawData() : [$path];
        foreach ($paths as $path) {
            if ($path) {
                $this->intel = $this->getDataAboutOneFile($path);
                if ($error) {
                    $this->runMethod($error);
                } else {
                    foreach ($this->intel as $key => $value) {
                        if (true === $value) {
                            $method = 'fix' . $key;
                            $this->runMethod($method);
                        }
                    }
                }
            }
        }
    }

    protected function init()
    {
        parent::init();
        if (! Permission::check('ADMIN')) {
            return Security::permissionFailure($this);
        }

        Requirements::clear();
        ini_set('memory_limit', '1024M');
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo(7200);
        SSViewer::config()->merge('theme_enabled', false);
        Versioned::set_stage(Versioned::DRAFT);
    }

    protected function runMethod($method)
    {
        $method = 'fix' . $method;
        if ('Error' === substr((string) $method, 0, 5) && $this->hasMethod($method)) {
            $this->{$method}();
        }
    }

    protected function getRawData(): array
    {
        //get data
        return Injector::inst()->get(AllFilesInfo::class)
            ->toArray();
    }

    protected function getDataAboutOneFile(string $absoluteLocation): array
    {
        $obj = OneFileInfo::inst($absoluteLocation);
        $obj->setNoCache(true);
        return $obj->toArray(true);
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

    protected function fixErrorDBNotPresentLive() {}

    protected function fixErrorDBNotPresentStaging() {}

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
        foreach (['', '_Live'] as $stage) {
            DB::query(
                '
                UPDATE "File"' . $stage . '
                SET "Filename" = "FileFileName"
                WHERE ID =' . $this->intel['DBID'] . ' AND "Filename" <> "FileFileName" AND "FileFileName" IS NOT NULL AND "FileFileName" <> \'\''
            );
            DB::query(
                '
                UPDATE "File"' . $stage . '
                SET "FileFileName" = "Filename"
                WHERE ID =' . $this->intel['DBID'] . ' AND "Filename" <> "FileFileName" AND "Filename" IS NOT NULL AND "Filename" <> \'\''
            );
        }

        return true;
    }

    protected function fixErrorParentID() {}

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
        return File::get_by_id($this->intel['DBID']);
    }
}
