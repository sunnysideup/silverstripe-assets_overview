<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Director;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;
use Sunnysideup\AssetsOverview\Api\CompareImages;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;
use Sunnysideup\AssetsOverview\Files\OneFileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;

class View extends ContentController
{
    use FilesystemRelatedTraits;

    private const ALL_FILES_INFO_CLASS = AllFilesInfo::class;

    private const ONE_FILE_INFO_CLASS = OneFileInfo::class;

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
     * @var ArrayList|null
     */
    protected $imagesSorted = null;

    /**
     * @var string
     */
    protected $title = '';

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
    protected $availableExtensions = [];

    /**
     * @var array
     */
    protected $allowedExtensions = [];

    /**
     * @var bool
     */
    protected $isThumbList = true;

    public function Link($action = null)
    {
        return Director::absoluteURL(DIRECTORY_SEPARATOR . 'assets-overview' . DIRECTORY_SEPARATOR) . $action;
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
        if ($filter = $this->request->getVar('filter')) {
            $this->filter = $filter;
        }

        if ($extensions = $this->request->getVar('extensions')) {
            if (! is_array($extensions)) {
                $extensions = [$extensions];
            }
            $this->allowedExtensions = $extensions;
            //make sure all are valid!
            $this->allowedExtensions = array_filter($this->allowedExtensions);
        }
        if ($limit = $this->request->getVar('limit')) {
            $this->limit = $limit;
        }
        if ($pageNumber = $this->request->getVar('page')) {
            $this->pageNumber = $pageNumber;
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
                CheckboxField::create(
                    'flush',
                    'flush all data'
                ),
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
            $this->imagesSorted = ArrayList::create();
            //done only if not already done ...
            $this->buildFileCache();
            $this->imagesRaw = $this->imagesRaw->Sort($sortField);

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

        return $this->imagesSorted;
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

    protected function buildFileCache()
    {
        if ($this->imagesRaw === null) {
            //get data
            $class = self::ALL_FILES_INFO_CLASS;
            $obj = new $class($this->getAssetsBaseFolder());
            $rawArray = $obj->toArray();
            //prepare loop
            $this->totalFileCount = count($rawArray);
            $this->imagesRaw = ArrayList::create();
            $count = 0;
            foreach ($rawArray as $absoluteLocation => $fileExists) {
                if ($this->isPathWithAllowedExtension($absoluteLocation)) {
                    if ($count >= $this->startLimit && $count < $this->endLimit) {
                        $intel = $this->getDataAboutOneFile($absoluteLocation, $fileExists);
                        $this->availableExtensions[$intel['ExtensionAsLower']] = $intel['ExtensionAsLower'];
                        $this->totalFileSize += $intel['FileSize'];
                        $this->imagesRaw->push(
                            ArrayData::create($intel)
                        );
                    }
                    $count++;
                }
            }
        }
        return $this->imagesRaw;
    }

    protected function getDataAboutOneFile(string $absoluteLocation, ?bool $fileExists): array
    {
        $class = self::ONE_FILE_INFO_CLASS;
        $obj = new $class($absoluteLocation, $fileExists);

        return $obj->toArray();
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

    protected function createFormField(string $name, string $title, $value, ?array $list = [])
    {
        $listCount = count($list);
        if ($listCount === 0) {
            $type = HiddenField::class;
        } elseif ($name === 'extensions') {
            $type = CheckboxSetField::class;
        } elseif ($listCount < 13) {
            $type = OptionsetField::class;
        } else {
            $type = DropdownField::class;
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
        $list = range(1, $this->getNumberOfPages());
        return array_combine($list, $list);
    }

    protected function getNumberOfPages(): Int
    {
        return ceil($this->totalFileCount / $this->limit);
    }

    protected function getLimitList(): array
    {
        $step = 250;
        $array = [];
        for ($i = $step; ($i - $step) < $this->totalFileCount; $i += $step) {
            if($i > $this->limit && ! isset($array[$this->limit])) {
                $array[$this->limit] = $this->limit;
            }
            $array[$i] = $i;
        }
        if($i > $this->limit && ! isset($array[$this->limit])) {
            $array[$this->limit] = $this->limit;
        }
        return $array;
    }
}
