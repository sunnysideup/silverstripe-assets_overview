<?php

namespace Vendor\Sunnysideup\AssetsOverview\Tasks;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Sunnysideup\AssetsOverview\Api\AddAndRemoveFromDb;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class AddAllFilesFromDiskToDB extends BuildTask
{
    protected string $title = 'Add all files from disk to database';

    protected static string $description = 'This task will add all files from the disk to the database.';

    protected static string $commandName = 'add-all-files-from-disk-to-db';

    protected bool $dryRun = true;

    public function getOptions(): array
    {
        return [
            new InputOption(
                'forreal',
                'f',
                InputOption::VALUE_NONE,
                'Execute the task for real (not a dry run)'
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->dryRun = !$input->getOption('forreal');
        $this->dryRunMessage($output);

        $files = AllFilesInfo::inst()->getAllFiles();
        $obj = Injector::inst()->get(AddAndRemoveFromDb::class);
        $obj->setIsDryRun($this->dryRun);
        foreach ($files as $file) {
            $array = $file->toMap();
            $obj->run($array, 'add');
        }

        $this->dryRunMessage($output);

        return Command::SUCCESS;
    }

    protected function dryRunMessage(PolyOutput $output): void
    {
        if ($this->dryRun) {
            $output->writeln('Please set --forreal to actually do this');
        } else {
            $output->writeln('Doing it for real!');
        }
    }
}
