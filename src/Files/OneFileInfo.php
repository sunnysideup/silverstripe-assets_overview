<?php

namespace Sunnysideup\AssetsOverview\Files;

use \Exception;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDate;

use Sunnysideup\Flush\FlushNow;

use Sunnysideup\AssetsOverview\Interfaces\FileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;
use Sunnysideup\AssetsOverview\Traits\Cacher;

class OneFileInfo implements FileInfo
{
    use FilesystemRelatedTraits;
    use Injectable;
    use Configurable;
    use Cacher;
    use FlushNow;

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
        $this->hash = md5($this->path);
        $fileExists = $fileExists === null ? file_exists($this->path) : $fileExists;
        $this->fileExists = $fileExists;
    }

    public function toArray(): array
    {
        $cachekey = $this->getCacheKey();
        if (! $this->hasCacheKey($cachekey)) {
            $this->addFileSystemDetails();
            $this->addImageDetails();
            $dbFileData = AllFilesInfo::getAnyData($this->intel['PathFromAssetsFolder']);
            $this->addFolderDetails($dbFileData);
            $this->addDBDetails($dbFileData);
            $this->addCalculatedValues();
            $this->addHumanValues();
            ksort($this->intel);
            if ($this->intel['ErrorHasAnyError']) {
                $this->flushNow('x ', '', false);
            } else {
                $this->flushNow('✓ ', '', false);
            }
            self::setCacheValue($cachekey, $this->intel);
        } else {
            $this->intel = self::getCacheValue($cachekey);
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
        $this->parthParts['extension'] = $this->parthParts['extension'] ?? '';
        $this->parthParts['filename'] = $this->parthParts['filename'] ?? '';
        $this->parthParts['dirname'] = $this->parthParts['dirname'] ?? '';

        //basics!
        $this->intel['Path'] = $this->path;
        $this->intel['PathFolderPath'] = $this->parthParts['dirname'] ?: dirname($this->intel['Path']);

        //file name
        $this->intel['PathFileName'] = $this->parthParts['filename'] ?: basename($this->path);
        $this->intel['PathFileNameFirstLetter'] = strtoupper(substr($this->intel['PathFileName'], 0, 1));

        //defaults
        $this->intel['ErrorIsInFileSystem'] = true;
        $this->intel['PathFileSize'] = 0;

        //in file details
        if ($this->fileExists) {
            $this->intel['ErrorIsInFileSystem'] = false;
            $this->intel['PathFileSize'] = filesize($this->path);
        }
        //path
        $this->intel['PathFromPublicRoot'] = trim(str_replace($this->getPublicBaseFolder(), '', $this->path), DIRECTORY_SEPARATOR);
        $this->intel['PathFromAssetsFolder'] = trim(str_replace($this->getAssetsBaseFolder(), '', $this->path), DIRECTORY_SEPARATOR);
        $this->intel['PathFolderFromAssets'] = dirname($this->intel['PathFromAssetsFolder']);
        $this->intel['PathFromAssetsFolderForSorting'] = $this->intel['PathFolderFromAssets'];
        if ($this->intel['PathFolderFromAssets'] === '.') {
            $this->intel['PathFolderFromAssets'] = '--in-root-folder--';
        }

        //folder
        $relativeDirFromAssetsFolder = str_replace($this->getAssetsBaseFolder(), '', $this->intel['PathFolderPath']);

        //extension
        $this->intel['PathExtension'] = $this->parthParts['extension'] ?: $this->getExtension($this->path);
        $this->intel['PathExtensionAsLower'] = (string) strtolower($this->intel['PathExtension']);
        $this->intel['ErrorExtensionMisMatch'] = $this->intel['PathExtension'] !== $this->intel['PathExtensionAsLower'];
    }

    protected function addImageDetails()
    {
        $this->intel['ImageRatio'] = '0';
        $this->intel['ImagePixels'] = 'n/a';
        $this->intel['ImageIsImage'] = $this->isRegularImage($this->intel['PathExtension']);
        $this->intel['ImageIsRegularImage'] = false;
        $this->intel['ImageWidth'] = 0;
        $this->intel['ImageHeight'] = 0;
        $this->intel['ImageType'] = $this->intel['PathExtension'];
        $this->intel['ImageAttribute'] = 'n/a';

        if ($this->fileExists) {
            $this->intel['ImageIsRegularImage'] = $this->isRegularImage($this->intel['PathExtension']);
            if ($this->intel['ImageIsRegularImage']) {
                $this->intel['ImageIsImage'] = true;
            } else {
                $this->intel['ImageIsImage'] = $this->isImage($this->path);
            }
            if ($this->intel['ImageIsImage']) {
                list($width, $height, $type, $attr) = getimagesize($this->path);
                $this->intel['ImageAttribute'] = print_r($attr, 1);
                $this->intel['ImageWidth'] = $width;
                $this->intel['ImageHeight'] = $height;
                $this->intel['ImageRatio'] = round($width / $height, 3);
                $this->intel['ImagePixels'] = $width * $height;
                $this->intel['ImageType'] = $type;
            }
        }
    }

    protected function addFolderDetails($dbFileData)
    {
        $folder = [];
        if (! empty($dbFileData['ParentID'])) {
            if (isset($this->folderCache[$dbFileData['ParentID']])) {
                $folder = $this->folderCache[$dbFileData['ParentID']];
            } else {
                $sql = 'SELECT * FROM "File" WHERE "ID" = ' . $dbFileData['ParentID'];
                $rows = DB::query($sql);
                foreach ($rows as $folder) {
                    $this->folderCache[$dbFileData['ParentID']] = $folder;
                }
            }
        }

        if (empty($folder)) {
            $this->intel['ErrorFindingFolder'] = empty($dbFileData['ParentID']) ? false : true;
            $this->intel['FolderID'] = 0;
        } else {
            $this->intel['ErrorFindingFolder'] = false;
            $this->intel['FolderID'] = $folder['ID'];
        }
        $this->intel['FolderCMSEditLink'] = '/admin/assets/show/' . $this->intel['FolderID'] . '/';
    }

    protected function addDBDetails($dbFileData)
    {
        $time = 0;
        if (empty($dbFileData)) {
            $this->intel['ErrorParentID'] = false;
            $this->intel['DBID'] = 0;
            $this->intel['DBClassName'] = 'Not in database';
            $this->intel['DBParentID'] = 0;
            $this->intel['DBPath'] = '';
            $this->intel['DBFilenameSS3'] = '';
            $this->intel['DBFilenameSS4'] = '';
            $this->intel['ErrorDBNotPresentStaging'] = true;
            $this->intel['ErrorDBNotPresentLive'] = true;
            $this->intel['DBCMSEditLink'] = '/admin/assets/';
            $this->intel['DBTitle'] = '-- no title set in database';
            $this->intel['ErrorInFilename'] = false;
            $this->intel['ErrorInSs3Ss4Comparison'] = false;
            if ($this->fileExists) {
                $time = filemtime($this->path);
            }
        } else {
            $dbFileData['Filename'] = $dbFileData['Filename'] ?? '';
            $this->intel['DBID'] = $dbFileData['ID'];
            $this->intel['DBClassName'] = $dbFileData['ClassName'];
            $this->intel['DBParentID'] = $dbFileData['ParentID'];
            $this->intel['DBPath'] = $dbFileData['FileFilename'] ?? $dbFileData['Filename'] ?? '';
            $this->intel['DBFilename'] = $dbFileData['Name'] ?: basename($this->intel['DBPath']);
            $this->intel['ErrorDBNotPresentStaging'] = AllFilesInfo::existsOnStaging($this->intel['DBID']) ? false : true;
            $this->intel['ErrorDBNotPresentLive'] = AllFilesInfo::existsOnLive($this->intel['DBID']) ? false : true;
            $this->intel['DBCMSEditLink'] = '/admin/assets/EditForm/field/File/item/' . $this->intel['DBID'] . '/edit';
            $this->intel['DBTitle'] = $dbFileData['Title'];
            $this->intel['DBFilenameSS4'] = $dbFileData['FileFilename'];
            $this->intel['DBFilenameSS3'] = $dbFileData['Filename'];
            $this->intel['ErrorInFilename'] = $this->intel['PathFromAssetsFolder'] !== $this->intel['DBPath'];
            $ss3FileName = $dbFileData['Filename'] ?? '';
            if (substr($ss3FileName, 0, strlen('assets/')) === 'assets/') {
                $ss3FileName = substr($ss3FileName, strlen('assets/'));
            }
            $this->intel['ErrorInSs3Ss4Comparison'] = $this->intel['DBFilenameSS3'] && $dbFileData['FileFilename'] !== $ss3FileName;
            $time = strtotime($dbFileData['LastEdited']);
            $this->intel['ErrorParentID'] = true;
            if ((int) $this->intel['FolderID'] === 0) {
                if (intval($dbFileData['ParentID'])) {
                    $this->intel['ErrorParentID'] = true;
                } else {
                    $this->intel['ErrorParentID'] = false;
                }
            } elseif ($this->intel['FolderID']) {
                $this->intel['ErrorParentID'] = (int) $this->intel['FolderID'] !== (int) $dbFileData['ParentID'] ? true : false;
            }
        }
        $this->intel['ErrorDBNotPresent'] = $this->intel['ErrorDBNotPresentLive'] && $this->intel['ErrorDBNotPresentStaging'];

        $this->intel['DBLastEditedTS'] = $time;
        $this->intel['DBLastEdited'] = DBDate::create_field('Date', $time)->Ago();

        $this->intel['DBTitleFirstLetter'] = strtoupper(substr($this->intel['DBTitle'], 0, 1));
    }

    protected function addHumanValues()
    {
        $this->intel['HumanImageDimensions'] = $this->intel['ImageWidth'] . 'px wide by ' . $this->intel['ImageHeight'] . 'px high';
        $this->intel['HumanImageIsImage'] = $this->intel['ImageIsImage'] ? 'Is image' : 'Is not an image';
        $this->intel['HumanErrorExtensionMisMatch'] = $this->intel['ErrorExtensionMisMatch'] ?
            'irregular extension' : 'normal extension';

        //file size
        $this->intel['HumanFileSize'] = $this->humanFileSize($this->intel['PathFileSize']);
        $this->intel['HumanFileSizeRounded'] = '~ ' . $this->humanFileSize(round($this->intel['PathFileSize'] / 204800) * 204800);
        $this->intel['HumanErrorIsInFileSystem'] = $this->intel['ErrorIsInFileSystem'] ?  'File does not exist' : 'File exists' ;

        $this->intel['HumanFolderIsInOrder'] = $this->intel['FolderID'] ? 'In sub-folder' : 'In root folder';

        $this->intel['HumanErrorInFilename'] = $this->intel['ErrorInFilename'] ? 'Error in filename case' : 'No error in filename case';
        $this->intel['HumanErrorParentID'] = $this->intel['ErrorParentID'] ? 'Error in folder ID' : 'Perfect folder ID';
        $stageDBStatus = $this->intel['ErrorDBNotPresentStaging'] ? 'Is not on draft site' : ' Is on draft site';
        $liveDBStatus = $this->intel['ErrorDBNotPresentLive'] ? 'Is not on live site' : ' Is on live site';
        $this->intel['HumanErrorDBNotPresent'] = $stageDBStatus . ', ' . $liveDBStatus;
        $this->intel['HumanErrorInSs3Ss4Comparison'] = $this->intel['ErrorInSs3Ss4Comparison'] ?
            'Filename and FileFilename do not match' : 'Filename and FileFilename match';
    }

    protected function addCalculatedValues()
    {
        $this->intel['ErrorHasAnyError'] = false;
        $errorFields = [
            'ErrorDBNotPresent',
            'ErrorDBNotPresentLive',
            'ErrorDBNotPresentStaging',
            'ErrorExtensionMisMatch',
            'ErrorFindingFolder',
            'ErrorInFilename',
            'ErrorInSs3Ss4Comparison',
            'ErrorParentID',
        ];
        foreach ($errorFields as $field) {
            if ($this->intel[$field]) {
                $this->intel['ErrorHasAnyError'] = true;
                break;
            }
        }
    }

    protected function getBackupDataObject()
    {
        $file = DataObject::get_one(File::class, ['FileFilename' => $this->intel['PathFromAssetsFolder']]);
        //backup for file ...
        if (! $file) {
            if ($folder) {
                $nameInDB = $this->intel['PathFileName'] . '.' . $this->intel['PathExtension'];
                $file = DataObject::get_one(File::class, ['Name' => $nameInDB, 'ParentID' => $folder->ID]);
            }
        }
        $filter = ['FileFilename' => $this->intel['PathFolderFromAssets']];
        if (Folder::get()->filter($filter)->count() === 1) {
            $folder = DataObject::get_one(Folder::class, $filter);
        }
    }

    ##############################################
    # CACHE
    ##############################################


    /**
     * @return string
     */
    protected function getCacheKey(): string
    {
        return $this->hash;
    }
}
