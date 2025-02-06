<?php

namespace Vendor\Sunnysideup\AssetsOverview\Tasks;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use Sunnysideup\AssetsOverview\Api\AddAndRemoveFromDb;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;

class AddAllFilesFromDiskToDB extends BuildTask
{

    protected $title = 'Add all files from disk to database';

    protected $description = 'This task will add all files from the disk to the database.';

    private static $segment = 'add-all-files-from-disk-to-db';

    protected $dryRun = true;

    public function run($request)
    {
        $this->dryRun = $request->getVar('forreal') ? false : true;
        $this->dryRunMessage();
        $allFilesInfoObj = AllFilesInfo::inst();
        $allFilesInfoObj->setLimit(99999999999);
        $allFilesInfoObj->setVerbose(true);
        $allFilesInfoObj->setNoCache(true);
        $files = $allFilesInfoObj->getFilesAsArrayList()->toArray();
        $obj = Injector::inst()->get(AddAndRemoveFromDb::class);
        $obj->setIsDryRun($this->dryRun);
        foreach ($files as $file) {
            $array = $file->toMap();
            $obj->run($array, 'add');
        }
        $this->dryRunMessage();
        DB::alteration_message('=== DONE ===', '');
    }

    protected function dryRunMessage()
    {
        if ($this->dryRun) {
            DB::alteration_message('Please set forreal=true to actually add the files to the database', 'created');
        } else {
            DB::alteration_message('Adding all files from disk to database and removing files from database that are not on disk.', 'created');
        }
    }
}
