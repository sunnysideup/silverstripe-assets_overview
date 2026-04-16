<?php

namespace Vendor\Sunnysideup\AssetsOverview\Tasks;

use Exception;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Sunnysideup\AssetsOverview\Files\AllFilesInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class DeleteFilesFromDiskNotFoundInDatabase extends BuildTask
{
    protected string $title = 'Delete files from disk not found in database';

    protected static string $description = 'This task will go through all files in assets and delete ones that are not in the database.';

    protected static string $commandName = 'delete-files-from-disk-not-found-in-database';

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
        $output->writeln('=== START ===');
        foreach ($files as $file) {
            $array = $file->toMap();
            if (! empty($array['ErrorDBNotPresent'])) {
                $output->writeln('--- Deleting from DB' . $array['Path']);
                if ($this->dryRun) {
                    $output->writeln('--- --- DRY RUN ONLY ---');
                } else {
                    try {
                        unlink($array['AbsolutePath']);
                    } catch (Exception $e) {
                        $output->writeln('Error: ' . $e->getMessage());
                    }
                }
            } else {
                $output->write('✓');
            }
        }

        $output->writeln('');
        $output->writeln('=== END ===');

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
