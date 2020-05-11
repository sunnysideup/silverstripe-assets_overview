<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Flushable;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;
use Sunnysideup\AssetsOverview\Files\OneFileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;

class View extends ContentController implements Flushable
{
    use FilesystemRelatedTraits;

    private const ALL_FILES_INFO_CLASS = AllFilesInfo::class;

    private const ONE_FILE_INFO_CLASS = OneFileInfo::class;

    private const SORTERS = [
        'byfolder' => [
            'Title' => 'Folder',
            'Sort' => 'PathFolderFromAssets',
            'Group' => 'PathFolderFromAssets',
        ],
        'byfilename' => [
            'Title' => 'Filename',
            'Sort' => 'PathFileName',
            'Group' => 'PathFileNameFirstLetter',
        ],
        'bydbtitle' => [
            'Title' => 'Database Title',
            'Sort' => 'DBTitle',
            'Group' => 'DBTitleFirstLetter',
        ],
        'byfilesize' => [
            'Title' => 'Filesize',
            'Sort' => 'PathFileSize',
            'Group' => 'HumanFileSizeRounded',
        ],
        'bylastedited' => [
            'Title' => 'Last Edited',
            'Sort' => 'DBLastEditedTS',
            'Group' => 'DBLastEdited',
        ],
        'byextension' => [
            'Title' => 'PathExtension',
            'Sort' => 'PathExtensionAsLower',
            'Group' => 'PathExtensionAsLower',
        ],
        'byisimage' => [
            'Title' => 'Image vs Other Files',
            'Sort' => 'ImageIsImage',
            'Group' => 'HumanImageIsImage',
        ],
        'byclassname' => [
            'Title' => 'Class Name',
            'Sort' => 'DBClassName',
            'Group' => 'DBClassName',
        ],
        'bydimensions' => [
            'Title' => 'Dimensions (small to big)',
            'Sort' => 'ImagePixels',
            'Group' => 'HumanImageDimensions',
        ],
        'byratio' => [
            'Title' => 'ImageRatio',
            'Sort' => 'ImageRatio',
            'Group' => 'ImageRatio',
        ],
    ];

    private const FILTERS = [
        'byanyerror' => [
            'Title' => 'Any Error',
            'Field' => 'ErrorHasAnyError',
            'Values' => [1, true],
        ],
        'byfilesystemstatus' => [
            'Title' => 'Not in filesystem',
            'Field' => 'ErrorIsInFileSystem',
            'Values' => [1, true],
        ],
        'bymissingfromdatabase' => [
            'Title' => 'Not in database',
            'Field' => 'ErrorDBNotPresent',
            'Values' => [1, true],
        ],
        'bymissingfromlive' => [
            'Title' => 'Not on live site',
            'Field' => 'ErrorDBNotPresentLive',
            'Values' => [1, true],
        ],
        'bymissingfromstaging' => [
            'Title' => 'Not on draft site',
            'Field' => 'ErrorDBNotPresentStaging',
            'Values' => [1, true],
        ],
        'bydraftonly' => [
            'Title' => 'In draft only (not on live)',
            'Field' => 'ErrorInDraftOnly',
            'Values' => [1, true],
        ],
        'byliveonly' => [
            'Title' => 'On live only (not in draft)',
            'Field' => 'ErrorNotInDraft',
            'Values' => [1, true],
        ],
        'byfoldererror' => [
            'Title' => 'Folder error',
            'Field' => 'ErrorParentID',
            'Values' => [1, true],
        ],
        'bydatabaseerror' => [
            'Title' => 'Error in file name',
            'Field' => 'ErrorInFilename',
            'Values' => [1, true],
        ],
        'byextensionerror' => [
            'Title' => 'UPPER/lower case error in file type',
            'Field' => 'ErrorExtensionMisMatch',
            'Values' => [1, true],
        ],
        'by3to4error' => [
            'Title' => 'Potential SS4 migration error',
            'Field' => 'ErrorInSs3Ss4Comparison',
            'Values' => [1, true],
        ],

    ];

    private const DISPLAYERS = [
        'thumbs' => 'Thumbnails',
        'rawlist' => 'File List',
        'rawlistfull' => 'Raw Data',
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
    protected $totalFileCountRaw = 0;

    /**
     * @var int
     */
    protected $totalFileCountFiltered = 0;

    /**
     * @var int
     */
    protected $totalFileSizeFiltered = 0;

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
    protected $sorter = 'byfolder';

    /**
     * @var string
     */
    protected $filter = '';

    /**
     * @var string
     */
    protected $displayer = 'thumbs';

    /**
     * @var array
     */
    protected $allowedExtensions = [];

    /**
     * Defines methods that can be called directly
     * @var array
     */
    private static $allowed_actions = [
        'index' => 'ADMIN',
        'json' => 'ADMIN',
        'jsonfull' => 'ADMIN',
        'fix' => 'ADMIN',
    ];

    public static function flush()
    {
        AllFilesInfo::flushCache();
    }

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
        $this->getSortStatement();
        $this->getFilterStatement();
        $this->getPageStatement();

        if ($this->hasFilter()) {
            $filterStatement = '' .
                $this->getTotalFileCountFiltered() . ' files / ' .
                $this->getTotalFileSizeFiltered();
        } else {
            $filterStatement =
                $this->getTotalFileCountRaw() . ' / ' .
                $this->getTotalFileSizeRaw();
        }

        return DBField::create_field(
            'HTMLText',
            'Found ' . $filterStatement
        );
    }

    public function getSubTitle(): string
    {
        $array = array_filter(
            [
                $this->getSortStatement(),
                $this->getFilterStatement(),
                $this->getPageStatement(),
                $this->getTotalsStatement(),
            ]
        );

        return DBField::create_field(
            'HTMLText',
            '- ' . implode('<br /> - ', $array)
        );
    }

    public function getSortStatement(): string
    {
        return '<strong>sorted by</strong>: ' . self::SORTERS[$this->sorter]['Title'] ?? 'ERROR IN SORTER';
    }

    public function getFilterStatement(): string
    {
        $filterArray = array_filter(
            [
                self::FILTERS[$this->filter]['Title'] ?? '',
                implode(', ', $this->allowedExtensions),
            ]
        );

        return count($filterArray) ? '<strong>filtered for</strong>: ' . implode(', ', $filterArray) : '';
    }

    public function getPageStatement(): string
    {
        return $this->getNumberOfPages() > 1 ?
            '<strong>page</strong>: ' . $this->pageNumber . ' of ' . $this->getNumberOfPages() . ', showing file ' . ($this->startLimit + 1) . ' to ' . $this->endLimit
            :
            '';
    }

    public function getDisplayer(): string
    {
        return $this->displayer;
    }

    public function getfilesAsArrayList(): ArrayList
    {
        return $this->filesAsArrayList;
    }

    public function getfilesAsSortedArrayList(): ArrayList
    {
        return $this->filesAsSortedArrayList;
    }

    public function getTotalFileCountRaw(): string
    {
        return (string) number_format($this->totalFileCountRaw);
    }

    public function getTotalFileCountFiltered(): string
    {
        return (string) number_format($this->totalFileCountFiltered);
    }

    public function getTotalFileSizeFiltered(): string
    {
        return (string) $this->humanFileSize($this->totalFileSizeFiltered);
    }

    public function getTotalFileSizeRaw(): string
    {
        return (string) $this->humanFileSize(AllFilesInfo::getTotalFileSizesRaw());
    }

    public function init()
    {
        parent::init();
        if (! Permission::check('ADMIN')) {
            return Security::permissionFailure($this);
        }
        Requirements::clear();
        ini_set('memory_limit', '1024M');
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo(7200);
        SSViewer::config()->update('theme_enabled', false);
        Versioned::set_stage(Versioned::DRAFT);
        $this->getGetVariables();
    }

    public function index($request)
    {
        $this->setFilesAsSortedArrayList();
        if ($this->displayer === 'rawlistfull') {
            $this->addMapToItems();
        }
        if (AllFilesInfo::loadedFromCache() === false) {
            $url = $_SERVER['REQUEST_URI'];
            $url = str_replace('flush=', 'previousflush=', $url);
            die('<script>window.location = "' . $url . '";</script>go to ' . $url . ' if this page does not autoload');
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

    public function addMapToItems()
    {
        $this->isThumbList = false;
        foreach ($this->filesAsSortedArrayList as $group) {
            foreach ($group->Items as $item) {
                $map = $item->toMap();
                $item->FullFields = ArrayList::create();
                foreach ($map as $key => $value) {
                    if ($value === false) {
                        $value = 'no';
                    }
                    if ($value === true) {
                        $value = 'yes';
                    }
                    $item->FullFields->push(ArrayData::create(['Key' => $key, 'Value' => $value]));
                }
            }
        }
    }

    ##############################################
    # FORM
    ##############################################
    public function Form()
    {
        return $this->getForm();
    }

    protected function getTotalsStatement()
    {
        return $this->hasFilter() ? '<strong>Totals</strong>: ' .
            $this->getTotalFileCountRaw() . ' files / ' . $this->getTotalFileSizeRaw()
            : '';
    }

    protected function hasFilter(): bool
    {
        return $this->filter || count($this->allowedExtensions);
    }

    protected function getGetVariables()
    {
        if ($filter = $this->request->getVar('filter')) {
            $this->filter = $filter;
        }
        if ($sorter = $this->request->getVar('sorter')) {
            $this->sorter = $sorter;
        }
        if ($displayer = $this->request->getVar('displayer')) {
            $this->displayer = $displayer;
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

    protected function sendJSON($data)
    {
        $this->response->addHeader('Content-Type', 'application/json');
        $fileData = json_encode($data, JSON_PRETTY_PRINT);
        if ($this->request->getVar('download')) {
            return HTTPRequest::send_file($fileData, 'files.json', 'text/json');
        }
        return $fileData;
    }

    protected function setfilesAsSortedArrayList()
    {
        if ($this->filesAsSortedArrayList === null) {
            $sortField = self::SORTERS[$this->sorter]['Sort'];
            $headerField = self::SORTERS[$this->sorter]['Group'];
            //done only if not already done ...
            $this->setFilesAsArrayList();
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
                    $count++;
                } elseif ($count >= $this->endLimit) {
                    break;
                }
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
            $this->totalFileCountRaw = AllFilesInfo::getTotalFilesCount();
            $this->filesAsArrayList = ArrayList::create();
            $filterFree = true;
            $filterField = null;
            $filterValues = null;
            if (isset(self::FILTERS[$this->filter])) {
                $filterFree = false;
                $filterField = self::FILTERS[$this->filter]['Field'];
                $filterValues = self::FILTERS[$this->filter]['Values'];
            }
            foreach ($rawArray as $absoluteLocation => $fileExists) {
                if ($this->isPathWithAllowedExtension($absoluteLocation)) {
                    $intel = $this->getDataAboutOneFile($absoluteLocation, $fileExists);
                    if ($filterFree || in_array($intel[$filterField], $filterValues, 1)) {
                        $this->totalFileCountFiltered++;
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

    protected function getForm(): Form
    {
        $fieldList = FieldList::create(
            [
                $this->createFormField('sorter', 'Sort by', $this->sorter, $this->getSorterList()),
                $this->createFormField('filter', 'Filter for errors', $this->filter, $this->getFilterList()),
                $this->createFormField('extensions', 'Filter by extensions', $this->allowedExtensions, $this->getExtensionList()),
                $this->createFormField('displayer', 'Displayed by', $this->displayer, $this->getDisplayerList()),
                $this->createFormField('limit', 'Items per page', $this->limit, $this->getLimitList()),
                $this->createFormField('page', 'Page number', $this->pageNumber, $this->getPageNumberList()),
                // TextField::create('compare', 'Compare With')->setDescription('add a link to a comparison file - e.g. http://oldsite.com/assets-overview/test.json'),
            ]
        );
        $actionList = FieldList::create(
            [
                FormAction::create('index', 'Update File List'),
            ]
        );

        $form = Form::create($this, 'index', $fieldList, $actionList);
        $form->setFormMethod('GET', true);
        $form->disableSecurityToken();

        return $form;
    }

    protected function createFormField(string $name, string $title, $value, ?array $list = [])
    {
        $listCount = count($list);
        if ($listCount === 0) {
            $type = HiddenField::class;
        } elseif ($name === 'limit' || $name === 'page') {
            $type = DropdownField::class;
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
        // $field->setAttribute('onchange', 'this.form.submit()');

        return $field;
    }

    protected function getSorterList(): array
    {
        $array = [];
        foreach (self::SORTERS as $key => $data) {
            $array[$key] = $data['Title'];
        }

        return $array;
    }

    protected function getFilterList(): array
    {
        $array = ['' => '-- no filter --'];
        foreach (self::FILTERS as $key => $data) {
            $array[$key] = $data['Title'];
        }

        return $array;
    }

    protected function getDisplayerList(): array
    {
        return self::DISPLAYERS;
    }

    protected function getExtensionList(): array
    {
        return AllFilesInfo::getAvailableExtensions();
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
        return ceil($this->totalFileCountFiltered / $this->limit);
    }

    protected function getLimitList(): array
    {
        $step = 100;
        $array = [];
        $i = 0;
        if ($this->totalFileCountRaw > $step) {
            for ($i = $step; ($i - $step) < $this->totalFileCountFiltered; $i += $step) {
                if ($i > $this->limit && ! isset($array[$this->limit])) {
                    $array[$this->limit] = $this->limit;
                }
                $array[$i] = $i;
            }
        }
        return $array;
    }
}
