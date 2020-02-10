<?php


namespace Sunnysideup\AssetsOverview\Files;

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
use Sunnysideup\AssetsOverview\Interfaces\FileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;

class AllFilesInfo implements Flushable, FileInfo
{
    use FilesystemRelatedTraits;


    public static function flush()
    {
        $cache = self::getCache();
        $cache->clear();
    }

    /**
     * @var string
     */
    protected $path = [];

    /**
     * @var array
     */
    protected $availableExtensions = [];

    /**
     * @var array
     */
    protected $listOfFiles = [];

    /**
     * @var array
     */
    private static $allowed_extensions = [];

    private static $not_real_file_substrings = [
        '__FitMax',
        '_resampled',
        '__Fill',
        '__Focus',
        '__Scale',
    ];

    public function __construct($path, ?array $allowedExtensions = [])
    {
        $this->path = '';
        $this->allowedExtensions = $allowedExtensions ?? $this->Config()->get('allowed_extensions');
    }


    public function toArray() : array
    {
        $cache = self::getCache();
        $cachekey = $this->getCacheKey();
        if (! $cache->has($cachekey)) {
            //disk
            $diskArray = $this->getArrayOfFilesOnDisk();
            foreach($diskArray as $path) {
                if ($this->isPathWithAllowedExtension($path)) {
                    $this->listOfFiles[$absoluteLocation] = true;
                }
            }
            //database
            $databaseArray = $this->getArrayOfFilesInDatabase();
            foreach($databaseArray as $path)
            if (! isset($this->listOfFiles[$path])) {
                if ($this->isPathWithAllowedExtension($path)) {
                    $this->listOfFiles[$absoluteLocation] = false;
                }
            }
            $fullArrayString = serialize($this->listOfFiles);
            $cache->set($cachekey, $fullArrayString);
        } else {
            $fullArrayString = $cache->get($cachekey);
            $this->listOfFiles = unserialize($fullArrayString);
        }

        return $this->listOfFiles;
    }

    protected function isRealFile(string $path): bool
    {
        $fileName = basename($path);
        $listOfItemsToSearchFor = $this->Config()->get('not_real_file_substrings');
        if (substr($fileName, 0, 1) === '.') {
            return false;
        }
        foreach ($listOfItemsToSearchFor as $test) {
            if (strpos($fileName, $test)) {
                return false;
            }
        }

        return true;
    }



    /**
     * @param  string $path - does not have to be full path.
     *
     * @return bool
     */
    protected function isPathWithAllowedExtension(string $path): bool
    {
        $count = count($this->allowedExtensions);
        if ($count === 0) {
            return true;
        }
        $extension = strtolower($this->getExtension($path));
        if (in_array($extension, $this->allowedExtensions, true)) {
            return true;
        }
        return false;
    }

    /**
     * @return array
     */
    protected function getArrayOfFilesOnDisk(): array
    {
        $finalArray = [];
        $arrayRaw = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($arrayRaw as $src) {
            if (is_dir($src)) {
                continue;
            }
            if (strpos($src, '.protected')) {
                continue;
            }
            if ($this->isRealFile($src) === false) {
                continue;
            }
            $path = $src->getPathName();
            if ($this->isPathWithAllowedExtension($path)) {
                $finalArray[$path] = $path;
            }
        }


        return $finalArray;
    }

    /**
     * @return array
     */
    protected function getArrayOfFilesInDatabase(): array
    {
        $finalArray = [];
        $rawArray = File::get()
            ->exclude(['ClassName' => Folder::class])
            ->column('FileFilename');
        foreach ($rawArray as $relativeSrc) {
            $absoluteLocation = $this->path . DIRECTORY_SEPARATOR . $relativeSrc;
            $finalArray[$absoluteLocation] = $absoluteLocation;
        };

        return $finalArray;
    }

    ##############################################
    # CACHE
    ##############################################

    /**
     * @return CacheInterface
     */
    protected static function getCache()
    {
        return Injector::inst()->get(CacheInterface::class . '.assetsoverviewCache');
    }

    /**
     *
     * @return string
     */
    protected function getCacheKey(): string
    {
        return 'allfiles';
    }
}
