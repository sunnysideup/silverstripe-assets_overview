<?php

namespace Sunnysideup\AssetsOverview\Traits;

use SilverStripe\Control\Director;

trait FilesystemRelatedTraits
{
    /**
     * @var string
     */
    protected $baseFolder = '';

    /**
     * @var string
     */
    protected $publicBaseFolder = '';

    /**
     * @var string
     */
    protected $assetsBaseFolder = '';

    /**
     * @param  int     $bytes    [description]
     * @param  integer $decimals [description]
     * @return string            [description]
     */
    protected function humanFileSize(int $bytes, int $decimals = 0): string
    {
        return File::format_size($bytes);

        // $size = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        // $factor = floor((strlen($bytes) - 1) / 3);
        //
        // return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }

    protected function getExtension(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * @return string
     */
    protected function getBaseFolder(): string
    {
        if (! $this->baseFolder) {
            $this->baseFolder = rtrim(Director::baseFolder(), DIRECTORY_SEPARATOR);
        }
        return $this->baseFolder;
    }

    /**
     * @return string
     */
    protected function getPublicBaseFolder(): string
    {
        if (! $this->publicBaseFolder) {
            $this->publicBaseFolder = rtrim(Director::publicFolder(), DIRECTORY_SEPARATOR);
        }
        return $this->publicBaseFolder;
    }

    /**
     * @return string
     */
    protected function getAssetsBaseFolder(): string
    {
        if (! $this->assetsBaseFolder) {
            $this->assetsBaseFolder = rtrim(ASSETS_PATH, DIRECTORY_SEPARATOR);
        }
        return $this->assetsBaseFolder;
    }
}
