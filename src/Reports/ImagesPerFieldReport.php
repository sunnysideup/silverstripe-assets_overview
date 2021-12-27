<?php

namespace Sunnysideup\AssetsOverview\Reports;

use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Forms\OptionsetField;
use ReflectionClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldExportButton;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldPrintButton;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\CMSPreviewable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\SS_List;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;
use SilverStripe\View\ViewableData;

use SilverStripe\Reports\Report;
use Sunnysideup\AssetsOverview\Api\ImageFieldFinder;

/**
 * Base "abstract" class creating reports on your data.
 *
 * Creating reports
 * ================
 *
 * Creating a new report is a matter overloading a few key methods
 *
 *  {@link title()}: Return the title - i18n is your responsibility
 *  {@link description()}: Return the description - i18n is your responsibility
 *  {@link sourceQuery()}: Return a SS_List of the search results
 *  {@link columns()}: Return information about the columns in this report.
 *  {@link parameterFields()}: Return a FieldList of the fields that can be used to filter this
 *  report.
 *
 * If you wish to modify the report in more extreme ways, you could overload these methods instead.
 *
 * {@link getReportField()}: Return a FormField in the place where your report's TableListField
 * usually appears.
 * {@link getCMSFields()}: Return the FieldList representing the complete right-hand area of the
 * report, including the title, description, parameter fields, and results.
 *
 * Showing reports to the user
 * ===========================
 *
 * Right now, all subclasses of SS_Report will be shown in the ReportAdmin. In SS3 there is only
 * one place where reports can go, so this class is greatly simplifed from its version in SS2.
 *
 * @method SS_List|DataList sourceRecords($params = [], $sort = null, $limit = null) List of records to show for this report
 */
class ImagesPerFieldReport extends Report
{
    /**
     * This is the title of the report,
     * used by the ReportAdmin templates.
     *
     * @var string
     */
    protected $title = 'Images per field';

    /**
     * This is a description about what this
     * report does. Used by the ReportAdmin
     * templates.
     *
     * @var string
     */
    protected $description = 'Choose an image field and see what images have been added';

    /**
     * The class of object being managed by this report.
     * Set by overriding in your subclass.
     */
    protected $dataClass = DataObject::class;

    /**
     * A field that specifies the sort order of this report
     * @var int
     */
    protected $sort = 999;

    protected $classNameUsed = '';

    protected $fieldUsed = '';

    protected $typeUsed = '';

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->renameField('updatereport', 'Show Images');
        return $fields;
    }

    public function sourceRecords($params)
    {
        $classNameFieldComboString = $params['ClassNameFieldCombo'] ?? ',,';
        $situation = $params['Situation'] ?? '';
        list ($classNameUsed, $fieldUsed, $typeUsed) = explode(',', $classNameFieldComboString);

        if ($classNameUsed && $fieldUsed && class_exists($classNameUsed)) {
            // set variables
            $this->classNameUsed = $classNameUsed;
            $this->fieldUsed = $fieldUsed;
            $this->typeUsed = $typeUsed;
            $isSingularRel = $typeUsed === 'has_one' || $typeUsed === 'belongs_to' ? true : false;
            // create list
            $list =  $classNameUsed::get();
            if($situation) {
                if($situation === 'WithImage') {
                    $filterMethod = 'exclude';
                    $existsValue = true;
                } else {
                    $existsValue = false;
                    $filterMethod = 'filter';
                }
                if($isSingularRel) {
                    $field = $fieldUsed.'ID';
                    $list = $list->$filterMethod([$field => 0]);
                } else {
                    $list = $list->filterByCallBack(
                        function ($item) {
                            return (bool) $item->$fieldUsed()->exists() === $existsValue;
                        }
                    );
                }
            }
            return $list;
        }

        return SiteConfig::get()->filter(['ID' => 0]);
    }

    /**
     * Return the {@link DataQuery} that provides your report data.
     *
     * @param array $params
     * @return DataQuery
     */
    public function sourceQuery($params)
    {
        if (!$this->hasMethod('sourceRecords')) {
            throw new \RuntimeException(
                'Please override sourceQuery()/sourceRecords() and columns() or, if necessary, override getReportField()'
            );
        }

        return $this->sourceRecords($params, null, null)->dataQuery();
    }

    /**
     * Return a SS_List records for this report.
     *
     * @param array $params
     * @return SS_List
     */
    public function records($params)
    {
        if ($this->hasMethod('sourceRecords')) {
            return $this->sourceRecords($params, null, null);
        } else {
            $query = $this->sourceQuery($params);
            $results = ArrayList::create();
            foreach ($query->execute() as $data) {
                $class = $this->dataClass();
                $result = Injector::inst()->create($class, $data);
                $results->push($result);
            }
            return $results;
        }
    }

    public function columns()
    {
        return [
            'Title' => ['title' => 'Title', 'link' => 'CMSEditLInk'],
            $this->fieldUsed . '.CMSThumbnail' => 'Image',
        ];
    }

    /**
     * Return the data class for this report
     */
    public function dataClass()
    {
        return $this->dataClass;
    }


    /**
     * counts the number of objects returned
     * @param array $params - any parameters for the sourceRecords
     * @return int
     */
    public function getCount($params = array())
    {
        return count($this->getClassNameFieldCombos());
    }

    /////////////////////// UI METHODS ///////////////////////


    /**
     * Return the name of this report, which is used by the templates to render the name of the report in the report
     * tree, the left hand pane inside ReportAdmin.
     *
     * @return string
     */
    public function TreeTitle()
    {
        return $this->title();
    }

    /**
     * Return additional breadcrumbs for this report. Useful when this report is a child of another.
     *
     * @return ArrayData[]
     */
    public function getBreadcrumbs()
    {
        return [];
    }

    /**
     * Get source params for the report to filter by
     *
     * @return array
     */
    protected function getSourceParams()
    {
        $params = [];
        if (Injector::inst()->has(HTTPRequest::class)) {
            /** @var HTTPRequest $request */
            $request = Injector::inst()->get(HTTPRequest::class);
            $params = $request->param('filters') ?: $request->requestVar('filters') ?: [];
        }

        $this->extend('updateSourceParams', $params);

        return $params;
    }

    protected function parameterFields()
    {
        $params = new FieldList();

        $params->push(
            DropdownField::create(
                'ClassNameFieldCombo',
                'Classname and Field',
                $this->getClassNameFieldCombos()
            )->setEmptyString('--- select record list and image field ---')
        );
        $params->push(
            OptionsetField::create(
                'Situation',
                'Situation',
                [
                    'WithImage' => 'With Image(s)',
                    'Without' => 'Without Image(s)',
                ]
            )->setEmptyString('--- any ---')
        );

        return $params;
    }


    protected function getClassNameFieldCombos() : array
    {
        return (new ImageFieldFinder)->Fields();
    }
}
