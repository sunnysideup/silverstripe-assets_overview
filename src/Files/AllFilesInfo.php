<?php

namespace Sunnysideup\AssetsOverview\Files;

use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use \FilesystemIterator;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;

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
    protected static $dataStaging = [];

    /**
     *
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

    public static function getTotalFilesCount() : int
    {
        return (int) count(self::$listOfFiles);
    }

    public static function getAvailableExtensions() : array
    {
        return self::$availableExtensions ?? [];
    }

    /**
     * does the file exists in the database on staging?
     * @param  int $id
     * @return bool
     */
    public static function exists_on_staging(int $id) : bool
    {
        return isset(self::$dataStaging[$id]);
    }

    /**
     * does the file exists in the database on live?
     * @param  int $id
     * @return bool
     */
    public static function exists_on_live(int $id) : bool
    {
        return isset(self::$dataLive[$id]);
    }


    /**
     * get data from staging database row
     * @param  string $path from the root of assets
     * @param  int id
     * @return array
     */
    public static function get_any_data(string $pathFromAssets, ?int $id = 0) : array
    {
        $data = self::get_staging_data($pathFromAssets, $id);
        if(empty($data)) {
            $data = self::get_live_data($pathFromAssets, $id);
        }

        return $data;
    }

    /**
     * get data from staging database row
     * @param  string $pathFromAssets from the root of assets
     * @param  int id
     * @return array
     */
    public static function get_staging_data(string $pathFromAssets, ?int $id = 0) : array
    {
        if(! $id) {
            $id = self::$databaseLookupListStaging[$pathFromAssets] ?? 0;
        }
        return self::$dataStaging[$id] ?? [];
    }

    /**
     * get data from live database row
     * @param  string $pathFromAssets - full lookup list
     * @return array
     */
    public static function get_live_data(string $pathFromAssets, ?int $id = 0) : array
    {
        if(! $id) {
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
    public static function find_in_data_staging(string $fieldName, $value) : int
    {
        return self::find_in_data(self::$dataStaging, $fieldName, $value);
    }

    /**
     * find a value in a field in live
     * returns ID of row
     * @param  string    $fieldName
     * @param  mixed     $value
     * @return int
     */
    public static function find_in_data_live(string $fieldName, $value) : int
    {
        return self::find_in_data(self::$dataLive, $fieldName, $value);
    }

    protected static function find_id_from_file_name(array $data, string $filename) : int
    {

        $id = self::find_in_data($data, 'FileFilename', $filename);
        if(! $id) {
            $id = self::find_in_data($data, 'Filename', $filename);
        }

        return $id;
    }

    /**
     *
     * @param  array  $data
     * @param  string $fieldName
     * @param  mixed  $value
     * @return int
     */
    protected static function find_in_data(array $data, string $fieldName, $value) : int
    {
        foreach($data as $id => $row) {
            if(isset($row[$fieldName])) {
                if($row[$fieldName] === $value) {
                    return (int) $id;
                }
            }
        }
        return 0;
    }

    public function __construct($path)
    {
        $this->path = $path;
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
        if($path!==false && $path!='' && file_exists($path)){
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object){
                $bytestotal += $object->getSize();
            }
        }
        return $bytestotal;
    }

    public function toArray(): array
    {
        $cache = self::getCache();
        $cachekey = $this->getCacheKey();
        if (count(self::$listOfFiles) === 0) {
            if (! $cache->has($cachekey)) {
                //disk
                $diskArray = $this->getArrayOfFilesOnDisk();
                foreach ($diskArray as $path) {
                    if($path) {
                        self::$listOfFiles[$path] = true;
                        $extension = strtolower($this->getExtension($path));
                        self::$availableExtensions[$extension] = $extension;
                    }
                }
                //database
                $databaseArray = $this->getArrayOfFilesInDatabase();
                foreach ($databaseArray as $path) {
                    if($path) {
                        if (! isset(self::$listOfFiles[$path])) {
                            self::$listOfFiles[$path] = false;
                            $extension = strtolower($this->getExtension($path));
                            self::$availableExtensions[$extension] = $extension;
                        }
                    }
                }
                asort(self::$listOfFiles);
                asort(self::$availableExtensions);
                $cache->set($cachekey, serialize(self::$listOfFiles));
                $cache->set($cachekey . 'availableExtensions', serialize(self::$availableExtensions));
                $cache->set($cachekey . 'dataStaging', serialize(self::$dataStaging));
                $cache->set($cachekey . 'dataLive', serialize(self::$dataLive));
                $cache->set($cachekey . 'databaseLookupStaging', serialize(self::$databaseLookupListStaging));
                $cache->set($cachekey . 'databaseLookupLive', serialize(self::$databaseLookupListLive));
            } else {
                self::$listOfFiles = unserialize($cache->get($cachekey));
                self::$availableExtensions = unserialize($cache->get($cachekey . 'availableExtensions'));
                self::$dataStaging = unserialize($cache->get($cachekey . 'dataStaging'));
                self::$dataLive = unserialize($cache->get($cachekey . 'dataLive'));
                self::$databaseLookupListStaging = unserialize($cache->get($cachekey . 'databaseLookupStaging'));
                self::$databaseLookupListLive = unserialize($cache->get($cachekey . 'databaseLookupLive'));
            }
        }

        return self::$listOfFiles;
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
        foreach(['', '_Live'] as $stage) {
            $sql = 'SELECT * FROM "File'.$stage.'" WHERE "ClassName" <> \''.addslashes(Folder::class).'\';';
            $rows = DB::query($sql);
            foreach($rows as $row) {
                $file = $row['FileFilename'] ?? $row['Filename'] ;
                if(trim($file)) {
                    $absoluteLocation = $this->path . DIRECTORY_SEPARATOR . $file;
                    if ($stage === '') {
                        self::$dataStaging[$row['ID']] = $row;
                        self::$databaseLookupListStaging[$file] = $row['ID'];
                    } elseif($stage === '_Live') {
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
