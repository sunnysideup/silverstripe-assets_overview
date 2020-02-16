<?php

namespace Sunnysideup\AssetsOverview\Files;

use \FilesystemIterator;
use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

use SilverStripe\ORM\DB;
use Sunnysideup\AssetsOverview\Interfaces\FileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;
use Sunnysideup\AssetsOverview\Control\View;

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
    protected static $dataStaging = [];

    /**
     * @var array
     */
    protected static $dataLive = [];

    /**
     * @var array
     */
    protected static $listOfFiles = [];

    /**
     * @var array
     */
    protected static $databaseLookupListStaging = [];

    /**
     * @var array
     */
    protected static $availableExtensions = [];

    /**
     * @var array
     */
    protected static $databaseLookupListLive = [];

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

    public static function getTotalFilesCount(): int
    {
        return (int) count(self::$listOfFiles);
    }

    public static function getAvailableExtensions(): array
    {
        return self::$availableExtensions ?? [];
    }

    /**
     * does the file exists in the database on staging?
     * @param  int $id
     * @return bool
     */
    public static function existsOnStaging(int $id): bool
    {
        return isset(self::$dataStaging[$id]);
    }

    /**
     * does the file exists in the database on live?
     * @param  int $id
     * @return bool
     */
    public static function existsOnLive(int $id): bool
    {
        return isset(self::$dataLive[$id]);
    }

    /**
     * get data from staging database row
     * @param  string $path from the root of assets
     * @param  int $pathFromAssets id
     * @return array
     */
    public static function getAnyData(string $pathFromAssets, ?int $id = 0): array
    {
        $data = self::getStagingData($pathFromAssets, $id);
        if (empty($data)) {
            $data = self::getLiveData($pathFromAssets, $id);
        }

        return $data;
    }

    /**
     * get data from staging database row
     * @param  string $pathFromAssets from the root of assets
     * @param  int $id
     * @return array
     */
    public static function getStagingData(string $pathFromAssets, ?int $id = 0): array
    {
        if (! $id) {
            $id = self::$databaseLookupListStaging[$pathFromAssets] ?? 0;
        }
        return self::$dataStaging[$id] ?? [];
    }

    /**
     * get data from live database row
     * @param  string $pathFromAssets - full lookup list
     * @return array
     */
    public static function getLiveData(string $pathFromAssets, ?int $id = 0): array
    {
        if (! $id) {
            $id = self::$databaseLookupListLive[$pathFromAssets] ?? 0;
        }
        return self::$dataLive[$id] ?? [];
    }

    /**
     * find a value in a field in staging
     * returns ID of row
     * @param  string    $fieldName
     * @param  mixed     $value
     * @return int
     */
    public static function findInStagingData(string $fieldName, $value): int
    {
        return self::findInData(self::$dataStaging, $fieldName, $value);
    }

    /**
     * find a value in a field in live
     * returns ID of row
     * @param  string    $fieldName
     * @param  mixed     $value
     * @return int
     */
    public static function findInLiveData(string $fieldName, $value): int
    {
        return self::findInData(self::$dataLive, $fieldName, $value);
    }

    public static function flush()
    {
        $cache = self::getCache();
        $cache->clear();
    }

    public static function getTotalFileSizesRaw()
    {
        $bytestotal = 0;
        $path = realpath(ASSETS_PATH);
        if ($path !== false && $path !== '' && file_exists($path)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object) {
                $bytestotal += $object->getSize();
            }
        }
        return $bytestotal;
    }

    public function toArray(): array
    {
        if (count(self::$listOfFiles) === 0) {
            $cachekey = $this->getCacheKey();
            if (! $this->hasCacheKey($cachekey)) {
                //disk
                $diskArray = $this->getArrayOfFilesOnDisk();
                foreach ($diskArray as $path) {
                    $this->registerFile($path, true);
                }
                //database
                $databaseArray = $this->getArrayOfFilesInDatabase();
                foreach ($databaseArray as $path) {
                    $this->registerFile($path, false);
                }
                asort(self::$listOfFiles);
                asort(self::$availableExtensions);
                $this->setCacheValue($cachekey, self::$listOfFiles);
                $this->setCacheValue($cachekey . 'availableExtensions', self::$availableExtensions);
                $this->setCacheValue($cachekey . 'dataStaging', self::$dataStaging);
                $this->setCacheValue($cachekey . 'dataLive', self::$dataLive);
                $this->setCacheValue($cachekey . 'databaseLookupStaging', self::$databaseLookupListStaging);
                $this->setCacheValue($cachekey . 'databaseLookupLive', self::$databaseLookupListLive);
            } else {
                self::$listOfFiles = $this->getCacheValue($cachekey);
                self::$availableExtensions = $this->getCacheValue($cachekey . 'availableExtensions');
                self::$dataStaging = $this->getCacheValue($cachekey . 'dataStaging');
                self::$dataLive = $this->getCacheValue($cachekey . 'dataLive');
                self::$databaseLookupListStaging = $this->getCacheValue($cachekey . 'databaseLookupStaging');
                self::$databaseLookupListLive = $this->getCacheValue($cachekey . 'databaseLookupLive');
            }
        }

        return self::$listOfFiles;
    }

    protected function registerFile($path, $inFileSystem)
    {
        if ($path) {
            if (! isset(self::$listOfFiles[$path])) {
                self::$listOfFiles[$path] = $inFileSystem;
                echo '<li>'.$path.'</li>';
                $extension = strtolower($this->getExtension($path));
                self::$availableExtensions[$extension] = $extension;
            }
        }
    }

    protected static function findIdFromFileName(array $data, string $filename): int
    {
        $id = self::findInData($data, 'FileFilename', $filename);
        if (! $id) {
            $id = self::findInData($data, 'Filename', $filename);
        }

        return $id;
    }

    /**
     * @param  array  $data
     * @param  string $fieldName
     * @param  mixed  $value
     * @return int
     */
    protected static function findInData(array $data, string $fieldName, $value): int
    {
        foreach ($data as $id => $row) {
            if (isset($row[$fieldName])) {
                if ($row[$fieldName] === $value) {
                    return (int) $id;
                }
            }
        }
        return 0;
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
        foreach (['', '_Live'] as $stage) {
            $sql = 'SELECT * FROM "File' . $stage . '" WHERE "ClassName" <> \'' . addslashes(Folder::class) . '\';';
            $rows = DB::query($sql);
            foreach ($rows as $row) {
                $file = $row['FileFilename'] ?? $row['Filename'];
                if (trim($file)) {
                    $absoluteLocation = $this->path . DIRECTORY_SEPARATOR . $file;
                    if ($stage === '') {
                        self::$dataStaging[$row['ID']] = $row;
                        self::$databaseLookupListStaging[$file] = $row['ID'];
                    } elseif ($stage === '_Live') {
                        self::$dataLive[$row['ID']] = $row;
                        self::$databaseLookupListLive[$file] = $row['ID'];
                    } else {
                        user_error('Can not find stage');
                    }
                    $finalArray[$absoluteLocation] = $absoluteLocation;
                }
            }
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
