<?php

namespace Sunnysideup\AssetsOverview\Control;

class View extends \ContentController
{

    private static $allowed_extensions = [];

    private static $allowed_actions = array(
        'byfolder' => 'ADMIN',
        'byfilename' => 'ADMIN',
        'byfilesize' => 'ADMIN',
        'byextension' => 'ADMIN',
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
    );

    private static $names = array(
        'byfolder' => 'Folder',
        'byfilename' => 'Filename',
        'byfilesize' => 'Filesize',
        'bylastedited' => 'Last Edited',
        'byextension' => 'Extension',
        'bydatabasestatus' => 'Database Status',
        'bydatabaseerror' => 'Database Error',
        'byfoldererror' => 'Folder Error',
        'byfilesystemstatus' => 'Filesystem Status',
        'bydbtitle' => 'File Title',
        'byisimage' => 'Image vs Other Files',
        'bydimensions' => 'Dimensions',
        'byratio' => 'Ratio',
        'bysimilarity' => 'Similarity (takes a long time!)',
        'rawlist' => 'Similarity (takes a long time!)',
    );

    protected $imagesRaw = null;
    protected $title = '';
    protected $imagesSorted = null;

    public function Link($action = null)
    {
        $base = rtrim(\Director::absoluteBaseURL(), DIRECTORY_SEPARATOR);
        $link = $base .  DIRECTORY_SEPARATOR . 'assetsoverview'  . DIRECTORY_SEPARATOR;
        if($action) {
            $link .= $action . DIRECTORY_SEPARATOR;
        }

        return $link;
    }

    public function ActionMenu()
    {
        $al = \ArrayList::create();
        foreach($this->Config()->get('names') as $key => $name) {
            $linkingMode = $key === $this->request->param('Action') ? 'current' : 'link';
            $array = [
                'Link' => $this->Link($key),
                'Title' => $name,
                'LinkingMode' => $linkingMode,
            ];
            $al->push(new \ArrayData($array));
        }

        return $al;
    }

    public function getTitle()
    {
        $list = $this->Config()->get('names');

        return $list[$this->request->param('Action')] ?? 'Please select view by ...';
    }

    public function getImagesRaw()
    {
        return $this->imagesRaw;

    }

    public function getImagesSorted()
    {
        return $this->imagesSorted;
    }

    function init()
    {
        parent::init();
        if(!\Permission::check("ADMIN")) return \Security::permissionFailure($this);
        \Requirements::clear();
    }

    public function index($request)
    {
        return $this->renderWith('AssetsOverview');
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
        return $this->createProperList('Extension', 'Extension');
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
        return $this->createProperList('ExistsInFileSystem', 'HumanExistsInFileSystem');
    }


    public function bylastedited($request)
    {
        return $this->createProperList('LastEditedTS', 'LastEdited');
    }

    public function bysimilarity($request)
    {
        require_once(__DIR__.'../../../compare-images-master/image.compare.class.php');
        set_time_limit(240);
        $engine = new compareImages();
        $this->buildImagesRaw();
        $a = clone $this->imagesRaw;
        $b = clone $this->imagesRaw;
        $c = clone $this->imagesRaw;
        $alreadyDone = [];
        foreach($a as $image) {
            $nameOne = $image->Path;
            $nameOneFromAssets = $image->PathFromAssets;
            if(! in_array($nameOne, $alreadyDone)) {
                $easyFind = false;
                $sortArray = [];
                foreach($b as $compareImage) {
                    $nameTwo = $compareImage->Path;
                    if($nameOne !== $nameTwo) {
                        $fileNameTest = $image->FileName && $image->FileName === $compareImage->FileName;
                        $fileSizeTest = $image->FileSize > 0 && $image->FileSize === $compareImage->FileSize;
                        if($fileNameTest || $fileSizeTest) {
                            $easyFind = true;
                            $alreadyDone[$nameOne] = $nameOneFromAssets;
                            $alreadyDone[$compareImage->Path] = $nameOneFromAssets;
                        } elseif($easyFind === false && $image->IsImage) {
                            if($image->Ratio == $compareImage->Ratio && $image->Ratio > 0) {
                                $score = $engine->compare($nameOne, $nameTwo);
                                $sortArray[$nameTwo] = $score;
                                break;
                            }
                        }
                    }
                }
                if($easyFind === false) {
                    if(count($sortArray)) {
                        asort($sortArray);
                        reset($sortArray);
                        $mostSimilarKey = key($sortArray);
                        foreach($c as $findImage) {
                            if($findImage->Path === $mostSimilarKey) {
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
        foreach($this->imagesRaw as $image) {
            $image->MostSimilarTo = $alreadyDone[$image->Path] ?? '[N/A]';
        }

        return $this->createProperList('MostSimilarTo', 'MostSimilarTo');
    }

    protected function createProperList($sortField, $headerField)
    {
        if($this->imagesSorted === null) {
            //done only if not already done ...
            $this->buildImagesRaw();
            $this->imagesRaw = $this->imagesRaw->Sort($sortField);
            $this->imagesSorted = \ArrayList::create();

            $innerArray = \ArrayList::create();
            $prevHeader = 'nothing here....';

            foreach($this->imagesRaw as $image){
                $newHeader = $image->$headerField;
                if($newHeader !== $prevHeader) {
                    $this->addToSortedArray(
                        $prevHeader, //correct! important ...
                        $innerArray
                    );
                    $prevHeader = $newHeader;
                    unset($innerArray);
                    $innerArray = \ArrayList::create();
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
        if($arrayList->count()) {
            $count = $this->imagesSorted->count();
            $this->imagesSorted->push(
                \ArrayData::create(
                    [
                        'Number' => $count,
                        'SubTitle' => $header,
                        'Items' => $arrayList
                    ]
                )
            );
        }
    }

    protected function isRegularImage(string $extension) : bool
    {
        return in_array(strtolower($extension), ['jpg', 'gif', 'png']);
    }

    protected function isImage(string $filename) : bool
    {
        try {
            $outcome = @exif_imagetype($filename) ? true : false;
        } catch (Exception $e) {
            $outcome = false;
        }

        return $outcome;
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
        return \Director::baseFolder() . DIRECTORY_SEPARATOR . ASSETS_DIR;
    }

    protected function buildImagesRaw()
    {
        if($this->imagesRaw === null) {
            $baseFolder = \Director::baseFolder();
            $assetsBaseFolder = $this->assetsBaseDir();

            $this->imagesRaw = \ArrayList::create();

            $allowedExtensions = $this->Config()->get('allowed_extensions');
            $arrayRaw = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($assetsBaseFolder), \RecursiveIteratorIterator::SELF_FIRST);
            $array = [];
            foreach($arrayRaw as $src){
                if(is_dir($src)) {
                    continue;
                }
                if(strpos($src, '_resampled/') !== false) {
                    continue;
                }
                $extension = $this->getExtension($src);
                if(count($allowedExtensions) === 0 || in_array($extension, $allowedExtensions)) {
                    $path = $src->getPathName();
                    $array[$path] = true;
                }
            }
            $filesInDatabase = \File::get()
                ->exclude(['ClassName' => \Folder::class])
                ->column('Filename');
            foreach($filesInDatabase as $relativeSrc) {
                $absoluteLocation = $baseFolder. '/' . $relativeSrc;
                if(! isset($array[$absoluteLocation])) {
                    $array[$absoluteLocation] = false;
                }
            }

            foreach($array as $absoluteLocation => $fileExists){
                $intel = [];
                $pathParts = [];
                if($fileExists) {
                    $pathParts = pathinfo($absoluteLocation);
                }
                $pathParts['extension'] = $pathParts['extension']  ?? '--no-extension';
                $pathParts['filename'] =  $pathParts['filename'] ?? '--no-file-name';
                $pathParts['dirname'] = $pathParts['dirname'] ?? '--no-parent-dir';

                $intel['Extension'] = $pathParts['extension'];
                $intel['FileName'] = $pathParts['filename'];

                $intel['FileSize'] = 0;

                $intel['Path'] = $absoluteLocation;
                $intel['PathFromAssets'] = str_replace($assetsBaseFolder, '', $absoluteLocation);
                $intel['PathFromRoot'] = str_replace($baseFolder, '', $absoluteLocation);
                $intel['FirstLetter'] = strtoupper(substr($intel['FileName'], 0, 1));
                $intel['FileNameInDB'] = ltrim($intel['PathFromRoot'], DIRECTORY_SEPARATOR);

                $intel['FolderName'] = trim(str_replace($baseFolder, '', $pathParts['dirname']), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
                $intel['FolderNameShort'] = trim(str_replace($assetsBaseFolder, '', $pathParts['dirname']), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
                $intel['GrandParentFolder'] = dirname($intel['FolderNameShort']);

                $intel['HumanImageDimensions'] = 'n/a';
                $intel['Ratio'] = '0';
                $intel['Pixels'] = 'n/a';
                $intel['IsImage'] = false;
                $intel['HumanIsImage'] = 'Is not an image';
                $intel['IsRegularImage'] = false;
                $intel['ExistsInFileSystem'] = false;
                $intel['HumanExistsInFileSystem'] = 'file does not exist';
                $intel['ErrorParentID'] = true;

                if($fileExists) {
                    $intel['ExistsInFileSystem'] = true;
                    $intel['HumanExistsInFileSystem'] = 'file exists';
                    $intel['FileSize'] = filesize($absoluteLocation);
                    $intel['IsRegularImage'] = $this->isRegularImage($intel['Extension']);
                    if($intel['IsRegularImage']) {
                        $intel['IsImage'] = true;
                    } else {
                        $intel['IsImage'] = $this->isImage($absoluteLocation);
                    }
                    if($intel['IsImage']) {
                        list($width, $height, $type, $attr) = getimagesize($absoluteLocation);
                        $intel['HumanImageDimensions'] = $width.'px wide by '.$height.'px high';
                        $intel['Ratio'] = round($width / $height, 3);
                        $intel['Pixels'] = $width * $height;
                        $intel['HumanIsImage'] = 'Is Image';
                    }
                }

                $intel['HumanFileSize'] = $this->humanFileSize($intel['FileSize']);
                $intel['HumanFileSizeRounded'] = '~ '.$this->humanFileSize(round($intel['FileSize'] / 1024) * 1024);
                $file = \DataObject::get_one(\File::class, ['Filename' => $intel['FileNameInDB']]);
                $folderFromFileName = \DataObject::get_one(\Folder::class, ['Filename' => $intel['FolderName']]);
                $folder = null;
                if($file) {
                    $folder = \DataObject::get_one(\Folder::class, ['ID' => $file->ParentID]);
                }

                //backup for folder
                if(! $folder) {
                    $folder = $folderFromFileName;
                }

                //backup for file ...
                if(! $file) {
                    if($folder) {
                        $nameInDB = $intel['FileName'] . '.' . $intel['Extension'];
                        $file = \DataObject::get_one(\File::class, ['Name' => $nameInDB, 'ParentID' => $folder->ID]);
                    }
                }

                if($file) {
                    $intel['ID'] = $file->ID;
                    $intel['IsInDatabase'] = true;
                    $intel['CMSEditLink'] = '/admin/assets/EditForm/field/File/item/'.$file->ID.'/edit';
                    $intel['DBTitle'] = $file->Title;
                    $intel['ErrorInFilenameCase'] = $intel['FileNameInDB'] !== $file->Filename;
                    $time = strtotime($file->LastEdited);
                    if($folder) {
                        $intel['ErrorParentID'] = (int)$folder->ID !== (int)$file->ParentID;
                    } elseif((int) $file->ParentID === 0) {
                        $intel['ErrorParentID'] = false;
                    }
                }
                else {
                    $intel['ID'] = 0;
                    $intel['IsInDatabase'] = false;
                    $intel['CMSEditLink'] = '/admin/assets/';
                    $intel['DBTitle'] = '-- no title set in database';
                    $intel['ErrorInFilenameCase'] = true;
                    if($fileExists) {
                        $time = filemtime($absoluteLocation);
                    }
                }
                if($folder) {
                    $intel['ParentID'] = $folder->ID;
                    $intel['HasFolder'] = true;
                    $intel['HumanHasFolder'] = 'in sub-folder';
                    $intel['CMSEditLinkFolder'] = '/admin/assets/show/'.$folder->ID;
                } else {
                    $intel['ParentID'] = 0;
                    $intel['HasFolder'] = false;
                    $intel['HumanHasFolder'] = 'in root folder';
                    $intel['CMSEditLinkFolder'] = '/assets/admin/';
                }

                $intel['LastEditedTS'] = $time;
                $intel['LastEdited'] = \DBField::create_field('Date', $time)->Ago();
                $intel['HumanIsInDatabase'] = $intel['IsInDatabase'] ? 'In Database' : 'Not in Database';
                $intel['HumanErrorInFilenameCase'] = $intel['ErrorInFilenameCase'] ? 'Error in Case' : 'Perfect Case';
                $intel['HumanErrorParentID'] = $intel['ErrorParentID'] ? 'Error in folder ID' : 'Perfect Folder ID';
                $intel['FirstLetterDBTitle'] = strtoupper(substr($intel['DBTitle'], 0, 1));


                $this->imagesRaw->push(
                    \ArrayData::create($intel)
                );
                unset($intel);
            }
        }
        return $this->imagesRaw;
    }




}
