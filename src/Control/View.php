<?php

namespace Sunnysideup\AssetsOverview\Control;
use SilverStripe\Control\Director;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;
use SilverStripe\Assets\Image;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Assets\Folder;
use SilverStripe\CMS\Controllers\ContentController;


class View extends ContentController
{
    private static $allowed_actions = array(
        'byfolder' => 'ADMIN',
        'byfilename' => 'ADMIN',
        'bydimensions' => 'ADMIN',
        'byratio' => 'ADMIN',
        'byfilesize' => 'ADMIN',
        'byextension' => 'ADMIN',
        'bydatabasestatus' => 'ADMIN',
        'bylastedited' => 'ADMIN',
        'bysimilarity' => 'ADMIN'
    );

    protected $imagesRaw = null;
    protected $title = '';
    protected $imagesSorted = null;

    public function Link($action = null)
    {
        $base = Director::absoluteBaseURL();
        $link = $base . DIRECTORY_SEPARATOR . 'assetsoverview'  . DIRECTORY_SEPARATOR;
        if ($action) {
            $link .= $action . DIRECTORY_SEPARATOR;
        }

        return $link;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function getImagesRaw()
    {
        return $this->imagesRaw;
    }

    public function getImagesSorted()
    {
        return $this->imagesSorted;
    }

    public function init()
    {
        parent::init();
        if (!Permission::check("ADMIN")) {
            return Security::permissionFailure($this);
        }
        Requirements::clear();
    }

    public function index($request)
    {
        return $this->renderWith('AssetsOverview');
    }

    public function byfolder($request)
    {
        $this->title = 'By Folder Name';
        $this->createProperList('FolderNameShort', 'FolderNameShort');

        return $this->renderWith('AssetsOverview');
    }

    public function byfilename($request)
    {
        $this->title = 'By File Name';
        $this->createProperList('FileName', 'FirstLetter');

        return $this->renderWith('AssetsOverview');
    }


    public function byfilesize($request)
    {
        $this->title = 'By File Size';
        $this->createProperList('FileSize', 'HumanFileSizeRounded');

        return $this->renderWith('AssetsOverview');
    }

    public function byextension($request)
    {
        $this->title = 'By File Type';
        $this->createProperList(Extension::class, Extension::class);

        return $this->renderWith('AssetsOverview');
    }

    public function bydimensions($request)
    {
        $this->title = 'By Dimensions';
        $this->createProperList('Pixels', 'HumanImageDimensions');
        return $this->renderWith('AssetsOverview');
    }

    public function byratio($request)
    {
        $this->title = 'By Dimensions';
        $this->createProperList('Ratio', 'Ratio');

        return $this->renderWith('AssetsOverview');
    }

    public function bydatabasestatus($request)
    {
        $this->title = 'By Database Status';
        $this->createProperList('IsInDatabase', 'HumanIsInDatabase');

        return $this->renderWith('AssetsOverview');
    }

    public function bylastedited($request)
    {
        $this->title = 'Last Edited';
        $this->createProperList('LastEditedTS', 'LastEdited');

        return $this->renderWith('AssetsOverview');
    }

    public function bysimilarity($request)
    {
        require_once(__DIR__.'../../../compare-images-master/image.compare.class.php');
        $this->title = 'Level of Similarity';
        $engine = new compareImages();
        $this->buildImagesRaw();
        $alreadyDone = [];
        foreach ($this->imagesRaw as $key => $image) {
            $nameOne = $image->Path;
            if (! in_array($nameOne, $alreadyDone)) {
                $sortArray = [];
                foreach ($this->imagesRaw as $compareImage) {
                    $nameTwo = $compareImage->Path;
                    if ($nameOne !== $nameTwo) {
                        if ($image->FileName === $compareImage->FileName) {
                            $image->MostSimilarTo = $image->PathFromAssets;
                            $compareImage->MostSimilarTo = $image->PathFromAssets;
                            $alreadyDone[$nameTwo] = [$nameTwo];
                            continue 2;
                        } else {
                            if ($image->Ratio == $compareImage->Ratio) {
                                $score = $engine->compare($nameOne, $nameTwo);
                                $sortArray[$nameTwo] = $score;
                            }
                        }
                    }
                }
                if (count($sortArray)) {
                    asort($sortArray);
                    reset($sortArray);
                    $mostSimilarKey = key($sortArray);
                    foreach ($this->imagesRaw as $findImage) {
                        if ($findImage->Path === $mostSimilarKey) {
                            $alreadyDone[$mostSimilarKey] = $mostSimilarKey;
                            $image->MostSimilarTo = $image->Path;
                            $findImage->MostSimilarTo = $image->Path;
                        }
                    }
                } else {
                    $image->MostSimilarTo = 'N/A';
                }
            }
        }
        $this->createProperList('MostSimilarTo', 'MostSimilarTo');

        return $this->renderWith('AssetsOverview');
    }

    protected function createProperList($sortField, $headerField)
    {
        if ($this->imagesSorted === null) {
            //done only if not already done ...
            $this->buildImagesRaw();
            $this->imagesRaw = $this->imagesRaw->Sort($sortField);
            $this->imagesSorted = ArrayList::create();

            $innerArray = ArrayList::create();
            $prevHeader = '';

            foreach ($this->imagesRaw as $image) {
                $newHeader = $image->$headerField;
                if ($newHeader !== $prevHeader) {
                    $this->addToSortedArray(
                        $prevHeader,
                        $innerArray
                    );
                    $prevHeader = $newHeader;
                    unset($innerArray);
                    $innerArray = ArrayList::create();
                }
                $innerArray->push($image);
            }
            $this->addToSortedArray(
                $newHeader,
                $innerArray
            );
        }
        return $this->imagesSorted;
    }

    protected function addToSortedArray($header, $arrayList)
    {
        if ($arrayList->count()) {
            if (! $header) {
                $header = 'ERROR';
            }
            $count = $this->imagesSorted->count();
            $this->imagesSorted->push(
                ArrayData::create(
                    [
                        'Number' => $count,
                        'SubTitle' => $header,
                        'Items' => $arrayList
                    ]
                )
            );
        }
    }

    protected function isImage($filename) : bool
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return in_array(strtolower($extension), ['jpg', 'gif', 'png', 'svg']);
    }

    protected function humanFileSize($bytes, $decimals = 0) : string
    {
        $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }

    protected function getExtension($path) : string
    {
        $basename = basename($path);
        return substr($basename, strlen(explode('.', $basename)[0]) + 1);
    }

    protected function assetsBaseDir() : string
    {
        return Director::baseFolder() . DIRECTORY_SEPARATOR . ASSETS_DIR;
    }

    protected function buildImagesRaw()
    {
        if ($this->imagesRaw === null) {
            $this->imagesRaw = ArrayList::create();

            $path = $this->assetsBaseDir();

            $arrayRaw = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path), \RecursiveIteratorIterator::SELF_FIRST);
            $array = [];
            $count = 0;
            foreach ($arrayRaw as $src) {
                $count++;
                $extension = $this->getExtension($src);
                if (strpos($src, '_resampled/') === false) {
                    if (in_array($extension, ['jpg', 'png', 'gif'])) {
                        $relativeSrc = str_replace(__DIR__.'/assets/', '', $src);
                        $array[$relativeSrc] = $src;
                    }
                }
            }
            $baseFolder = Director::baseFolder();
            $assetsBaseFolder = $this->assetsBaseDir();
            foreach ($array as $absoluteLocation => $fileObject) {
                $pathParts = pathinfo($absoluteLocation);
                $fileSize = filesize($absoluteLocation);
                list($width, $height, $type, $attr) = getimagesize($absoluteLocation);
                $imageSizeHuman = $width.'px wide by '.$height.'px high';
                $intel = [];
                $intel['Path'] = $absoluteLocation;
                $intel['PathFromAssets'] = str_replace($assetsBaseFolder, '', $absoluteLocation);
                $intel['PathFromRoot'] = str_replace($baseFolder, '', $absoluteLocation);
                $intel[Extension::class] = $pathParts['extension'];

                $intel['FileName'] = $pathParts['filename'];
                $intel['FirstLetter'] = strtoupper(substr($pathParts['filename'], 0, 1));

                $intel['FileSize'] = $fileSize;
                $intel['HumanFileSize'] = $this->humanFileSize($fileSize);
                $intel['HumanFileSizeRounded'] = '~ '.$this->humanFileSize(round($fileSize / 1024) * 1024);

                $intel['Ratio'] = round($width / $height, 3);
                $intel['Pixels'] = $width * $height;
                $intel['HumanImageDimensions'] = $imageSizeHuman;
                $fileNameInDB = ltrim($intel['PathFromRoot'], DIRECTORY_SEPARATOR);
                $file = Image::get()->filter(['Filename' => $fileNameInDB])->first();
                if (! $file) {
                    $nameInDB = $intel['FileName'] . '.' . $pathParts['extension'];
                    $file = Image::get()->filter(['Name' => $nameInDB])->first();
                }
                if ($file) {
                    $intel['IsInDatabase'] = true;
                    $intel['CMSEditLink'] = '/admin/assets/EditForm/field/File/item/'.$file->ID.'/edit';
                    $intel['DBTitle'] = $file->Title;
                    $time = strtotime($file->LastEdited);
                } else {
                    $intel['IsInDatabase'] = false;
                    $intel['CMSEditLink'] = '/admin/assets/';
                    $intel['DBTitle'] = 'no title set in database';
                    $time = filemtime($absoluteLocation);
                }
                $intel['HumanIsInDatabase'] = $intel['IsInDatabase'] ? 'In Database' : 'Not in Database';
                $intel['LastEditedTS'] = $time;
                $intel['LastEdited'] = DBField::create_field(DBDate::class, $time)->Ago();
                $intel['FolderName'] = trim(str_replace($baseFolder, '', $pathParts['dirname']), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
                $intel['FolderNameShort'] = trim(str_replace($assetsBaseFolder, '', $pathParts['dirname']), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
                $intel['GrandParentFolder'] = dirname($intel['FolderNameShort']);
                $folder = Folder::get()->filter(['Filename' => $intel['FolderName']])->first();
                if ($folder) {
                    $intel['CMSEditLinkFolder'] = '/admin/assets/show/'.$folder->ID;
                } else {
                    $intel['CMSEditLinkFolder'] = '/assets/admin/';
                }
                $this->imagesRaw->push(
                    ArrayData::create($intel)
                );
                unset($intel);
            }
        }
        return $this->imagesRaw;
    }
}
