<?php

namespace Sunnysideup\AssetsOverview\Files;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

use SilverStripe\ORM\DB;

use Sunnysideup\AssetsOverview\Interfaces\FileInfo;
use Sunnysideup\AssetsOverview\Traits\Cacher;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;
use Sunnysideup\Flush\FlushNow;

class AllFilesInfo implements FileInfo
{
    use FilesystemRelatedTraits;
    use Injectable;
    use Configurable;
    use Cacher;
    use FlushNow;

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
        DIRECTORY_SEPARATOR . '_resampled',
        DIRECTORY_SEPARATOR . '__',
        DIRECTORY_SEPARATOR . '.',
        // '__Fit',
        // '__Pad',
        // '__Fill',
        // '__Focus',
        // '__Scale',
        // '__ResizedImage',
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
     */
    public static function existsOnStaging(int $id): bool
    {
        return isset(self::$dataStaging[$id]);
    }

    /**
     * does the file exists in the database on live?
     */
    public static function existsOnLive(int $id): bool
    {
        return isset(self::$dataLive[$id]);
    }

    /**
     * get data from staging database row
     * @param  int $pathFromAssets id
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
     * @param  mixed     $value
     */
    public static function findInStagingData(string $fieldName, $value): int
    {
        return self::findInData(self::$dataStaging, $fieldName, $value);
    }

    /**
     * find a value in a field in live
     * returns ID of row
     * @param  mixed     $value
     */
    public static function findInLiveData(string $fieldName, $value): int
    {
        return self::findInData(self::$dataLive, $fieldName, $value);
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
                $this->flushNow('<h1>Analysing files</h1>');
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
                if ($inFileSystem) {
                    $this->flushNow('. ', '', false);
                } else {
                    $this->flushNow('x ', '', false);
                }
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
     * @param  mixed  $value
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
        $listOfItemsToSearchFor = Config::inst()->get(self::class, 'not_real_file_substrings');
        foreach ($listOfItemsToSearchFor as $test) {
            if (strpos($path, $test)) {
                return false;
            }
        }
        $fileName = basename($path);
        return ! (substr($fileName, 0, 5) === 'error' && substr($fileName, -5) === '.html');
    }

    protected function getArrayOfFilesOnDisk(): array
    {
        $finalArray = [];
        $arrayRaw = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($arrayRaw as $src) {
            $path = $src->getPathName();
            if ($this->isRealFile($path) === false) {
                continue;
            }
            $finalArray[$path] = $path;
        }

        return $finalArray;
    }

    protected function getArrayOfFilesInDatabase(): array
    {
        $finalArray = [];
        foreach (['', '_Live'] as $stage) {
            $sql = 'SELECT * FROM "File' . $stage . '" WHERE "ClassName" <> \'' . addslashes(Folder::class) . "';";
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

    protected function getCacheKey(): string
    {
        return 'allfiles';
    }
}
