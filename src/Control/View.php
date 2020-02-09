<?php

// if ($action) {
//     $link .= $action . DIRECTORY_SEPARATOR;
// }
// $getVars = [];
// if ($ext = $this->request->getVar('ext')) {
//     $getVars['ext'] = $ext;
// }
// if ($limit = $this->request->getVar('limit')) {
//     $getVars['limit'] = $limit;
// }
// if (count($getVars)) {
//     $link .= '?' . http_build_query($getVars);
// }

namespace Sunnysideup\AssetsOverview\Control;

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

class View extends ContentController implements Flushable
{
    private const FILTERS = [
        'byfolder' => [
            'Title' => 'Folder',
            'Sort' => 'FolderNameShort',
            'Group' => 'FolderNameShort',
        ],
        'byfilename' => [
            'Title' => 'Filename',
            'Sort' => 'FileName',
            'Group' => 'FirstLetter',
        ],
        'bydbtitle' => [
            'Title' => 'Database Title',
            'Sort' => 'DBTitle',
            'Group' => 'FirstLetterDBTitle',
        ],
        'byfilesize' => [
            'Title' => 'Filesize',
            'Sort' => 'FileSize',
            'Group' => 'HumanFileSizeRounded',
        ],
        'bylastedited' => [
            'Title' => 'Last Edited',
            'Sort' => 'LastEditedTS',
            'Group' => 'LastEdited',
        ],
        'byextension' => [
            'Title' => 'Extension',
            'Sort' => 'ExtensionAsLower',
            'Group' => 'ExtensionAsLower',
        ],
        'byextensionerror' => [
            'Title' => 'Extension Error',
            'Sort' => 'HasIrregularExtension',
            'Group' => 'HumanHasIrregularExtension',
        ],
        'bydatabasestatus' => [
            'Title' => 'Database Status',
            'Sort' => 'IsInDatabase',
            'Group' => 'HumanIsInDatabase',
        ],
        'bydatabaseerror' => [
            'Title' => 'Database Error',
            'Sort' => 'ErrorInFilenameCase',
            'Group' => 'HumanErrorInFilenameCase',
        ],
        'byfoldererror' => [
            'Title' => 'Folder Error',
            'Sort' => 'ErrorParentID',
            'Group' => 'HumanErrorParentID',
        ],
        'byfilesystemstatus' => [
            'Title' => 'Filesystem Status',
            'Sort' => 'HumanIsInFileSystemIsInFileSystem',
            'Group' => '',
        ],
        'byisimage' => [
            'Title' => 'Image vs Other Files',
            'Sort' => 'IsImage',
            'Group' => 'HumanIsImage',
        ],
        'bydimensions' => [
            'Title' => 'Dimensions (small to big)',
            'Sort' => 'Pixels',
            'Group' => 'HumanImageDimensions',
        ],
        'byratio' => [
            'Title' => 'Ratio',
            'Sort' => 'Ratio',
            'Group' => 'Ratio',
        ],
        'bysimilarity' => [
            'Title' => 'Similarity (takes a long time!)',
            'Sort' => 'MostSimilarTo',
            'Group' => 'MostSimilarTo',
            'Method' => 'workOutSimilarity',
        ],
        'rawlist' => [
            'Title' => 'Raw List',
            'Sort' => 'Path',
            'Group' => 'Path',
            'Method' => 'workoutRawList',
        ],
        'rawlistfull' => [
            'Title' => 'Full Raw List',
            'Sort' => 'Path',
            'Group' => 'Path',
            'Method' => 'workoutRawListFull',
        ],
    ];


    /**
     * @var ArrayList|null
     */
    protected $imagesRaw = null;

    /**
     * @var string
     */
    protected $title = '';

    /**
     * @var ArrayList|null
     */
    protected $imagesSorted = null;

    /**
     * @var string
     */
    protected $baseFolder = '';

    /**
     * @var string
     */
    protected $assetsBaseFolder = '';

    /**
     * @var int
     */
    protected $totalFileCount = 0;

    /**
     * @var int
     */
    protected $totalFileSize = 0;

    /**
     * @var int
     */
    protected $limit = 1000;

    /**
     * @var int
     */
    protected $startLimit = 0;

    /**
     * @var int
     */
    protected $endLimit = 0;

    /**
     * @var int
     */
    protected $pageNumber = 1;

    /**
     * @var string
     */
    protected $filter = 'byfolder';

    /**
     * @var array
     */
    protected $allowedExtensions = [];

    /**
     * @var array
     */
    protected $availableExtensions = [];

    /**
     * @var bool
     */
    protected $isThumbList = true;

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

    public static function flush()
    {
        $cache = self::getCache();
        $cache->clear();
    }

    public function Link($action = null)
    {
        return Director::absoluteURL(DIRECTORY_SEPARATOR . 'assetsoverview' . DIRECTORY_SEPARATOR) . $action;
    }

    public function getTitle(): string
    {
        $list = self::FILTERS;

        return $list[$this->filter]['Title'] ?? 'NO FILTER AVAILABLE';
    }

    public function getIsThumbList(): bool
    {
        return $this->isThumbList;
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
        $this->baseFolder = $this->getBaseFolder();
        $this->assetsBaseFolder = rtrim($this->getAssetBaseDir(), DIRECTORY_SEPARATOR);
        $this->allowedExtensions = $this->Config()->get('allowed_extensions');
        if ($extensions = $this->request->getVar('extensions')) {
            $this->allowedExtensions = $extensions;
        }
        if ($limit = $this->request->getVar('limit')) {
            $this->limit = $limit;
        }
        if ($pageNumber = $this->request->getVar('page')) {
            $this->pageNumber = $pageNumber;
        }
        if ($filter = $this->request->getVar('filter')) {
            $this->filter = $filter;
        }
        $this->startLimit = $this->limit * ($this->pageNumber - 1);
        $this->endLimit = $this->limit * $this->pageNumber;
    }

    public function index($request)
    {
        $list = self::FILTERS;
        $actionData = $list[$this->filter];
        if (isset($list[$this->filter])) {
            $this->workOutImagesSorted($actionData['Sort'], $actionData['Group']);
            if (! empty($actionData['Method'])) {
                $this->{$actionData['Method']}();
            }
        } else {
            user_error('Could not find filter');
        }

        return $this->renderWith('AssetsOverview');
    }

    public function workoutRawList()
    {
        $this->isThumbList = false;
        foreach ($this->imagesSorted as $group) {
            foreach ($group->Items as $item) {
                $item->HTML = '<li>' . $item->FileNameInDB . '</li>';
            }
        }
        return [];
    }

    public function workoutRawListFull()
    {
        $this->isThumbList = false;
        foreach ($this->imagesSorted as $group) {
            foreach ($group->Items as $item) {
                $map = $item->toMap();
                ksort($map);
                $item->HTML = '
                    <li>
                        <strong>' . $item->FileNameInDB . '</strong>
                        <ul>
                            <li>
                            ' .
                            implode(
                                '</li><li>',
                                array_map(
                                    function ($v, $k) {
                                        return sprintf("<strong>%s</strong>: '%s'", $k, $v);
                                    },
                                    $map,
                                    array_keys($map)
                                )
                            ) . '
                            </li>
                        </ul>
                    </li>';
            }
        }
        return [];
    }

    ##############################################
    # FORM
    ##############################################

    public function getForm(): Form
    {
        $fieldList = FieldList::create(
            [
                $this->createFormField('filter', 'Group By', $this->filter, $this->getFilterList()),
                $this->createFormField('extensions', 'Extensions', $this->allowedExtensions, $this->getExtensionList()),
                $this->createFormField('limit', 'Items Per Page', $this->limit, $this->getLimitList()),
                $this->createFormField('page', 'Page Number', $this->pageNumber, $this->getPageNumberList()),
            ]
        );
        $actionList = FieldList::create(
            [
                FormAction::create('index', 'show'),
            ]
        );

        $form = Form::create($this, 'index', $fieldList, $actionList);
        $form->setFormMethod('GET', true);
        $form->disableSecurityToken();

        return $form;
    }

    protected function workOutSimilarity()
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
    }

    protected function workOutImagesSorted($sortField, $headerField)
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
                    $this->addToImagesSorted(
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
            $this->addToImagesSorted(
                $newHeader,
                $innerArray
            );
        }

        return $this->renderWith('AssetsOverview');
    }

    protected function addToImagesSorted(string $header, ArrayList $arrayList)
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
        return in_array(
            strtolower($extension),
            ['jpg', 'gif', 'png'],
            true
        );
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

    /**
     * @return string
     */
    protected function getBaseFolder(): string
    {
        return rtrim(Director::baseFolder(), DIRECTORY_SEPARATOR);
    }

    /**
     * @return string
     */
    protected function getAssetBaseDir(): string
    {
        return ASSETS_PATH;
    }

    protected function buildFileCache()
    {
        if ($this->imagesRaw === null) {
            $this->imagesRaw = ArrayList::create();
            $cache = self::getCache();
            $cachekey = $this->getCacheKey();
            if (! $cache->has($cachekey)) {
                $fullArray = [];
                $rawArray = $this->getArrayOfFilesOnDisk();
                $filesOnDiskArray = $this->getArrayOfFilesOnDisk();
                foreach ($filesOnDiskArray as $relativeSrc) {
                    $absoluteLocation = $this->baseFolder . '/' . $relativeSrc;
                    if (! isset($rawArray[$absoluteLocation])) {
                        if ($this->isPathWithAllowedExtension($absoluteLocation)) {
                            $rawArray[$absoluteLocation] = false;
                        }
                    }
                }
                $count = 0;
                $this->totalFileCount = count($rawArray);
                foreach ($rawArray as $absoluteLocation => $fileExists) {
                    if ($count >= $this->startLimit && $count < $this->endLimit) {
                        $intel = $this->getDataAboutOneFile($absoluteLocation, $fileExists);
                        $this->availableExtensions[$intel['ExtensionAsLower']] = $intel['ExtensionAsLower'];
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
                $intel['Attribute'] = print_r($attr, 1);
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
        $intel['LastEdited'] = DBDate::create_field('Date', $time)->Ago();
        $intel['HumanIsInDatabase'] = $intel['IsInDatabase'] ? 'In Database' : 'Not in Database';
        $intel['HumanErrorInFilenameCase'] = $intel['ErrorInFilenameCase'] ? 'Error in Case' : 'Perfect Case';
        $intel['HumanErrorParentID'] = $intel['ErrorParentID'] ? 'Error in folder ID' : 'Perfect Folder ID';
        $intel['FirstLetterDBTitle'] = strtoupper(substr($intel['DBTitle'], 0, 1));

        return $intel;
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
            if ($this->isPathWithAllowedExtension($path)) {
                $array[$path] = true;
            }
        }

        return $array;
    }

    /**
     * @return array
     */
    protected function getArrayOfFilesInDatabase(): array
    {
        return File::get()
            ->exclude(['ClassName' => Folder::class])
            ->column('Filename');
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

    protected function getCacheKey(): string
    {
        return 'fullarray_' .
            implode('_', $this->allowedExtensions) . '_' .
            $this->limit . '_' .
            $this->pageNumber . '_';
    }

    protected function createFormField(string $name, string $title, $value, ?array $list = [])
    {
        $listCount = count($list);
        if ($listCount === 0) {
            $type = HiddenField::class;
        } elseif ($name === 'extensions') {
            $type = CheckboxSetField::class;
        } elseif ($listCount < 13) {
            $type = DropdownField::class;
        } else {
            $type = OptionsetField::class;
        }

        $field = $type::create($name, $title)
            ->setValue($value);
        if ($listCount) {
            $field->setSource($list);
        }

        return $field;
    }

    protected function getFilterList(): array
    {
        $array = [];
        foreach (self::FILTERS as $key => $data) {
            $array[$key] = $data['Title'];
        }

        return $array;
    }

    protected function getExtensionList(): array
    {
        asort($this->availableExtensions);

        return $this->availableExtensions;
    }

    protected function getPageNumberList(): array
    {
        return range(1, $this->getNumberOfPages());
    }

    protected function getNumberOfPages(): Int
    {
        return ceil($this->totalFileCount / $this->limit);
    }

    protected function getLimitList(): array
    {
        $step = 250;
        $array = [];
        for ($i = $step; ($i - $step) < $this->limit; $i += $step) {
            $array[$i] = $i;
        }
        return $array;
    }
}
