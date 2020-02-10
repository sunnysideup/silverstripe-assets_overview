<?php

namespace Sunnysideup\AssetsOverview\Files;

use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;

use SilverStripe\Core\Injector\Injector;
use Sunnysideup\AssetsOverview\Interfaces\FileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;

class AllFilesInfo implements Flushable, FileInfo
{
    use FilesystemRelatedTraits;
    use Injectable;
    use Configurable;

    /**
     * @var string
     */
    protected $path = '';

    /**
     * @var array
     */
    protected $listOfFiles = [];

    private static $not_real_file_substrings = [
        '_resampled',
        '__Fit',
        '__Pad',
        '__Fill',
        '__Focus',
        '__Scale',
        '__ResizedImage',
    ];

    public function __construct($path)
    {
        $this->path = $path;
    }

    public static function flush()
    {
        $cache = self::getCache();
        $cache->clear();
    }

    public function toArray(): array
    {
        $cache = self::getCache();
        $cachekey = $this->getCacheKey();
        if (! $cache->has($cachekey)) {
            //disk
            $diskArray = $this->getArrayOfFilesOnDisk();
            foreach ($diskArray as $path) {
                $this->listOfFiles[$path] = true;
            }
            //database
            $databaseArray = $this->getArrayOfFilesInDatabase();
            foreach ($databaseArray as $path) {
                if (! isset($this->listOfFiles[$path])) {
                    $this->listOfFiles[$path] = false;
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
        $listOfItemsToSearchFor = Config::inst()->get(self::class, 'not_real_file_substrings');
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
            $path = $src->getPathName();
            if (is_dir($path)) {
                continue;
            }
            if (strpos($path, '.protected')) {
                continue;
            }
            if ($this->isRealFile($path) === false) {
                continue;
            }
            $finalArray[$path] = $path;
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
        }

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
     * @return string
     */
    protected function getCacheKey(): string
    {
        return 'allfiles';
    }
}
