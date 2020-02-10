<?php

namespace Sunnysideup\AssetsOverview\Traits;

use \Exception;
use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Director;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;
use Sunnysideup\AssetsOverview\Api\CompareImages;

trait FilesystemRelatedTraits
{

    /**
     * @var string
     */
    protected $baseFolder = '';

    /**
     * @var string
     */
    protected $assetsBaseFolder = '';

    /**
     *
     * @param  int     $bytes    [description]
     * @param  integer $decimals [description]
     * @return string            [description]
     */
    protected function humanFileSize(int $bytes, int $decimals = 0): string
    {
        $size = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }


    protected function getExtension(string $path): string
    {
        $basename = basename($path);

        return substr($basename, strlen(explode('.', $basename)[0]) + 1);
    }

    /**
     * @return string
     */
    protected function getBaseFolder(): string
    {
        if(! $this->baseFolder) {
            $this->baseFolder = rtrim(Director::baseFolder(), DIRECTORY_SEPARATOR);
        }
        return $this->baseFolder;
    }

    /**
     * @return string
     */
    protected function getAssetBaseFolder(): string
    {
        if(! $this->assetsBaseFolder) {
            $this->assetsBaseFolder = rtrim(ASSETS_PATH, DIRECTORY_SEPARATOR);
        }
        return $this->assetsBaseFolder;
    }

}
