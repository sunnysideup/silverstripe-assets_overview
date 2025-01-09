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
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\ArrayData;
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

    public static function inst(): AllFilesInfo
    {
        return Injector::inst()
            ->get(AllFilesInfo::class, true, [ASSETS_PATH]);
    }

    private static array $not_real_file_substrings = [
        DIRECTORY_SEPARATOR . '_resampled',
        DIRECTORY_SEPARATOR . '__',
        DIRECTORY_SEPARATOR . '.',
        '__Fit',
        '__Pad',
        '__Fill',
        '__Focus',
        '__Scale',
        '__ResizedImage',
    ];

    protected $debug = false;

    /**
     * @var string
     */
    protected string $path = '';

    /**
     * @var array
     */
    protected array $dataStaging = [];

    /**
     * @var array
     */
    protected array $dataLive = [];

    /**
     * @var array
     */
    protected array $listOfFiles = [];

    /**
     * @var array
     */
    protected array $databaseLookupListStaging = [];


    /**
     * @var array
     */
    protected array $databaseLookupListLive = [];


    /**
     * @var ArrayList
     */
    protected ArrayList $filesAsArrayList;

    /**
     * @var ArrayList
     */
    protected ArrayList $filesAsSortedArrayList;

    /**
     * @var array
     */
    protected array $availableExtensions = [];



    /**
     * @var int
     */
    protected int $totalFileCountRaw = 0;


    /**
     * @var int
     */
    protected int $totalFileCountFiltered = 0;

    /**
     * @var int
     */
    protected int $totalFileSizeFiltered = 0;

    /**
     * @var int
     */
    protected int $limit = 1000;

    /**
     * @var int
     */
    protected int $startLimit = 0;

    /**
     * @var int
     */
    protected int $endLimit = 0;

    /**
     * @var int
     */
    protected int $pageNumber = 1;

    /**
     * @var string
     */
    protected string $sorter = 'byfolder';
    protected array $sorters = [];

    /**
     * @var string
     */
    protected string $filter = '';

    /**
     * @var string
     */
    protected array $filters = [];

    /**
     * @var string
     */
    protected string $displayer = 'thumbs';

    /**
     * @var array
     */
    protected array $allowedExtensions = [];


    public function __construct($path)
    {
        $this->path = $path;
    }

    public function getTotalFilesCount(): int
    {
        return (int) count($this->listOfFiles);
    }

    /**
     * does the file exists in the database on staging?
     */
    public function existsOnStaging(int $id): bool
    {
        return isset($this->dataStaging[$id]);
    }

    /**
     * does the file exists in the database on live?
     */
    public function existsOnLive(int $id): bool
    {
        return isset($this->dataLive[$id]);
    }

    /**
     * get data from staging database row.
     */
    public function getAnyData(string $pathFromAssets, ?int $id = 0): array
    {
        $data = self::getStagingData($pathFromAssets, $id);
        if (empty($data)) {
            $data = self::getLiveData($pathFromAssets, $id);
        }

        return $data;
    }

    /**
     * get data from staging database row.
     *
     * @param string $pathFromAssets from the root of assets
     * @param int    $id
     */
    public function getStagingData(string $pathFromAssets, ?int $id = 0): array
    {
        if (! $id) {
            $id = $this->databaseLookupListStaging[$pathFromAssets] ?? 0;
        }

        return $this->dataStaging[$id] ?? [];
    }

    /**
     * get data from live database row.
     *
     * @param string $pathFromAssets - full lookup list
     */
    public function getLiveData(string $pathFromAssets, ?int $id = 0): array
    {
        if (! $id) {
            $id = $this->databaseLookupListLive[$pathFromAssets] ?? 0;
        }

        return $this->dataLive[$id] ?? [];
    }

    /**
     * find a value in a field in staging
     * returns ID of row.
     *
     * @param mixed $value
     */
    public function findInStagingData(string $fieldName, $value): int
    {
        return self::findInData($this->dataStaging, $fieldName, $value);
    }

    /**
     * find a value in a field in live
     * returns ID of row.
     *
     * @param mixed $value
     */
    public function findInLiveData(string $fieldName, $value): int
    {
        return self::findInData($this->dataLive, $fieldName, $value);
    }

    public function getTotalFileSizesRaw()
    {
        $bytestotal = 0;
        $absoluteAssetPath = realpath(ASSETS_PATH);
        if (false !== $absoluteAssetPath && '' !== $absoluteAssetPath && file_exists($absoluteAssetPath)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absoluteAssetPath, FilesystemIterator::SKIP_DOTS)) as $object) {
                $bytestotal += $object->getSize();
            }
        }

        return $bytestotal;
    }

    public function toArray(): array
    {
        if ([] === $this->listOfFiles) {
            $cachekey = $this->getCacheKey();
            if ($this->hasCacheKey($cachekey)) {
                $this->listOfFiles = $this->getCacheValue($cachekey);
                $this->availableExtensions = $this->getCacheValue($cachekey . 'availableExtensions');
                $this->dataStaging = $this->getCacheValue($cachekey . 'dataStaging');
                $this->dataLive = $this->getCacheValue($cachekey . 'dataLive');
                $this->databaseLookupListStaging = $this->getCacheValue($cachekey . 'databaseLookupStaging');
                $this->databaseLookupListLive = $this->getCacheValue($cachekey . 'databaseLookupLive');
            } else {
                //disk
                $diskArray = $this->getArrayOfFilesOnDisk();
                foreach ($diskArray as $path) {
                    $this->registerFile($path, true);
                }

                //database
                $databaseArray = $this->getArrayOfFilesInDatabase();
                foreach ($databaseArray as $path) {
                    $location = trim(str_replace(ASSETS_PATH, '', $path), '/');
                    $this->registerFile($location, false);
                }

                asort($this->listOfFiles);
                asort($this->availableExtensions);
                $this->setCacheValue($cachekey, $this->listOfFiles);
                $this->setCacheValue($cachekey . 'availableExtensions', $this->availableExtensions);
                $this->setCacheValue($cachekey . 'dataStaging', $this->dataStaging);
                $this->setCacheValue($cachekey . 'dataLive', $this->dataLive);
                $this->setCacheValue($cachekey . 'databaseLookupStaging', $this->databaseLookupListStaging);
                $this->setCacheValue($cachekey . 'databaseLookupLive', $this->databaseLookupListLive);
            }
        }

        return $this->listOfFiles;
    }

    public function getFilesAsArrayList(): ArrayList
    {
        if (!isset($this->filesAsArrayList)) {
            $rawArray = $this->toArray();
            //prepare loop
            $this->totalFileCountRaw = self::getTotalFilesCount();
            $this->filesAsArrayList = ArrayList::create();
            $filterFree = true;
            $filterField = null;
            $filterValues = [];
            if (isset($this->filters[$this->filter])) {
                $filterFree = false;
                $filterField = $this->filters[$this->filter]['Field'];
                $filterValues = $this->filters[$this->filter]['Values'];
            }

            foreach ($rawArray as $location => $fileExists) {
                if ($this->isPathWithAllowedExtension($this->allowedExtensions, $location)) {
                    $intel = $this->getDataAboutOneFile($location, $fileExists);
                    if ($filterFree || in_array($intel[$filterField], $filterValues, 1)) {
                        ++$this->totalFileCountFiltered;
                        $this->totalFileSizeFiltered += $intel['PathFileSize'];
                        $this->filesAsArrayList->push(
                            ArrayData::create($intel)
                        );
                    }
                }
            }
        }

        return $this->filesAsArrayList;
    }


    public function getFilesAsSortedArrayList(): ArrayList
    {
        if (!isset($this->filesAsSortedArrayList)) {
            $sortField = $this->sorters[$this->sorter]['Sort'];
            $headerField = $this->sorters[$this->sorter]['Group'];
            $this->filesAsSortedArrayList = ArrayList::create();
            $this->filesAsArrayList = $this->filesAsArrayList->Sort($sortField);

            $count = 0;

            $innerArray = ArrayList::create();
            $prevHeader = 'nothing here....';
            $newHeader = '';
            foreach ($this->filesAsArrayList as $file) {
                $newHeader = $file->{$headerField};
                if ($newHeader !== $prevHeader) {
                    $this->addTofilesAsSortedArrayList(
                        $prevHeader, //correct! important ...
                        $innerArray
                    );
                    $prevHeader = $newHeader;
                    unset($innerArray);
                    $innerArray = ArrayList::create();
                }

                if ($count >= $this->startLimit && $count < $this->endLimit) {
                    $innerArray->push($file);
                } elseif ($count >= $this->endLimit) {
                    break;
                }

                ++$count;
            }

            //last one!
            $this->addTofilesAsSortedArrayList(
                $newHeader,
                $innerArray
            );
        }

        return $this->filesAsSortedArrayList;
    }

    protected function addTofilesAsSortedArrayList(string $header, ArrayList $arrayList)
    {
        if ($arrayList->exists()) {
            $count = $this->filesAsSortedArrayList->count();
            $this->filesAsSortedArrayList->push(
                ArrayData::create(
                    [
                        'Number' => $count,
                        'SubTitle' => $header,
                        'Items' => $arrayList,
                    ]
                )
            );
        }
    }

    protected function getDataAboutOneFile(string $location, ?bool $fileExists): array
    {
        return OneFileInfo::inst($location, $fileExists)
            ->toArray();
    }

    /**
     * @param string $path - does not have to be full path
     */
    protected function isPathWithAllowedExtension(array $allowedExtensions, string $path): bool
    {
        $count = count($allowedExtensions);
        if (0 === $count) {
            return true;
        }

        $extension = strtolower($this->getExtension($path));
        if ($extension === '') {
            $extension = 'n/a';
        }

        return in_array($extension, $allowedExtensions, true);
    }

    protected function registerFile($path, ?bool $inFileSystem = true)
    {
        if ($path) {
            if (! isset($this->listOfFiles[$path])) {
                $this->listOfFiles[$path] = $inFileSystem;
                if ($this->debug) {
                    echo $inFileSystem ? '✓ ' : 'x ';
                }

                $extension = strtolower($this->getExtension($path));
                $this->availableExtensions[$extension] = $extension;
            }
        }
    }


    /**
     * @param mixed $value
     */
    protected function findInData(array $data, string $fieldName, $value): int
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

    protected function isRealFile(string $absolutePath): bool
    {
        $listOfItemsToSearchFor = Config::inst()->get(self::class, 'not_real_file_substrings');
        foreach ($listOfItemsToSearchFor as $test) {
            if (strpos($absolutePath, $test)) {
                return false;
            }
        }

        $fileName = basename($absolutePath);
        $isErrorPage = ('error' === substr((string) $fileName, 0, 5) && '.html' === substr((string) $fileName, -5));
        return ! $isErrorPage;
    }

    protected function getArrayOfFilesOnDisk(): array
    {
        $finalArray = [];
        $arrayRaw = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($arrayRaw as $src) {
            $absolutePath = $src->getPathName();
            if (false === $this->isRealFile($absolutePath)) {
                continue;
            }
            $location = trim(str_replace(ASSETS_PATH, '', $absolutePath), '/');
            $finalArray[$location] = $location;
        }

        return $finalArray;
    }

    /**
     *
     * returns all the files in the database except for folders.
     * @return array
     */
    protected function getArrayOfFilesInDatabase(): array
    {
        $finalArray = [];
        foreach (['Stage', 'Live'] as $stage) {
            Versioned::set_stage($stage);
            $files = File::get();
            foreach ($files as $file) {
                $row = $file->toMap();
                $location = trim($file->getFilename(), '/');
                if (!$location) {
                    $location = $file->generateFilename();
                }
                if ('Stage' === $stage) {
                    $this->dataStaging[$row['ID']] = $row;
                    $this->databaseLookupListStaging[$location] = $row['ID'];
                } elseif ('Live' === $stage) {
                    $this->dataLive[$row['ID']] = $row;
                    $this->databaseLookupListLive[$location] = $row['ID'];
                } else {
                    user_error('Can not find stage');
                }

                $finalArray[$location] = $location;
            }
        }
        Versioned::set_stage('Stage');
        return $finalArray;
    }

    //#############################################
    // CACHE
    //#############################################

    protected function getCacheKey(): string
    {
        return 'allfiles';
    }


    public function getAvailableExtensions(): array
    {
        return $this->availableExtensions;
    }


    public function getTotalFileCountRaw(): int
    {
        return $this->totalFileCountRaw;
    }


    public function getTotalFileCountFiltered(): int
    {
        return $this->totalFileCountFiltered;
    }


    public function getTotalFileSizeFiltered(): int
    {
        return $this->totalFileSizeFiltered;
    }

    public function setAvailableExtensions(array $availableExtensions): static
    {
        $this->availableExtensions = $availableExtensions;
        return $this;
    }

    public function setFilters(array $filters): static
    {
        $this->filters = $filters;
        return $this;
    }

    public function setSorters(array $sorters): static
    {
        $this->sorters = $sorters;
        return $this;
    }

    public function setLimit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    public function setStartLimit(int $startLimit): static
    {
        $this->startLimit = $startLimit;
        return $this;
    }

    public function setEndLimit(int $endLimit): static
    {
        $this->endLimit = $endLimit;
        return $this;
    }

    public function setPageNumber(int $pageNumber): static
    {
        $this->pageNumber = $pageNumber;
        return $this;
    }

    public function setSorter(string $sorter): static
    {
        $this->sorter = $sorter;
        return $this;
    }

    public function setFilter(string $filter): static
    {
        $this->filter = $filter;
        return $this;
    }

    public function setDisplayer(string $displayer): static
    {
        $this->displayer = $displayer;
        return $this;
    }

    public function setAllowedExtensions(array $allowedExtensions): static
    {
        $this->allowedExtensions = $allowedExtensions;
        return $this;
    }
}
