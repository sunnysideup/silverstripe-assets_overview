<?php

namespace Vendor\Sunnysideup\AssetsOverview\Tasks;

use Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use Sunnysideup\AssetsOverview\Api\AddAndRemoveFromDb;
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
        $this->dryRunMessage();
        $files = AllFilesInfo::inst()->getAllFiles();
        DB::alteration_message('=== START ===');
        foreach ($files as $file) {
            $array = $file->toMap();
            if (!empty($array['ErrorDBNotPresent'])) {
                DB::alteration_message('--- Deleting from DB' . $array['Path'], 'deleted');
                if ($this->dryRun) {
                    DB::alteration_message('--- --- DRY RUN ONLY ---', 'deleted');
                } else {
                    try {
                        unlink($array['AbsolutePath']);
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

    protected function dryRunMessage()
    {
        if ($this->dryRun) {
            DB::alteration_message('Please set forreal=true to actually do this', 'created');
        } else {
            DB::alteration_message('Doing it for real!', 'created');
        }
    }
}
