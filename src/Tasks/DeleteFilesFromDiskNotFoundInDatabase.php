<?php

namespace Vendor\Sunnysideup\AssetsOverview\Tasks;

use Exception;
use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;

class DeleteFilesFromDiskNotFoundInDatabase extends BuildTask
{
    protected $title = 'Delete files from disk not found in database';

    protected $description = 'This task will go through all files in assets and delete ones that are not in the database.';

    private static $segment = 'delete-files-from-disk-not-found-in-database';

    protected $dryRun = true;

    public function run($request)
    {
        $this->dryRun = $request && $request->getVar('forreal') ? false : true;
        if (! $this->dryRun) {
            if (! Director::is_cli()) {
                echo 'This task can only be run on the command line.';

                return;
            }
        }

        $this->dryRunMessage();
        $files = AllFilesInfo::inst()->getAllFiles();
        DB::alteration_message('=== START ===');
        foreach ($files as $file) {
            $array = $file->toMap();
            if (! empty($array['ErrorDBNotPresent'])) {
                if ($this->isProtectedOrHidden((string) ($array['Path'] ?? ''))) {
                    DB::alteration_message('--- Skipping protected / hidden file: ' . Convert::raw2xml((string) ($array['Path'] ?? '')), 'changed');

                    continue;
                }

                DB::alteration_message('--- Deleting from disk: ' . Convert::raw2xml($array['Path']), 'deleted');
                if ($this->dryRun) {
                    DB::alteration_message('--- --- DRY RUN ONLY ---', 'deleted');
                } else {
                    try {
                        $base = realpath(ASSETS_PATH);
                        $real = realpath((string) $array['AbsolutePath']);
                        if (false === $base || false === $real || 0 !== strpos($real, $base . DIRECTORY_SEPARATOR) || ! is_file($real)) {
                            DB::alteration_message('Error: not a file inside the assets folder, not deleting', 'deleted');
                        } else {
                            unlink($real);
                        }
                    } catch (Exception $e) {
                        DB::alteration_message('Error: ' . $e->getMessage(), 'deleted');
                    }
                }
            } else {
                echo '✓';
            }
        }
        echo PHP_EOL;
        DB::alteration_message('=== END ===');

        $this->dryRunMessage();
        DB::alteration_message('=== DONE ===', '');
    }

    /**
     * dotfiles (e.g. .htaccess) and anything in a folder that starts with a dot (e.g. .protected) are never deleted.
     */
    protected function isProtectedOrHidden(string $path): bool
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ('' !== $segment && '.' === $segment[0]) {
                return true;
            }
        }

        return false;
    }

    protected function dryRunMessage()
    {
        if ($this->dryRun) {
            DB::alteration_message('Please set forreal=true to actually do this', 'created');
        } else {
            DB::alteration_message('Doing it for real!', 'created');
        }
    }
}
