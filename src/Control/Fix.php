<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\Assets\File;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\Core\Convert;
use SilverStripe\Control\Director;
use SilverStripe\Security\SecurityToken;
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
        // This changes the database and renames files, so it only runs after a POST with a valid SecurityID
        // (otherwise another website could start it for a logged-in admin).
        $confirm = $this->confirmDataChange();
        if (null !== $confirm) {
            return $confirm;
        }

        $path = $this->request->requestVar('path');
        $error = $this->request->requestVar('error');
        if (! empty($path)) {
            $path = $this->getValidatedPath($path);
            if (null === $path) {
                return $this->httpError(400, 'The path has to be a file or folder inside the assets folder.');
            }
        }
        $error = is_string($error) ? $error : '';
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

    /**
     * Returns null if the change is allowed to go ahead, or the confirmation form (html) to show.
     */
    protected function confirmDataChange(): ?string
    {
        $request = $this->getRequest();
        if ($request->isPOST() && SecurityToken::inst()->checkRequest($request)) {
            return null;
        }

        $token = SecurityToken::inst();
        $action = Director::baseURL() . ltrim($request->getURL(true), '/');
        $path = $request->getVar('path');
        $error = $request->getVar('error');
        $what = (is_string($path) && '' !== $path)
            ? 'Fix this file: ' . $path . (is_string($error) && '' !== $error ? ' (' . $error . ')' : '')
            : 'Fix ALL files (this can take a long time).';

        return '<form method="post" action="' . Convert::raw2att($action) . '" style="padding: 1rem; margin: 1rem 0; border: 2px solid #c00; background: #fff4f4;">'
            . '<input type="hidden" name="' . Convert::raw2att($token->getName()) . '" value="' . Convert::raw2att($token->getValue()) . '" />'
            . '<p><strong>' . Convert::raw2xml($what) . '</strong></p>'
            . '<p>This changes the database and renames files. Have you made a backup? Nothing has been changed yet.</p>'
            . '<button type="submit">Yes, do it now</button>'
            . '</form>';
    }

    /**
     * Returns the (absolute) path, but only if it is a file or folder that really is inside the assets folder.
     */
    protected function getValidatedPath($location): ?string
    {
        if (! is_string($location) || '' === $location || false !== strpos($location, "\0")) {
            return null;
        }

        $base = realpath(ASSETS_PATH);
        if (false === $base) {
            return null;
        }

        $location = str_replace('\\', '/', $location);
        // relative paths are relative to the assets folder.
        $candidate = 0 === strpos($location, $base . '/') ? $location : $base . '/' . ltrim($location, '/');
        $full = realpath($candidate);
        if (false === $full || 0 !== strpos($full, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $full;
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
        return null;
    }

    protected function runMethod($method)
    {
        $method = 'fix' . $method;
        if ('Error' === substr($method, 0, 5) && $this->hasMethod($method)) {
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
        return $obj->toArray();
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
        if ($file instanceof \SilverStripe\Assets\File) {
            $newFileName = $this->intel['PathFolderFromAssets'] . DIRECTORY_SEPARATOR .
                $this->intel['PathFileName'] . '.' . $this->intel['PathExtensionAsLower'];
            $file = $this->getFileObject();
            if ($file instanceof \SilverStripe\Assets\File) {
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

    protected function fixErrorParentID()
    {
    }

    protected function fixFileInDB()
    {
        $file = $this->getFileObject();
        if ($file instanceof \SilverStripe\Assets\File) {
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
