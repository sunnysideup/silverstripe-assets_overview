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

    private static $not_real_file_substrings = [
        '_resampled',
        '__Fit',
        '__Pad',
        '__Fill',
        '__Focus',
        '__Scale',
        '__ResizedImage',
    ];

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
     * @param  string $fileName from the root of assets
     * @return array
     */
    public static function get_staging_data(string $fileName) : array
    {
        $id = self::find_id_from_file_name(self::$dataLive, $filename);
        if($id) {
            return self::$dataStaging($id);
        }
    }

    /**
     * get data from live database row
     * @param  string $fileName from the root of assets
     * @return array
     */
    public static function get_live_data(string $fileName) : array
    {
        $id = self::find_id_from_file_name(self::$dataLive, $filename);
        if($id) {
            return self::$dataStaging($id);
        }
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
        foreach($data as $row) {
            if($row[$fieldName] === $value) {
                return (int) $id;
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

    public function toArray(): array
    {
        $cache = self::getCache();
        $cachekey = $this->getCacheKey();
        if (! $cache->has($cachekey)) {
            //disk
            $diskArray = $this->getArrayOfFilesOnDisk();
            foreach ($diskArray as $path) {
                self::$listOfFiles[$path] = true;
            }
            //database
            $databaseArray = $this->getArrayOfFilesInDatabase();
            foreach ($databaseArray as $path) {
                if (! isset(self::$listOfFiles[$path])) {
                    self::$listOfFiles[$path] = false;
                }
            }
            $cache->set($cachekey, serialize(self::$listOfFiles));
            $cache->set($cachekey . 'dataStaging', serialize(self::$dataStaging));
            $cache->set($cachekey . 'dataLive', serialize(self::$dataLive));
        } else {
            self::$listOfFiles = unserialize($cache->get($cachekey));
            self::$dataStaging = unserialize($cache->get($cachekey . 'dataStaging'));
            self::$dataLive = unserialize($cache->get($cachekey . 'dataLive'));
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
            $sql = 'SELECT * FROM "File" "'.$stage.'" WHERE ClassName <> \''.Folder::class.'\';';
            $rows = DB::query($sql);
            foreach($rows as $row) {
                if(empty($row['FileFilename'])) {
                    $file = $row['Filename'];
                } else {
                    $file = $row['FileFilename'];
                }
                $absoluteLocation = $this->path . DIRECTORY_SEPARATOR . $file;
                if ($stage === '') {
                    self::$dataStaging[$row['ID']] = $row;
                } else {
                    self::$dataLive[$row['ID']] = $row;
                }
                $finalArray[$absoluteLocation] = $absoluteLocation;
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
