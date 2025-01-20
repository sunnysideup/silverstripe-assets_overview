<?php

namespace Sunnysideup\AssetsOverview\Files;

use Exception;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\ValidationResult;
use Sunnysideup\AssetsOverview\Control\View;
use Sunnysideup\AssetsOverview\Interfaces\FileInfo;
use Sunnysideup\AssetsOverview\Traits\Cacher;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;
use Sunnysideup\Flush\FlushNow;

class OneFileInfo implements FileInfo
{
    use FilesystemRelatedTraits;
    use Injectable;
    use Configurable;
    use Cacher;
    use FlushNow;

    protected static array $cached_inst = [];

    public static function inst(string $path): OneFileInfo
    {
        if (!isset(self::$cached_inst[$path])) {
            self::$cached_inst[$path] = new OneFileInfo($path);
        }
        return self::$cached_inst[$path];
    }

    protected bool $debug = false;
    protected bool $noCache = false;

    public function setDebug(bool $b): static
    {
        $this->debug = $b;
        return $this;
    }

    public function setNoCache(bool $b): static
    {
        $this->noCache = $b;
        return $this;
    }

    protected array $errorFields = [
        'ErrorDBNotPresent',
        'ErrorDBNotPresentStaging',
        'ErrorExtensionMisMatch',
        'ErrorFindingFolder',
        'ErrorInFilename',
        'ErrorInSs3Ss4Comparison',
        'ErrorParentID',
        'ErrorNotInDraft',
    ];

    /**
     * @var string
     */
    protected string $pathHash = '';

    /**
     * @var string
     */
    protected string $path = '';

    /**
     * @var array
     */
    protected array $intel = [];

    /**
     * @var bool
     */
    protected bool $physicalFileExists = false;
    protected ?File $file;

    public function __construct(string $location)
    {
        $this->path = $location;
        $this->intel['Path'] = $this->path;
        $this->intel['AbsolutePath'] = Controller::join_links(ASSETS_PATH, $this->intel['Path']);
        $this->intel['IsProtected'] = false;
        $this->pathHash = md5($this->path);
        $this->physicalFileExists = file_exists($this->intel['AbsolutePath']);
        $this->file = DataObject::get_one(File::class, ['FileFilename' => $this->path]);
        if (!$this->physicalFileExists) {
            if ($this->file && $this->file->exists()) {
                $this->physicalFileExists = true;
                $this->intel['IsProtected'] = true;
            }
        }
    }

    public function toArray(): array
    {
        $cachekey = $this->getCacheKey();
        if (! $this->hasCacheKey($cachekey) || $this->noCache) {
            $this->collateIntel();
            if ($this->debug) {
                if ($this->intel['ErrorHasAnyError']) {
                    echo PHP_EOL . 'x ' . $this->path . ' ' . PHP_EOL;
                } else {
                    echo '✓ ';
                }
            }
            $this->setCacheValue($cachekey, $this->intel);
        } else {
            $this->intel = $this->getCacheValue($cachekey);
        }

        return $this->intel;
    }

    protected function collateIntel(): void
    {

        $this->addFileSystemDetails();
        $this->addImageDetails();

        $obj = AllFilesInfo::inst();
        $dbFileData = $obj->getAnyData($this->intel['Path']);
        $this->addFolderDetails($dbFileData);
        $this->addDBDetails($dbFileData);
        $this->addCalculatedValues();
        $this->addHumanValues();
        $this->file = null;
        ksort($this->intel);
    }

    protected function isRegularImage(string $extension): bool
    {
        return in_array(
            strtolower($extension),
            ['jpg', 'gif', 'png', 'webp', 'jpeg', 'bmp', 'tiff', 'svg'],
            true
        );
    }

    protected function isImage(): bool
    {
        return $this->file && $this->file instanceof Image;
    }

    protected function addFileSystemDetails()
    {
        //get path parts
        $pathParts = [];
        if ($this->physicalFileExists) {
            $pathParts = pathinfo($this->intel['AbsolutePath']);
        }

        $pathParts['extension'] = $pathParts['extension'] ?? '';
        $pathParts['filename'] = $pathParts['filename'] ?? '';
        $pathParts['dirname'] = $pathParts['dirname'] ?? '';

        //basics!
        $this->intel['InfoLink'] = Config::inst()->get(View::class, 'url_segment') . '/jsonone/?path=' . $this->path;
        $this->intel['PathFolderPath'] = $pathParts['dirname'] ?: dirname($this->intel['Path']);
        //file name
        $this->intel['PathFileName'] = $pathParts['filename'] ?: basename($this->intel['AbsolutePath']);
        $this->intel['PathFileNameFirstLetter'] = strtoupper(substr((string) $this->intel['PathFileName'], 0, 1));

        //defaults
        $this->intel['ErrorIsInFileSystem'] = true;
        $this->intel['PathFileSize'] = 0;
        $this->intel['IsDir'] = is_dir($this->intel['AbsolutePath']);

        //in file details
        if ($this->physicalFileExists) {
            $this->intel['ErrorIsInFileSystem'] = false;
            $this->intel['PathFileSize'] = $this->file?->getAbsoluteSize() ?: 0;
        }

        //path
        $this->intel['PathFromPublicRoot'] = trim(str_replace($this->getPublicBaseFolder(), '', (string) $this->intel['AbsolutePath']), DIRECTORY_SEPARATOR);
        $this->intel['Path'] = trim($this->path, '/');
        $this->intel['PathFolderFromAssets'] = dirname($this->path);
        if ('.' === $this->intel['PathFolderFromAssets']) {
            $this->intel['PathFolderFromAssets'] = '--in-root-folder--';
        }

        //folder
        // $relativeDirFromAssetsFolder = str_replace($this->getAssetsBaseFolder(), '', (string) $this->intel['PathFolderPath']);

        //extension
        $this->intel['PathExtension'] = $pathParts['extension'] ?: $this->getExtension($this->path);
        $this->intel['PathExtensionAsLower'] = (string) strtolower($this->intel['PathExtension']);
        $this->intel['ErrorExtensionMisMatch'] = $this->intel['PathExtension'] !== $this->intel['PathExtensionAsLower'];
        $pathExtensionWithDot = '.' . $this->intel['PathExtension'];
        $extensionLength = strlen((string) $pathExtensionWithDot);
        $pathLength = strlen((string) $this->intel['PathFileName']);
        if (substr((string) $this->intel['PathFileName'], (-1 * $extensionLength)) === $pathExtensionWithDot) {
            $this->intel['PathFileName'] = substr((string) $this->intel['PathFileName'], 0, ($pathLength - $extensionLength));
        }

        $this->intel['ErrorInvalidExtension'] = false;
        if (false !== $this->intel['IsDir']) {
            $test = Injector::inst()->get(DBFile::class);
            $validationResult = Injector::inst()->get(ValidationResult::class);
            $this->intel['ErrorInvalidExtension'] = (bool) $test->validate(
                $validationResult,
                $this->intel['PathFileName']
            );
        }
    }

    protected function addImageDetails()
    {
        $this->intel['ImageRatio'] = '0';
        $this->intel['ImagePixels'] = 'n/a';
        $this->intel['IsImage'] = $this->isImage();
        $this->intel['ImageIsRegularImage'] = $this->isRegularImage($this->intel['PathExtension']);
        $this->intel['ImageWidth'] = 0;
        $this->intel['ImageHeight'] = 0;
        $this->intel['ImageType'] = $this->intel['PathExtension'];
        $this->intel['ImageAttribute'] = 'n/a';

        if ($this->physicalFileExists) {
            if ($this->intel['IsImage']) {
                $this->intel['ImageWidth'] = $this->file?->getWidth() ?: 0;
                $this->intel['ImageHeight'] = $this->file?->getHeight() ?: 0;
                if ($this->intel['ImageHeight'] > 0) {
                    $this->intel['ImageRatio'] = round($this->intel['ImageHeight'] / $this->intel['ImageWidth'], 3);
                } else {
                    $this->intel['ImageRatio'] = 0;
                }
                $this->intel['ImagePixels'] =  $this->intel['ImageHeight'] * $this->intel['ImageWidth'];
                $this->intel['IsResizedImage'] = (bool) strpos($this->intel['PathFileName'], '__');
            }
        }
    }

    protected function addFolderDetails($dbFileData)
    {
        $folder = [];
        if (! empty($dbFileData['ParentID'])) {
            $sql = 'SELECT ID FROM "File" WHERE "ID" = ' . $dbFileData['ParentID'] . ' LIMIT 1';
            $rows = DB::query($sql);
            foreach ($rows as $folder) {
                $hasFolder = true;
                break;
            }
        }

        if ($hasFolder) {
            $this->intel['ErrorFindingFolder'] = false;
            $this->intel['FolderID'] = $folder['ID'];
            $this->intel['FolderCMSEditLink'] = '/admin/assets/show/' . $this->intel['FolderID'] . '/';
        } else {
            $this->intel['ErrorFindingFolder'] = ! empty($dbFileData['ParentID']);
            $this->intel['FolderID'] = 0;
            $this->intel['FolderCMSEditLink'] = '/admin/assets';
        }
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
            $this->intel['ErrorInDraftOnly'] = false;
            $this->intel['ErrorNotInDraft'] = false;
            $this->intel['DBCMSEditLink'] = '/admin/assets/';
            $this->intel['DBTitle'] = '-- no title set in database';
            $this->intel['ErrorInFilename'] = false;
            $this->intel['ErrorInSs3Ss4Comparison'] = false;
            $time = time();
            if ($this->physicalFileExists && !$this->intel['IsProtected'] && $this->intel['AbsolutePath']) {
                if (file_exists($this->intel['AbsolutePath'])) {
                    $time = filemtime($this->intel['AbsolutePath']);
                }
            }
        } else {
            $obj = AllFilesInfo::inst();
            if (!$this->intel['IsDir']) {
                $this->intel['IsDir'] = is_a($dbFileData['ClassName'], Folder::class, true);
            }
            $dbFileData['Filename'] = $dbFileData['Filename'] ?? '';
            $this->intel['DBID'] = $dbFileData['ID'];
            $this->intel['DBClassName'] = $dbFileData['ClassName'];
            $this->intel['DBParentID'] = $dbFileData['ParentID'];
            $this->intel['DBPath'] = $dbFileData['FileFilename'] ?? $dbFileData['Filename'] ?? '';
            $this->intel['DBFilename'] = $dbFileData['Name'] ?: basename($this->intel['DBPath']);
            $existsOnStaging = $obj->existsOnStaging($this->intel['DBID']);
            $existsOnLive = $obj->existsOnLive($this->intel['DBID']);
            $this->intel['ErrorDBNotPresentStaging'] = ! $existsOnStaging;
            $this->intel['ErrorDBNotPresentLive'] = ! $existsOnLive;
            $this->intel['ErrorInDraftOnly'] = $existsOnStaging && ! $existsOnLive;
            $this->intel['ErrorNotInDraft'] = ! $existsOnStaging && $existsOnLive;
            $this->intel['DBCMSEditLink'] = '/admin/assets/EditForm/field/File/item/' . $this->intel['DBID'] . '/edit';
            $this->intel['DBTitle'] = $dbFileData['Title'];
            $this->intel['DBFilenameSS4'] = $dbFileData['FileFilename'] ?? 'none';
            $this->intel['DBFilenameSS3'] = $dbFileData['Filename'] ?? 'none';;
            $this->intel['ErrorInFilename'] = $this->intel['Path'] !== $this->intel['DBPath'];
            $ss3FileName = $dbFileData['Filename'] ?? '';
            if ('assets/' === substr((string) $ss3FileName, 0, strlen('assets/'))) {
                $ss3FileName = substr((string) $ss3FileName, strlen('assets/'));
            }

            $this->intel['ErrorInSs3Ss4Comparison'] = $this->intel['DBFilenameSS3'] && $this->intel['DBFilenameSS4'] !== $this->intel['DBFilenameSS3'];
            $time = strtotime((string) $dbFileData['LastEdited']);
            $this->intel['ErrorParentID'] = true;
            if (0 === (int) $this->intel['FolderID']) {
                $this->intel['ErrorParentID'] = (bool) (int) $dbFileData['ParentID'];
            } elseif ($this->intel['FolderID']) {
                $this->intel['ErrorParentID'] = (int) $this->intel['FolderID'] !== (int) $dbFileData['ParentID'];
            }
        }

        $this->intel['ErrorDBNotPresent'] = $this->intel['ErrorDBNotPresentLive'] && $this->intel['ErrorDBNotPresentStaging'];

        $this->intel['DBLastEditedTS'] = $time;
        $this->intel['DBLastEdited'] = DBDate::create_field(DBDatetime::class, $time)->Ago();

        $this->intel['DBTitleFirstLetter'] = strtoupper(substr((string) $this->intel['DBTitle'], 0, 1));
    }

    protected function addHumanValues()
    {
        $this->intel['HumanImageDimensions'] = $this->intel['ImageWidth'] . 'px wide by ' . $this->intel['ImageHeight'] . 'px high';
        $this->intel['HumanImageIsImage'] = $this->intel['IsImage'] ? 'Is image' : 'Is not an image';
        $this->intel['HumanErrorExtensionMisMatch'] = $this->intel['ErrorExtensionMisMatch'] ?
            'irregular extension' : 'normal extension';

        //file size
        $this->intel['HumanFileSize'] = $this->humanFileSize($this->intel['PathFileSize']);
        $this->intel['HumanFileSizeRounded'] = '~ ' . $this->humanFileSize(round($this->intel['PathFileSize'] / 204800) * 204800);
        $this->intel['HumanErrorIsInFileSystem'] = $this->intel['ErrorIsInFileSystem'] ? 'File does not exist' : 'File exists';

        $this->intel['HumanFolderIsInOrder'] = $this->intel['FolderID'] ? 'In sub-folder' : 'In root folder';

        $this->intel['HumanErrorInFilename'] = $this->intel['ErrorInFilename'] ? 'Error in filename case' : 'No error in filename case';
        $this->intel['HumanErrorParentID'] = $this->intel['ErrorParentID'] ? 'Error in folder ID' : 'Perfect folder ID';
        $stageDBStatus = $this->intel['ErrorDBNotPresentStaging'] ? 'Is not on draft site' : ' Is on draft site';
        $liveDBStatus = $this->intel['ErrorDBNotPresentLive'] ? 'Is not on live site' : ' Is on live site';
        $this->intel['HumanErrorDBNotPresent'] = $stageDBStatus . ', ' . $liveDBStatus;
        $this->intel['HumanErrorInSs3Ss4Comparison'] = $this->intel['ErrorInSs3Ss4Comparison'] ?
            'Filename and FileFilename do not match' : 'Filename and FileFilename match';
        $this->intel['HumanIcon'] = File::get_icon_for_extension($this->intel['PathExtensionAsLower']);
    }

    protected function addCalculatedValues()
    {
        $this->intel['ErrorHasAnyError'] = false;
        foreach ($this->errorFields as $field) {
            if ($this->intel[$field]) {
                $this->intel['ErrorHasAnyError'] = true;
            }
        }
    }

    protected function getCacheKey(): string
    {
        return $this->pathHash;
    }
}
