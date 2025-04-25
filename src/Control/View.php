<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
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
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\ArrayData;
use SilverStripe\View\Requirements;
use SilverStripe\View\SSViewer;
use Sunnysideup\AssetsOverview\Api\AddAndRemoveFromDb;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;
use Sunnysideup\AssetsOverview\Files\OneFileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;

/**
 * Class \Sunnysideup\AssetsOverview\Control\View
 *
 * @property \Sunnysideup\AssetsOverview\Control\View $dataRecord
 * @method \Sunnysideup\AssetsOverview\Control\View data()
 * @mixin \Sunnysideup\AssetsOverview\Control\View
 */
class View extends ContentController implements Flushable
{
    use FilesystemRelatedTraits;

    public static function get_sorters()
    {
        return self::SORTERS;
    }

    public static function get_filters()
    {
        return self::FILTERS;
    }

    public static function get_displayers()
    {
        return self::DISPLAYERS;
    }


    protected static $allFilesProvider = null;

    /**
     * @var string
     */
    protected string $title = '';


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
     * @var bool
     */
    protected bool $dryRun = false;

    /**
     * @var string
     */
    protected string $sorter = 'byfolder';

    /**
     * @var string
     */
    protected string $filter = '';

    /**
     * @var string
     */
    protected string $displayer = 'thumbs';

    /**
     * @var array
     */
    protected array $allowedExtensions = [];

    /**
     * Defines methods that can be called directly.
     *
     * @var array
     */
    private static $allowed_actions = [
        'index' => 'ADMIN',
        'json' => 'ADMIN',
        'jsonfull' => 'ADMIN',
        'jsonone' => 'ADMIN',
        'sync' => 'ADMIN',
        'addtodb' => 'ADMIN',
        'removefromdb' => 'ADMIN',
    ];

    public static function flush()
    {
        AllFilesInfo::flushCache();
    }

    private static $url_segment = 'admin/assets-overview';

    public function Link($action = null)
    {
        $str = Director::absoluteURL(DIRECTORY_SEPARATOR . $this->config()->get('url_segment'));
        if ($action) {
            $str .= DIRECTORY_SEPARATOR . $action;
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
                $this->getAllFilesInfoProvider()->getTotalFileCountFiltered() . ' files / ' .
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

    public function getFilesAsArrayList(): ArrayList
    {
        return $this->getAllFilesInfoProvider()->getFilesAsArrayList();
    }

    public function getFilesAsArray(): array
    {
        return $this->getFilesAsArrayList()->toArray();
    }

    public function getFilesAsSortedArrayList(): ArrayList
    {
        return $this->getAllFilesInfoProvider()->getFilesAsSortedArrayList();
    }

    public function getTotalFileCountRaw(): string
    {
        return (string) number_format($this->getAllFilesInfoProvider()->getTotalFileCountRaw());
    }

    public function getTotalFileCountFilteredAndFormatted(): string
    {
        return (string) number_format($this->getAllFilesInfoProvider()->getTotalFileCountFiltered());
    }

    public function getTotalFileSizeFiltered(): string
    {
        return (string) $this->humanFileSize($this->getAllFilesInfoProvider()->getTotalFileSizeFiltered());
    }

    public function getTotalFileSizeRaw(): string
    {
        return (string) $this->humanFileSize($this->getAllFilesInfoProvider()->getTotalFileSizesRaw());
    }

    public function index($request)
    {
        if ('rawlistfull' === $this->displayer) {
            $this->addMapToItems();
        }

        // if (false === AllFilesInfo::loadedFromCache()) {
        //     $url = $_SERVER['REQUEST_URI'];
        //     $url = str_replace('flush=', 'previousflush=', $url);

        //     $js = '<script>window.location.href = \'' . $url . '\';</script>';
        //     return 'go to <a href="' . $url . '">' . $url . '</a> if this page does not autoload';
        // }


        return $this->renderWith('AssetsOverview');
    }

    public function json($request)
    {
        return $this->sendJSON($this->getRawData());
    }

    public function jsonfull($request)
    {
        $array = [];

        foreach ($this->getFilesAsArray() as $item) {
            $array[] = $item->toMap();
        }

        return $this->sendJSON($array);
    }

    public function jsonone($request)
    {
        $array = [];
        $location = $this->request->getVar('path');
        $obj = OneFileInfo::inst($location);
        $obj->setNoCache(true);

        return $this->sendJSON($obj->toArray());
    }

    public function sync()
    {
        $obj = $this->getAddAndRemoveFromDbClass();
        foreach ($this->getFilesAsArray() as $item) {
            $obj->run($item->toMap());
        }
    }

    public function addtodb()
    {
        $obj = $this->getAddAndRemoveFromDbClass();
        foreach ($this->getFilesAsArray() as $item) {
            $obj->run($item->toMap(), 'add');
        }
    }

    public function removefromdb()
    {
        $obj = $this->getAddAndRemoveFromDbClass();
        foreach ($this->getFilesAsArrayList()->toArray() as $item) {
            $obj->run($item->toMap(), 'remove');
        }
    }

    protected function getAddAndRemoveFromDbClass(): AddAndRemoveFromDb
    {
        $obj = Injector::inst()->get(AddAndRemoveFromDb::class);
        return $obj->setIsDryRun($this->dryRun);
    }

    public function addMapToItems()
    {
        $this->isThumbList = false;
        foreach ($this->getFilesAsSortedArrayList() as $group) {
            foreach ($group->Items as $item) {
                $map = $item->toMap();
                $item->FullFields = ArrayList::create();
                foreach ($map as $key => $value) {
                    if (false === $value) {
                        $value = 'no';
                    }

                    if (true === $value) {
                        $value = 'yes';
                    }

                    $item->FullFields->push(ArrayData::create(['Key' => $key, 'Value' => $value]));
                }
            }
        }
    }

    //#############################################
    // FORM
    //#############################################
    public function Form()
    {
        return $this->getForm();
    }

    protected function init()
    {
        parent::init();
        if (! Permission::check('ADMIN')) {
            return Security::permissionFailure($this);
        }

        Requirements::clear();
        ini_set('memory_limit', '1024M');
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo(7200);
        SSViewer::config()->set('theme_enabled', false);
        Versioned::set_stage(Versioned::DRAFT);
        $this->getGetVariables();
        $this->getAllFilesInfoProvider();
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
        $filter = $this->request->getVar('filter');
        if ($filter) {
            $this->filter = $filter;
        }

        $sorter = $this->request->getVar('sorter');
        if ($sorter) {
            $this->sorter = $sorter;
        }

        $displayer = $this->request->getVar('displayer');
        if ($displayer) {
            $this->displayer = $displayer;
        }

        $extensions = $this->request->getVar('extensions');
        if ($extensions) {
            if (! is_array($extensions)) {
                $extensions = [$extensions];
            }

            $this->allowedExtensions = $extensions;
            //make sure all are valid!
            $this->allowedExtensions = array_filter($this->allowedExtensions);
        }

        $limit = $this->request->getVar('limit');
        if ($limit) {
            $this->limit = $limit;
        }

        $this->pageNumber = ($this->request->getVar('page') ?: 1);
        $this->startLimit = $this->limit * ($this->pageNumber - 1);
        $this->endLimit = $this->limit * ($this->pageNumber + 0);
        $this->getAllFilesInfoProvider();
    }

    protected function sendJSON($data)
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        if ($this->request->getVar('download')) {
            return HTTPRequest::send_file($json, 'files.json', 'text/json');
        }
        $response = (new HTTPResponse($json));
        $response->addHeader('Content-Type', 'application/json; charset="utf-8"');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('cache-control', 'no-cache, no-store, must-revalidate');
        $response->addHeader('Access-Control-Allow-Origin', '*');
        $response->addHeader('Expires', 0);
        HTTPCacheControlMiddleware::singleton()
            ->disableCache()
        ;
        $response->output();
        die();
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
                // TextField::create('compare', 'Compare With')->setDescription('add a link to a comparison file - e.g. http://oldsite.com/admin/assets-overview/test.json'),
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
        if (0 === $listCount) {
            $type = HiddenField::class;
        } elseif ('limit' === $name || 'page' === $name) {
            $type = DropdownField::class;
        } elseif ('extensions' === $name) {
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
        $list = array_filter($this->getAllFilesInfoProvider()->getAvailableExtensions());
        $list = ['n/a' => 'n/a'] + $list;
        return $list;
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

    protected function getNumberOfPages(): int
    {
        return ceil($this->getAllFilesInfoProvider()->getTotalFileCountFiltered() / $this->limit);
    }

    protected function getLimitList(): array
    {
        $step = 100;
        $array = [];
        $i = 0;
        $totalRaw = (int)  $this->getAllFilesInfoProvider()->getTotalFileCountRaw();
        $totalFiltered = (int)  $this->getAllFilesInfoProvider()->getTotalFileCountFiltered();
        if ($totalRaw > $step) {
            for ($i = $step; ($i - $step) < $totalFiltered; $i += $step) {
                if ($i > $this->limit && ! isset($array[$this->limit])) {
                    $array[$this->limit] = $this->limit;
                }

                $array[$i] = $i;
            }
        }

        return $array;
    }



    protected function getRawData(): array
    {
        return $this->getAllFilesInfoProvider()->toArray();
    }

    protected function getAllFilesInfoProvider(): AllFilesInfo
    {
        if (!self::$allFilesProvider) {
            /** @var AllFilesInfo self::$allFilesProvider */
            self::$allFilesProvider = AllFilesInfo::inst();
            self::$allFilesProvider
                ->setFilters(self::get_filters())
                ->setSorters(self::get_sorters())
                ->setFilter($this->filter)
                ->setAllowedExtensions($this->allowedExtensions)
                ->setSorter($this->sorter)
                ->setLimit($this->limit)
                ->setPageNumber($this->pageNumber)
                ->setStartLimit($this->startLimit)
                ->setEndLimit($this->endLimit)
                ->setDisplayer($this->displayer)
                ->getFilesAsArrayList();
            while ($this->startLimit > $this->filesAsArrayList->count()) {
                $this->pageNumber--;
                $this->startLimit = $this->limit * ($this->pageNumber - 1);
                $this->endLimit = $this->limit * ($this->pageNumber + 0);
            }
        }
        return self::$allFilesProvider;
    }


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
            'Sort' => 'IsImage',
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
        'byextensionallowed' => [
            'Title' => 'Extension not allowed',
            'Field' => 'ErrorInvalidExtension',
            'Values' => [1, true],
        ],
        'by3to4error' => [
            'Title' => 'Potential SS4 migration error',
            'Field' => 'ErrorInSs3Ss4Comparison',
            'Values' => [1, true],
        ],
    ];
    /**
     * @var array<string, string>
     */
    private const DISPLAYERS = [
        'thumbs' => 'Thumbnails',
        'rawlist' => 'File List',
        'rawlistfull' => 'Raw Data',
    ];
}
