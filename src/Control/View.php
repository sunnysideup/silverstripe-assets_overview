<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\SSViewer;
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
            'Sort' => 'PathFromAssetsFolder',
            'Group' => 'PathFromAssetsFolderFolderOnly',
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
            'Sort' => 'IsInDatabaseSummary',
            'Group' => 'HumanIsInDatabaseSummary',
        ],
        'bydatabaseerror' => [
            'Title' => 'Database Error',
            'Sort' => 'ErrorInFilenameCase',
            'Group' => 'HumanErrorInFilenameCase',
        ],
        'by3to4error' => [
            'Title' => 'Migration to SS4 errors',
            'Sort' => 'ErrorInSs3Ss4Comparison',
            'Group' => 'HumanErrorInSs3Ss4Comparison',
        ],
        'byfoldererror' => [
            'Title' => 'Folder Error',
            'Sort' => 'ErrorParentID',
            'Group' => 'HumanErrorParentID',
        ],
        'byfilesystemstatus' => [
            'Title' => 'Filesystem Status',
            'Sort' => 'IsInFileSystem',
            'Group' => 'HumanIsInFileSystem',
        ],
        'byisimage' => [
            'Title' => 'Image vs Other Files',
            'Sort' => 'IsImage',
            'Group' => 'HumanIsImage',
        ],
        'byclassname' => [
            'Title' => 'Class Name',
            'Sort' => 'ClassName',
            'Group' => 'ClassName',
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
            'Sort' => '',
            'Group' => '',
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
    protected $filesAsArrayList = null;

    /**
     * @var ArrayList|null
     */
    protected $filesAsSortedArrayList = null;

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

    /**
     * Defines methods that can be called directly
     * @var array
     */
    private static $allowed_actions = [
        'index' => 'ADMIN',
        'json' => 'ADMIN',
        'jsonfull' => 'ADMIN',
    ];

    public function Link($action = null)
    {
        $str = Director::absoluteURL(DIRECTORY_SEPARATOR . 'assets-overview' . DIRECTORY_SEPARATOR);
        if ($action) {
            $str = $action . DIRECTORY_SEPARATOR;
        }
        return $str;
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

    public function getfilesAsArrayList(): ArrayList
    {
        return $this->filesAsArrayList;
    }

    public function getfilesAsSortedArrayList(): ArrayList
    {
        return $this->filesAsSortedArrayList;
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
        SSViewer::config()->update('theme_enabled', false);
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
            if ($actionData['Sort'] && $actionData['Group']) {
                $this->setFilesAsSortedArrayList($actionData['Sort'], $actionData['Group']);
            }
            if (! empty($actionData['Method'])) {
                $this->{$actionData['Method']}();
            }
        } else {
            user_error('Could not find filter');
        }

        return $this->renderWith('AssetsOverview');
    }

    public function json($request)
    {
        return $this->sendJSON($this->getRawData());
    }

    public function jsonfull($request)
    {
        $array = [];
        $this->setFilesAsArrayList();
        foreach ($this->filesAsArrayList->toArray() as $item) {
            $array[] = $item->toMap();
        }
        return $this->sendJSON($array);
    }

    public function workoutRawList()
    {
        $this->isThumbList = false;
        foreach ($this->filesAsSortedArrayList as $group) {
            foreach ($group->Items as $item) {
                $item->HTML = '<li>' . $item->PathFromAssetsFolder . '</li>';
            }
        }
        return [];
    }

    public function workoutRawListFull()
    {
        $this->isThumbList = false;
        foreach ($this->filesAsSortedArrayList as $group) {
            foreach ($group->Items as $item) {
                $map = $item->toMap();
                ksort($map);
                $item->HTML = '
                    <li>
                        <strong>' . $item->PathFromAssetsFolder . '</strong>
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
                TextField::create('compare', 'Compare With')->setDescription('add a link to a comparison file - e.g. http://oldsite.com/assets-overview/test.json'),
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

    protected function sendJSON($data)
    {
        $this->response->addHeader('Content-Type', 'application/json');
        $fileData = json_encode($data, JSON_PRETTY_PRINT);
        if ($this->request->getVar('download')) {
            return HTTPRequest::send_file($fileData, 'files.json', 'text/json');
        }
        return $fileData;
    }

    protected function workOutSimilarity()
    {
        set_time_limit(240);
        $engine = new CompareImages();
        $this->setFilesAsArrayList();
        $a = clone $this->filesAsArrayList;
        $b = clone $this->filesAsArrayList;
        $c = clone $this->filesAsArrayList;
        $alreadyDone = [];
        foreach ($a as $file) {
            $nameOne = $file->Path;
            $nameOneFromAssets = $file->PathFromAssetsFolder;
            if (! in_array($nameOne, $alreadyDone, true)) {
                $easyFind = false;
                $sortArray = [];
                foreach ($b as $compareImage) {
                    $nameTwo = $compareImage->Path;
                    if ($nameOne !== $nameTwo) {
                        $fileNameTest = $file->FileName && $file->FileName === $compareImage->FileName;
                        $fileSizeTest = $file->FileSize > 0 && $file->FileSize === $compareImage->FileSize;
                        if ($fileNameTest || $fileSizeTest) {
                            $easyFind = true;
                            $alreadyDone[$nameOne] = $nameOneFromAssets;
                            $alreadyDone[$compareImage->Path] = $nameOneFromAssets;
                        } elseif ($easyFind === false && $file->IsImage) {
                            if ($file->Ratio === $compareImage->Ratio && $file->Ratio > 0) {
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
                        $alreadyDone[$file->Path] = '[N/A]';
                    }
                }
            }
        }
        foreach ($this->filesAsArrayList as $file) {
            $file->MostSimilarTo = $alreadyDone[$file->Path] ?? '[N/A]';
        }
        $this->setFilesAsSortedArrayList('MostSimilarTo', 'MostSimilarTo');
    }

    protected function setfilesAsSortedArrayList($sortField, $headerField)
    {
        if ($this->filesAsSortedArrayList === null) {
            //done only if not already done ...
            $this->setFilesAsArrayList();
            $this->filesAsSortedArrayList = ArrayList::create();
            $this->filesAsArrayList = $this->filesAsArrayList->Sort($sortField);

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
                $innerArray->push($file);
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
        if ($arrayList->count()) {
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

    protected function setFilesAsArrayList(): ArrayList
    {
        if ($this->filesAsArrayList === null) {
            $rawArray = $this->getRawData();
            //prepare loop
            $this->totalFileCount = count($rawArray);
            $this->filesAsArrayList = ArrayList::create();
            $count = 0;
            foreach ($rawArray as $absoluteLocation => $fileExists) {
                if ($this->isPathWithAllowedExtension($absoluteLocation)) {
                    if ($count >= $this->startLimit && $count < $this->endLimit) {
                        $intel = $this->getDataAboutOneFile($absoluteLocation, $fileExists);
                        $this->availableExtensions[$intel['ExtensionAsLower']] = $intel['ExtensionAsLower'];
                        $this->totalFileSize += $intel['FileSize'];
                        $this->filesAsArrayList->push(
                            ArrayData::create($intel)
                        );
                    }
                    $count++;
                }
            }
        }

        return $this->filesAsArrayList;
    }

    protected function getRawData(): array
    {
        //get data
        $class = self::ALL_FILES_INFO_CLASS;
        $obj = new $class($this->getAssetsBaseFolder());

        return $obj->toArray();
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
        } elseif ($listCount < 20) {
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
        $list = array_combine($list, $list);
        $list[(string) $this->pageNumber] = (string) $this->pageNumber;
        if (count($list) < 2) {
            return [];
        }
        return $list;
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
            if ($i > $this->limit && ! isset($array[$this->limit])) {
                $array[$this->limit] = $this->limit;
            }
            $array[$i] = $i;
        }
        if ($i > $this->limit && ! isset($array[$this->limit])) {
            $array[$this->limit] = $this->limit;
        }
        return $array;
    }
}
