<?php

namespace Sunnysideup\AssetsOverview\Control;

use \Exception;

use SilverStripe\Core\Flushable;
use \RecursiveDirectoryIterator;
use \RecursiveIteratorIterator;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;
use Sunnysideup\AssetsOverview\Api\CompareImages;

class View extends ContentController implements Flushable
{
    protected $imagesRaw = null;

    protected $title = '';

    protected $imagesSorted = null;

    protected $baseFolder = '';

    protected $assetsBaseFolder = '';

    protected $totalFileCount = 0;

    protected $totalFileSize = 0;

    protected $limit = 1000;

    protected $allowedExtensions = [];

    private static $allowed_extensions = [];

    private static $allowed_actions = [
        'byfolder' => 'ADMIN',
        'byfilename' => 'ADMIN',
        'byfilesize' => 'ADMIN',
        'byextension' => 'ADMIN',
        'byextensionerror' => 'ADMIN',
        'bydatabasestatus' => 'ADMIN',
        'bydatabaseerror' => 'ADMIN',
        'byfoldererror' => 'ADMIN',
        'byfilesystemstatus' => 'ADMIN',
        'bydbtitle' => 'ADMIN',
        'byisimage' => 'ADMIN',
        'bydimensions' => 'ADMIN',
        'byratio' => 'ADMIN',
        'bylastedited' => 'ADMIN',
        'bysimilarity' => 'ADMIN',
        'rawlist' => 'ADMIN',
        'rawlistfull' => 'ADMIN',
    ];

    private static $names = [
        'byfolder' => 'Folder',
        'byfilename' => 'Filename',
        'byfilesize' => 'Filesize',
        'bylastedited' => 'Last Edited',
        'byextension' => 'Extension',
        'byextensionerror' => 'Extension Error',
        'bydatabasestatus' => 'Database Status',
        'bydatabaseerror' => 'Database Error',
        'byfoldererror' => 'Folder Error',
        'byfilesystemstatus' => 'Filesystem Status',
        'bydbtitle' => 'File Title',
        'byisimage' => 'Image vs Other Files',
        'bydimensions' => 'Dimensions',
        'byratio' => 'Ratio',
        'bysimilarity' => 'Similarity (takes a long time!)',
        'rawlist' => 'Raw List',
        'rawlistfull' => 'Full Raw List',
    ];

    public static function flush()
    {
        $cache = Injector::inst()->get(CacheInterface::class . '.assetsoverviewCache');
        $cache->clear();
    }


    public function Link($action = null)
    {
        $base = rtrim(Director::absoluteBaseURL(), DIRECTORY_SEPARATOR);
        $link = $base . DIRECTORY_SEPARATOR . 'assetsoverview' . DIRECTORY_SEPARATOR;
        if ($action) {
            $link .= $action . DIRECTORY_SEPARATOR;
        }
        $getVars = [];
        if ($ext = $this->request->getVar('ext')) {
            $getVars['ext'] = $ext;
        }
        if ($limit = $this->request->getVar('limit')) {
            $getVars['limit'] = $limit;
        }
        if (count($getVars)) {
            $link .= '?' . http_build_query($getVars);
        }
        return $link ;
    }

    public function getActionMenu(): ArrayList
    {
        $al = ArrayList::create();
        $action = $this->request->param('Action') ?: 'byfolder';
        foreach ($this->Config()->get('names') as $key => $name) {
            $linkingMode = $key === $action ? 'current' : 'link';
            $array = [
                'Link' => $this->Link($key),
                'Title' => $name,
                'LinkingMode' => $linkingMode,
            ];
            $al->push(ArrayData::create($array));
        }

        return $al;
    }

    public function getTitle(): string
    {
        $list = $this->Config()->get('names');

        return $list[$this->request->param('Action')] ?? 'By Folder';
    }

    public function getImagesRaw(): ArrayList
    {
        return $this->imagesRaw;
    }

    public function getImagesSorted(): ArrayList
    {
        return $this->imagesSorted;
    }

    public function getTotalFileCount(): string
    {
        return (string) number_format($this->totalFileCount);
    }

    public function getTotalFileSize(): string
    {
        return (string) $this->humanFileSize($this->totalFileSize);
    }

    public function init()
    {
        parent::init();
        if (! Permission::check('ADMIN')) {
            return Security::permissionFailure($this);
        }
        Requirements::clear();
        $this->baseFolder = Director::baseFolder();
        $this->assetsBaseFolder = $this->getAssetBaseDir();
        $this->allowedExtensions = $this->Config()->get('allowed_extensions');
        if ($ext = $this->request->getVar('ext')) {
            $this->allowedExtensions = explode(',', $ext);
        }
        if ($limit = $this->request->getVar('limit')) {
            $this->limit = $limit;
        }
    }

    public function index($request)
    {
        return $this->createProperList('FolderNameShort', 'FolderNameShort');
    }

    public function byfolder($request)
    {
        return $this->createProperList('FolderNameShort', 'FolderNameShort');
    }

    public function byfilename($request)
    {
        return $this->createProperList('FileName', 'FirstLetter');
    }

    public function bydbtitle($request)
    {
        return $this->createProperList('DBTitle', 'FirstLetterDBTitle');
    }

    public function byfilesize($request)
    {
        return $this->createProperList('FileSize', 'HumanFileSizeRounded');
    }

    public function byextension($request)
    {
        return $this->createProperList('ExtensionAsLower', 'ExtensionAsLower');
    }

    public function byextensionerror($request)
    {
        return $this->createProperList('HasIrregularExtension', 'HumanHasIrregularExtension');
    }

    public function byisimage($request)
    {
        return $this->createProperList('IsImage', 'HumanIsImage');
    }

    public function bydimensions($request)
    {
        return $this->createProperList('Pixels', 'HumanImageDimensions');
    }

    public function byratio($request)
    {
        return $this->createProperList('Ratio', 'Ratio');
    }

    public function bydatabasestatus($request)
    {
        return $this->createProperList('IsInDatabase', 'HumanIsInDatabase');
    }

    public function bydatabaseerror($request)
    {
        return $this->createProperList('ErrorInFilenameCase', 'HumanErrorInFilenameCase');
    }

    public function byfoldererror($request)
    {
        return $this->createProperList('ErrorParentID', 'HumanErrorParentID');
    }

    public function byfilesystemstatus($request)
    {
        return $this->createProperList('IsInFileSystem', 'HumanIsInFileSystem');
    }

    public function bylastedited($request)
    {
        return $this->createProperList('LastEditedTS', 'LastEdited');
    }

    public function bysimilarity($request)
    {
        set_time_limit(240);
        $engine = new CompareImages();
        $this->buildFileCache();
        $a = clone $this->imagesRaw;
        $b = clone $this->imagesRaw;
        $c = clone $this->imagesRaw;
        $alreadyDone = [];
        foreach ($a as $image) {
            $nameOne = $image->Path;
            $nameOneFromAssets = $image->PathFromAssets;
            if (! in_array($nameOne, $alreadyDone, true)) {
                $easyFind = false;
                $sortArray = [];
                foreach ($b as $compareImage) {
                    $nameTwo = $compareImage->Path;
                    if ($nameOne !== $nameTwo) {
                        $fileNameTest = $image->FileName && $image->FileName === $compareImage->FileName;
                        $fileSizeTest = $image->FileSize > 0 && $image->FileSize === $compareImage->FileSize;
                        if ($fileNameTest || $fileSizeTest) {
                            $easyFind = true;
                            $alreadyDone[$nameOne] = $nameOneFromAssets;
                            $alreadyDone[$compareImage->Path] = $nameOneFromAssets;
                        } elseif ($easyFind === false && $image->IsImage) {
                            if ($image->Ratio === $compareImage->Ratio && $image->Ratio > 0) {
                                $score = $engine->compare($nameOne, $nameTwo);
                                $sortArray[$nameTwo] = $score;
                                break;
                            }
                        }
                    }
                }
                if ($easyFind === false) {
                    if (count($sortArray)) {
                        asort($sortArray);
                        reset($sortArray);
                        $mostSimilarKey = key($sortArray);
                        foreach ($c as $findImage) {
                            if ($findImage->Path === $mostSimilarKey) {
                                $alreadyDone[$nameOne] = $nameOneFromAssets;
                                $alreadyDone[$findImage->Path] = $nameOneFromAssets;
                                break;
                            }
                        }
                    } else {
                        $alreadyDone[$image->Path] = '[N/A]';
                    }
                }
            }
        }
        foreach ($this->imagesRaw as $image) {
            $image->MostSimilarTo = $alreadyDone[$image->Path] ?? '[N/A]';
        }

        return $this->createProperList('MostSimilarTo', 'MostSimilarTo');
    }

    public function rawlist($request)
    {
        $this->createProperList('Path', 'Path');
        echo '<ul>';
        foreach ($this->imagesSorted as $group) {
            foreach ($group->Items as $item) {
                echo '<li>'. $item->FileNameInDB .'</li>';
            }
        }
        echo '</ul>';
    }
    public function rawlistfull($request)
    {
        $this->createProperList('Path', 'Path');
        echo '<ol>';
        foreach ($this->imagesSorted as $group) {
            foreach ($group->Items as $item) {
                $map = $item->toMap();
                ksort($map);
                echo '<li><strong>'. $item->FileNameInDB .'</strong>
                    <ul>
                        <li>
                        '.
                        implode(
                            '</li><li>',
                            array_map(
                                function ($v, $k) {
                                    return sprintf("<strong>%s</strong>: '%s'", $k, $v);
                                },
                                $map,
                                array_keys($map)
                            )
                        ).'
                        </li>
                    </ul>
                </li>';
            }
        }
        echo '</ol>';
    }

    protected function createProperList($sortField, $headerField)
    {
        if ($this->imagesSorted === null) {
            //done only if not already done ...
            $this->buildFileCache();
            $this->imagesRaw = $this->imagesRaw->Sort($sortField);
            $this->imagesSorted = ArrayList::create();

            $innerArray = ArrayList::create();
            $prevHeader = 'nothing here....';
            $newHeader = '';
            foreach ($this->imagesRaw as $image) {
                $newHeader = $image->{$headerField};
                if ($newHeader !== $prevHeader) {
                    $this->addToSortedArray(
                        $prevHeader, //correct! important ...
                        $innerArray
                    );
                    $prevHeader = $newHeader;
                    unset($innerArray);
                    $innerArray = ArrayList::create();
                }
                $innerArray->push($image);
            }

            //last one!
            $this->addToSortedArray(
                $newHeader,
                $innerArray
            );
        }

        return $this->renderWith('AssetsOverview');
    }

    protected function addToSortedArray(string $header, $arrayList)
    {
        if ($arrayList->count()) {
            $count = $this->imagesSorted->count();
            $this->imagesSorted->push(
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

    protected function isRegularImage(string $extension): bool
    {
        return in_array(strtolower($extension), ['jpg', 'gif', 'png'], true);
    }

    protected function isImage(string $filename): bool
    {
        try {
            $outcome = exif_imagetype($filename) ? true : false;
        } catch (Exception $e) {
            $outcome = false;
        }

        return $outcome;
    }

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

    protected function getAssetBaseDir(): string
    {
        return ASSETS_PATH;
    }

    protected function buildFileCache()
    {
        if ($this->imagesRaw === null) {
            $fullArray = [];
            $this->imagesRaw = ArrayList::create();
            $cache = Injector::inst()->get(CacheInterface::class . '.assetsoverviewCache');
            $cachekey = 'fullarray_' . implode('_', $this->allowedExtensions).'_'.$this->limit;
            if (! $cache->has($cachekey)) {
                $rawArray = $this->getArrayOfFilesOnDisk();
                $filesOnDiskArray = $this->getArrayOfFilesOnDisk();
                foreach ($filesOnDiskArray as $relativeSrc) {
                    $absoluteLocation = $this->baseFolder . '/' . $relativeSrc;
                    if (! isset($rawArray[$absoluteLocation])) {
                        if ($this->isPathWithAllowedExtion($absoluteLocation)) {
                            $rawArray[$absoluteLocation] = false;
                        }
                    }
                }
                $count = 0;
                foreach ($rawArray as $absoluteLocation => $fileExists) {
                    if ($count < $this->limit) {
                        $intel = $this->getDataAboutOneFile($absoluteLocation, $fileExists);
                        $fullArray[$intel['Path']] = $intel;
                    }
                    $count++;
                }
                $fullArrayString = serialize($fullArray);
                $cache->set($cachekey, $fullArrayString);
            } else {
                $fullArrayString = $cache->get($cachekey);
                $fullArray = unserialize($fullArrayString);
            }
            $this->totalFileCount = count($fullArray);
            foreach ($fullArray as $intel) {
                $this->totalFileSize += $intel['FileSize'];
                $this->imagesRaw->push(
                    ArrayData::create($intel)
                );
            }
        }
        return $this->imagesRaw;
    }

    protected function getDataAboutOneFile($absoluteLocation, $fileExists): array
    {
        $intel = [];
        $pathParts = [];
        if ($fileExists) {
            $pathParts = pathinfo($absoluteLocation);
        }
        $pathParts['extension'] = $pathParts['extension'] ?? '--no-extension';
        $pathParts['filename'] = $pathParts['filename'] ?? '--no-file-name';
        $pathParts['dirname'] = $pathParts['dirname'] ?? '--no-parent-dir';
        $relativeDirFromBaseFolder = str_replace($this->baseFolder, '', $pathParts['dirname']);
        $relativeDirFromAssetsFolder = str_replace($this->assetsBaseFolder, '', $pathParts['dirname']);

        $intel['Extension'] = $pathParts['extension'];
        $intel['ExtensionAsLower'] = (string) strtolower($intel['Extension']);
        $intel['HasIrregularExtension'] = $intel['Extension'] !== $intel['ExtensionAsLower'];
        $intel['HumanHasIrregularExtension'] = $intel['HasIrregularExtension'] ?
            'irregular extension' : 'normal extension';
        $intel['FileName'] = $pathParts['filename'];

        $intel['FileSize'] = 0;

        $intel['Path'] = $absoluteLocation;
        $intel['PathFromAssets'] = str_replace($this->assetsBaseFolder, '', $absoluteLocation);
        $intel['PathFromRoot'] = str_replace($this->baseFolder, '', $absoluteLocation);
        $intel['FirstLetter'] = strtoupper(substr($intel['FileName'], 0, 1));
        $intel['FileNameInDB'] = ltrim($intel['PathFromAssets'], DIRECTORY_SEPARATOR);

        $intel['FolderName'] = trim($relativeDirFromBaseFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $intel['FolderNameShort'] = trim($relativeDirFromAssetsFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $intel['GrandParentFolder'] = dirname($intel['FolderNameShort']);

        $intel['HumanImageDimensions'] = 'n/a';
        $intel['Ratio'] = '0';
        $intel['Pixels'] = 'n/a';
        $intel['IsImage'] = false;
        $intel['HumanIsImage'] = 'Is not an image';
        $intel['IsRegularImage'] = false;
        $intel['IsInFileSystem'] = false;
        $intel['HumanIsInFileSystem'] = 'file does not exist';
        $intel['ErrorParentID'] = true;
        $intel['Type'] = $intel['Extension'];
        $intel['Attribute'] = 'n/a';

        if ($fileExists) {
            $intel['IsInFileSystem'] = true;
            $intel['HumanIsInFileSystem'] = 'file exists';
            $intel['FileSize'] = filesize($absoluteLocation);
            $intel['IsRegularImage'] = $this->isRegularImage($intel['Extension']);
            if ($intel['IsRegularImage']) {
                $intel['IsImage'] = true;
            } else {
                $intel['IsImage'] = $this->isImage($absoluteLocation);
            }
            if ($intel['IsImage']) {
                list($width, $height, $type, $attr) = getimagesize($absoluteLocation);
                $intel['HumanImageDimensions'] = $width . 'px wide by ' . $height . 'px high';
                $intel['Ratio'] = round($width / $height, 3);
                $intel['Pixels'] = $width * $height;
                $intel['HumanIsImage'] = 'Is Image';
                $intel['Type'] = $type;
            }
        }

        $intel['HumanFileSize'] = $this->humanFileSize($intel['FileSize']);
        $intel['HumanFileSizeRounded'] = '~ ' . $this->humanFileSize(round($intel['FileSize'] / 1024) * 1024);
        $file = DataObject::get_one(File::class, ['FileFilename' => $intel['FileNameInDB']]);
        $folder = null;
        if ($file) {
            $folder = DataObject::get_one(Folder::class, ['ID' => $file->ParentID]);
        }

        //backup for folder
        if (! $folder) {
            $folder = DataObject::get_one(Folder::class, ['FileFilename' => $intel['FolderName']]);
        }

        //backup for file ...
        if (! $file) {
            if ($folder) {
                $nameInDB = $intel['FileName'] . '.' . $intel['Extension'];
                $file = DataObject::get_one(File::class, ['Name' => $nameInDB, 'ParentID' => $folder->ID]);
            }
        }
        $time = 0;
        if ($file) {
            $intel['ID'] = $file->ID;
            $intel['ParentID'] = $file->ParentID;
            $intel['IsInDatabase'] = true;
            $intel['CMSEditLink'] = '/admin/assets/EditForm/field/File/item/' . $file->ID . '/edit';
            $intel['DBTitle'] = $file->Title;
            $intel['ErrorInFilenameCase'] = $intel['FileNameInDB'] !== $file->Filename;
            $time = strtotime($file->LastEdited);
            if ($folder) {
                $intel['ErrorParentID'] = (int) $folder->ID !== (int) $file->ParentID;
            } elseif ((int) $file->ParentID === 0) {
                $intel['ErrorParentID'] = false;
            }
        } else {
            $intel['ID'] = 0;
            $intel['ParentID'] = 0;
            $intel['IsInDatabase'] = false;
            $intel['CMSEditLink'] = '/admin/assets/';
            $intel['DBTitle'] = '-- no title set in database';
            $intel['ErrorInFilenameCase'] = true;
            if ($fileExists) {
                $time = filemtime($absoluteLocation);
            }
        }
        if ($folder) {
            if (! $file) {
                $intel['ParentID'] = $folder->ID;
            }
            $intel['HasFolder'] = true;
            $intel['HumanHasFolder'] = 'in sub-folder';
            $intel['CMSEditLinkFolder'] = '/admin/assets/show/' . $folder->ID;
        } else {
            $intel['HasFolder'] = false;
            $intel['HumanHasFolder'] = 'in root folder';
            $intel['CMSEditLinkFolder'] = '/assets/admin/';
        }

        $intel['LastEditedTS'] = $time;
        $intel['LastEdited'] = DBField::create_field('Date', $time)->Ago();
        $intel['HumanIsInDatabase'] = $intel['IsInDatabase'] ? 'In Database' : 'Not in Database';
        $intel['HumanErrorInFilenameCase'] = $intel['ErrorInFilenameCase'] ? 'Error in Case' : 'Perfect Case';
        $intel['HumanErrorParentID'] = $intel['ErrorParentID'] ? 'Error in folder ID' : 'Perfect Folder ID';
        $intel['FirstLetterDBTitle'] = strtoupper(substr($intel['DBTitle'], 0, 1));

        return $intel;
    }

    protected function isRealFile(string $path) : bool
    {
        $fileName = basename($path);
        $listOfItemsToSearchFor =[
            '__FitMax',
            '_resampled',
            '__Fill',
            '__Focus',
            '__Scale',
        ];
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

    protected function isPathWithAllowedExtion(string $path): bool
    {
        $extension = $this->getExtension($path);
        $count = count($this->allowedExtensions);
        if ($count === 0 || in_array($extension, $this->allowedExtensions, true)) {
            return true;
        }
        return false;
    }

    protected function getArrayOfFilesOnDisk(): array
    {
        $arrayRaw = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->assetsBaseFolder),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $array = [];
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
            if ($this->isPathWithAllowedExtion($path)) {
                $array[$path] = true;
            }
        }

        return $array;
    }

    protected function getArrayOfFilesInDatabase(): array
    {
        return File::get()
            ->exclude(['ClassName' => Folder::class])
            ->column('Filename');
    }

}
