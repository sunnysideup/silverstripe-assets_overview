<?php

namespace Sunnysideup\AssetsOverview\Files;

use \Exception;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDate;

use Sunnysideup\AssetsOverview\Interfaces\FileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;

class OneFileInfo implements Flushable, FileInfo
{
    use FilesystemRelatedTraits;
    use Injectable;
    use Configurable;

    /**
     * @var string
     */
    protected $hash = '';

    /**
     * @var string
     */
    protected $path = '';

    /**
     * @var array
     */
    protected $intel = [];

    /**
     * @var array
     */
    protected $parthParts = '';

    /**
     * @var bool
     */
    protected $fileExists = false;

    protected $folderCache = [];

    /**
     * @param string $absoluteLocation [description]
     * @param ?bool  $fileExists       [description]
     */
    public function __construct(string $absoluteLocation, ?bool $fileExists)
    {
        $this->path = $absoluteLocation;
        if($fileExists) {
            $this->hash = md5_file($this->path);
        } else {
            $this->hash = 'no-file'.md5($absoluteLocation);
        }
        $fileExists = $fileExists === null ? file_exists($this->path) : $fileExists;
        $this->fileExists = $fileExists;
    }

    public static function flush()
    {
        $cache = self::getCache();
        $cache->clear();
    }

    public function toArray(): array
    {
        $cache = self::getCache();
        $cachekey = $this->getCacheKey();
        if (! $cache->has($cachekey)) {
            $this->addFileSystemDetails();
            $this->addImageDetails();
            $fileData = AllFilesInfo::get_any_data($this->intel['PathFromAssetsFolder']);
            $this->addFolderDetails($fileData);
            $this->addFileDetails($fileData);
            $this->addHumanValues();

            $fullArrayString = serialize($this->intel);
            $cache->set($cachekey, $fullArrayString);
        } else {
            $fullArrayString = $cache->get($cachekey);
            $this->intel = unserialize($fullArrayString);
        }

        return $this->intel;
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

    protected function addFileSystemDetails()
    {

        //get path parts
        $this->parthParts = [];
        if ($this->fileExists) {
            $this->parthParts = pathinfo($this->path);
        }
        $this->parthParts['extension'] = $this->parthParts['extension'] ?? '--no-extension';
        $this->parthParts['filename'] = $this->parthParts['filename'] ?? '--no-file-name';
        $this->parthParts['dirname'] = $this->parthParts['dirname'] ?? '--no-parent-dir';

        //basics!
        $this->intel['Path'] = $this->path;
        $this->intel['Dirname'] = $this->parthParts['dirname'] ;

        //file name
        $this->intel['FileName'] = $this->parthParts['filename'];
        $this->intel['FirstLetter'] = strtoupper(substr($this->intel['FileName'], 0, 1));

        //defaults
        $this->intel['IsInFileSystem'] = false;
        $this->intel['FileSize'] = 0;

        //in file details
        if ($this->fileExists) {
            $this->intel['IsInFileSystem'] = true;
            $this->intel['FileSize'] = filesize($this->path);
        }
        //path
        $this->intel['PathFromPublicRoot'] = trim(str_replace($this->getPublicBaseFolder(), '', $this->path), DIRECTORY_SEPARATOR);
        $this->intel['PathFromAssetsFolder'] = trim(str_replace($this->getAssetsBaseFolder(), '', $this->path), DIRECTORY_SEPARATOR);
        $this->intel['PathFromAssetsFolderFolderOnly'] = dirname($this->intel['PathFromAssetsFolder']);

        //folder
        $relativeDirFromAssetsFolder = str_replace($this->getAssetsBaseFolder(), '', $this->intel['Dirname']);
        $this->intel['FolderNameFromAssetsFolder'] = trim($relativeDirFromAssetsFolder, DIRECTORY_SEPARATOR) ;

        //extension
        $this->intel['Extension'] = $this->parthParts['extension'];
        $this->intel['ExtensionAsLower'] = (string) strtolower($this->intel['Extension']);
        $this->intel['HasIrregularExtension'] = $this->intel['Extension'] !== $this->intel['ExtensionAsLower'];
    }

    protected function addImageDetails()
    {
        $this->intel['Ratio'] = '0';
        $this->intel['Pixels'] = 'n/a';
        $this->intel['IsImage'] = false;
        $this->intel['IsRegularImage'] = false;
        $this->intel['Width'] = 0;
        $this->intel['Height'] = 0;
        $this->intel['Type'] = $this->intel['Extension'];
        $this->intel['Attribute'] = 'n/a';

        if ($this->fileExists) {
            $this->intel['IsRegularImage'] = $this->isRegularImage($this->intel['Extension']);
            if ($this->intel['IsRegularImage']) {
                $this->intel['IsImage'] = true;
            } else {
                $this->intel['IsImage'] = $this->isImage($this->path);
            }
            if ($this->intel['IsImage']) {
                list($width, $height, $type, $attr) = getimagesize($this->path);
                $this->intel['Attribute'] = print_r($attr, 1);
                $this->intel['Width'] = $width;
                $this->intel['Height'] = $height;
                $this->intel['Ratio'] = round($width / $height, 3);
                $this->intel['Pixels'] = $width * $height;
                $this->intel['Type'] = $type;
            }
        }

    }

    protected function addFolderDetails($fileData)
    {

        $folder = [];
        if (! empty($fileData['ParentID'])) {
            if(isset($this->folderCache['ParentID'])) {
                $folder = $this->folderCache['ParentID'];
            } else {
                $sql = 'SELECT * FROM "File" WHERE "ID" = '.$fileData['ParentID'];
                $rows = DB::query($sql);
                foreach($rows as $folder) {
                    $this->folderCache['ParentID'] = $folder;
                }
            }
        }

        if (empty($folder)) {
            $this->intel['HasFolderError'] = true;
            $this->intel['FolderID'] = 0;
            $this->intel['HasFolder'] = false;
            $this->intel['CMSEditLinkFolder'] = '/admin/assets/show/0ssws';
        } else {
            $this->intel['HasFolderError'] = false;
            $this->intel['FolderID'] = $folder['ID'];
            $this->intel['HasFolder'] = true;
            $this->intel['CMSEditLinkFolder'] = '/admin/assets/show/' .$folder['ID'] . '/';
        }
    }

    protected function addFileDetails($fileData)
    {

        $time = 0;
        if (empty($fileData)) {
            $this->intel['ErrorParentID'] = false;
            $this->intel['ID'] = 0;
            $this->intel['ClassName'] = 'Error';
            $this->intel['ParentID'] = 0;
            $this->intel['IsInDatabaseStaging'] = false;
            $this->intel['IsInDatabaseLive'] = false;
            $this->intel['CMSEditLink'] = '/admin/assets/';
            $this->intel['DBTitle'] = '-- no title set in database';
            $this->intel['ErrorInFilenameCase'] = false;
            $this->intel['ErrorInSs3Ss4Comparison'] = false;
            if ($this->fileExists) {
                $time = filemtime($this->path);
            }
        } else {
            $this->intel['ID'] = $fileData['ID'];
            $this->intel['ClassName'] = $fileData['ClassName'];
            $this->intel['ParentID'] = $fileData['ParentID'];
            $this->intel['IsInDatabaseStaging'] = AllFilesInfo::exists_on_staging($this->intel['ID']);
            $this->intel['IsInDatabaseLive'] = AllFilesInfo::exists_on_live($this->intel['ID']);
            $this->intel['CMSEditLink'] = '/admin/assets/EditForm/field/File/item/' . $this->intel['ID']. '/edit';
            $this->intel['DBTitle'] = $fileData['Title'];
            $this->intel['ErrorInFilenameCase'] = $this->intel['PathFromAssetsFolder'] !== $fileData['FileFilename'];
            $fileData['Filename'] = $fileData['Filename'] ?? '';
            $this->intel['ErrorInSs3Ss4Comparison'] = $fileData['FileFilename'] !== $fileData['Filename'];
            $time = strtotime($fileData['LastEdited']);
            $this->intel['ErrorParentID'] = true;
            if ((int) $this->intel['FolderID'] === 0) {
                if(intval($fileData['ParentID'])) {
                    $this->intel['ErrorParentID'] = true;
                } else {
                    $this->intel['ErrorParentID'] = false;
                }
            } elseif ($this->intel['FolderID']) {
                $this->intel['ErrorParentID'] = ((int) $this->intel['FolderID'] !== (int) $fileData['ParentID']) ? true : false;
            }
        }
        $this->intel['IsInDatabase'] = $this->intel['IsInDatabaseLive'] || $this->intel['IsInDatabaseStaging'];
        $stageDBStatus = $this->intel['IsInDatabaseStaging'] ? 'Is in Draft' : ' Is not in Draft';
        $liveDBStatus = $this->intel['IsInDatabaseLive'] ? 'Is in Live' : ' Is not in Live';
        $this->intel['IsInDatabaseSummary'] = $stageDBStatus . ', '. $liveDBStatus;

        $this->intel['LastEditedTS'] = $time;
        $this->intel['LastEdited'] = DBDate::create_field('Date', $time)->Ago();

        $this->intel['FirstLetterDBTitle'] = strtoupper(substr($this->intel['DBTitle'], 0, 1));
    }

    protected function addHumanValues()
    {
        $this->intel['HumanImageDimensions'] = $this->intel['Width'] . 'px wide by ' . $this->intel['Height'] . 'px high';
        $this->intel['HumanIsImage'] = $this->intel['IsImage'] ? 'Is image' : 'Is not an image';
        $this->intel['HumanHasIrregularExtension'] = $this->intel['HasIrregularExtension'] ?
            'irregular extension' : 'normal extension';

        //file size
        $this->intel['HumanFileSize'] = $this->humanFileSize($this->intel['FileSize']);
        $this->intel['HumanFileSizeRounded'] = '~ ' . $this->humanFileSize(round($this->intel['FileSize'] / 204800) * 204800);
        $this->intel['HumanIsInFileSystem'] = $this->fileExists ? 'File exists' : 'File does not exist';

        $this->intel['HumanHasFolder'] = $this->intel['FolderID'] ? 'In sub-folder' : 'In root folder';

        $this->intel['HumanErrorInFilenameCase'] = $this->intel['ErrorInFilenameCase'] ? 'Error in filename case' : 'No error in filename case';
        $this->intel['HumanErrorParentID'] = $this->intel['ErrorParentID'] ? 'Error in folder ID' : 'Perfect folder ID';
        $this->intel['HumanIsInDatabaseSummary'] = $this->intel['IsInDatabaseSummary'];
        $this->intel['HumanErrorInSs3Ss4Comparison'] = $this->intel['ErrorInSs3Ss4Comparison'] ?
            'Filename and FileFilename do not match' :  'Filename and FileFilename match' ;

    }

    protected function getBackupDataObject()
    {
        $file = DataObject::get_one(File::class, ['FileFilename' => $this->intel['PathFromAssetsFolder']]);
        //backup for file ...
        if (! $file) {
            if ($folder) {
                $nameInDB = $this->intel['FileName'] . '.' . $this->intel['Extension'];
                $file = DataObject::get_one(File::class, ['Name' => $nameInDB, 'ParentID' => $folder->ID]);
            }
        }
        $filter = ['FileFilename' => $this->intel['FolderNameFromAssetsFolder']];
        if(Folder::get()->filter($filter)->count() === 1) {
            $folder = DataObject::get_one(Folder::class, $filter);
        }
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

    /**
     * @return string
     */
    protected function getCacheKey(): string
    {
        return $this->hash;
    }




}
