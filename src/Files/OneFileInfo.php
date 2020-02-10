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
use SilverStripe\ORM\FieldType\DBDate;

use Sunnysideup\AssetsOverview\Interfaces\FileInfo;
use Sunnysideup\AssetsOverview\Traits\FilesystemRelatedTraits;

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
     * @var bool
     */
    protected $fileExists = false;

    /**
     * @param string $absoluteLocation [description]
     * @param ?bool  $fileExists       [description]
     */
    public function __construct(string $absoluteLocation, ?bool $fileExists)
    {
        $this->path = $absoluteLocation;
        $this->hash = md5_file($this->path);
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
            $intel = [];
            $pathParts = [];
            if ($this->fileExists) {
                $pathParts = pathinfo($this->path);
            }
            $pathParts['extension'] = $pathParts['extension'] ?? '--no-extension';
            $pathParts['filename'] = $pathParts['filename'] ?? '--no-file-name';
            $pathParts['dirname'] = $pathParts['dirname'] ?? '--no-parent-dir';
            $relativeDirFromBaseFolder = str_replace($this->getBaseFolder(), '', $pathParts['dirname']);
            $relativeDirFromAssetsFolder = str_replace($this->getAssetsBaseFolder(), '', $pathParts['dirname']);

            $intel['Extension'] = $pathParts['extension'];
            $intel['ExtensionAsLower'] = (string) strtolower($intel['Extension']);
            $intel['HasIrregularExtension'] = $intel['Extension'] !== $intel['ExtensionAsLower'];
            $intel['HumanHasIrregularExtension'] = $intel['HasIrregularExtension'] ?
                'irregular extension' : 'normal extension';
            $intel['FileName'] = $pathParts['filename'];

            $intel['FileSize'] = 0;

            $intel['Path'] = $this->path;
            $intel['PathFromAssets'] = str_replace($this->getAssetsBaseFolder(), '', $this->path);
            $intel['PathFromRoot'] = str_replace($this->getBaseFolder(), '', $this->path);
            $intel['FirstLetter'] = strtoupper(substr($intel['FileName'], 0, 1));
            $intel['FileNameInDB'] = ltrim($intel['PathFromAssets'], DIRECTORY_SEPARATOR);

            $intel['FolderName'] = trim($relativeDirFromBaseFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $intel['FolderNameShort'] = trim($relativeDirFromAssetsFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $intel['GrandParentFolder'] = dirname($intel['FolderNameShort']);

            $intel['HumanImageDimensions'] = 'n/a';
            $intel['Ratio'] = '0';
            $intel['Pixels'] = 'n/a';
            $intel['IsImage'] = false;
            $intel['HumanIsImage'] = 'Is not an image';
            $intel['IsRegularImage'] = false;
            $intel['IsInFileSystem'] = false;
            $intel['HumanIsInFileSystem'] = 'file does not exist';
            $intel['ErrorParentID'] = true;
            $intel['Type'] = $intel['Extension'];
            $intel['Attribute'] = 'n/a';

            if ($this->fileExists) {
                $intel['IsInFileSystem'] = true;
                $intel['HumanIsInFileSystem'] = 'file exists';
                $intel['FileSize'] = filesize($this->path);
                $intel['IsRegularImage'] = $this->isRegularImage($intel['Extension']);
                if ($intel['IsRegularImage']) {
                    $intel['IsImage'] = true;
                } else {
                    $intel['IsImage'] = $this->isImage($this->path);
                }
                if ($intel['IsImage']) {
                    list($width, $height, $type, $attr) = getimagesize($this->path);
                    $intel['Attribute'] = print_r($attr, 1);
                    $intel['HumanImageDimensions'] = $width . 'px wide by ' . $height . 'px high';
                    $intel['Ratio'] = round($width / $height, 3);
                    $intel['Pixels'] = $width * $height;
                    $intel['HumanIsImage'] = 'Is Image';
                    $intel['Type'] = $type;
                }
            }

            $intel['HumanFileSize'] = $this->humanFileSize($intel['FileSize']);
            $intel['HumanFileSizeRounded'] = '~ ' . $this->humanFileSize(round($intel['FileSize'] / 1024) * 1024);
            $file = DataObject::get_one(File::class, ['FileFilename' => $intel['FileNameInDB']]);
            $folder = null;
            if ($file) {
                $folder = DataObject::get_one(Folder::class, ['ID' => $file->ParentID]);
            }

            //backup for folder
            if (! $folder) {
                $folder = DataObject::get_one(Folder::class, ['FileFilename' => $intel['FolderName']]);
            }

            //backup for file ...
            if (! $file) {
                if ($folder) {
                    $nameInDB = $intel['FileName'] . '.' . $intel['Extension'];
                    $file = DataObject::get_one(File::class, ['Name' => $nameInDB, 'ParentID' => $folder->ID]);
                }
            }
            $time = 0;
            if ($file) {
                $intel['ID'] = $file->ID;
                $intel['ParentID'] = $file->ParentID;
                $intel['IsInDatabase'] = true;
                $intel['CMSEditLink'] = '/admin/assets/EditForm/field/File/item/' . $file->ID . '/edit';
                $intel['DBTitle'] = $file->Title;
                $intel['ErrorInFilenameCase'] = $intel['FileNameInDB'] !== $file->Filename;
                $time = strtotime($file->LastEdited);
                if ($folder) {
                    $intel['ErrorParentID'] = (int) $folder->ID !== (int) $file->ParentID;
                } elseif ((int) $file->ParentID === 0) {
                    $intel['ErrorParentID'] = false;
                }
            } else {
                $intel['ID'] = 0;
                $intel['ParentID'] = 0;
                $intel['IsInDatabase'] = false;
                $intel['CMSEditLink'] = '/admin/assets/';
                $intel['DBTitle'] = '-- no title set in database';
                $intel['ErrorInFilenameCase'] = true;
                if ($this->fileExists) {
                    $time = filemtime($this->path);
                }
            }
            if ($folder) {
                if (! $file) {
                    $intel['ParentID'] = $folder->ID;
                }
                $intel['HasFolder'] = true;
                $intel['HumanHasFolder'] = 'in sub-folder';
                $intel['CMSEditLinkFolder'] = '/admin/assets/show/' . $folder->ID;
            } else {
                $intel['HasFolder'] = false;
                $intel['HumanHasFolder'] = 'in root folder';
                $intel['CMSEditLinkFolder'] = '/assets/admin/';
            }

            $intel['LastEditedTS'] = $time;
            $intel['LastEdited'] = DBDate::create_field('Date', $time)->Ago();
            $intel['HumanIsInDatabase'] = $intel['IsInDatabase'] ? 'In Database' : 'Not in Database';
            $intel['HumanErrorInFilenameCase'] = $intel['ErrorInFilenameCase'] ? 'Error in Case' : 'Perfect Case';
            $intel['HumanErrorParentID'] = $intel['ErrorParentID'] ? 'Error in folder ID' : 'Perfect Folder ID';
            $intel['FirstLetterDBTitle'] = strtoupper(substr($intel['DBTitle'], 0, 1));
            $fullArrayString = serialize($intel);
            $cache->set($cachekey, $fullArrayString);
        } else {
            $fullArrayString = $cache->get($cachekey);
            $intel = unserialize($fullArrayString);
        }

        return $intel;
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
