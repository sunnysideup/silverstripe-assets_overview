<?php

namespace Vendor\Sunnysideup\AssetsOverview\Tasks;

use Override;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Manifest\ClassLoader;
use SilverStripe\Core\Manifest\ClassManifest;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\Connect\DBSchemaManager;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class ConvertLegacyFilesToAssets extends BuildTask
{
    protected static string $commandName = 'update-html-references';

    //double %% is required for the query to work
    private const string CONTENT_QUERY_TEMPLATE = 'SELECT ID FROM "%s" WHERE "%s" IS NOT NULL AND "%s" != \'\' AND "%s" LIKE \'%%____PATH____%%\'';

    protected string $title = 'HTML file Reference Updater';

    protected static string $description = 'Updates HTML file references from a specific path (e.g. /my-legacy-images/) to SilverStripe shortcodes';

    private int $processedCount = 0;

    private int $updatedCount = 0;

    private int $errorCount = 0;

    private array $conversions = [];

    private array $warnings = [];

    private array $errors = [];

    private static string $old_path = '/my-legacy-images/';

    private bool $forreal = false;

    private bool $testonly = false;

    private PolyOutput $output;

    #[Override]
    public function getOptions(): array
    {
        return [
            new InputOption(
                'forreal',
                'f',
                InputOption::VALUE_NONE,
                'Execute the task for real (not a dry run)'
            ),
            new InputOption(
                'testonly',
                't',
                InputOption::VALUE_NONE,
                'Run in test mode (only process 100 items)'
            ),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->output = $output;

        if ($input->getOption('forreal')) {
            $this->forreal = true;
            $output->writeln('Running in "for real" mode. Changes will be saved to the database.');
        } else {
            $output->writeln('Running in "dry run" mode. No changes will be saved to the database.');
        }

        if ($input->getOption('testonly')) {
            $this->testonly = true;
            $output->writeln('Running in "test only" mode. We will run only 100 updates.');
        }

        $classesToCheck = $this->getClassesToCheck();
        $classesWithContent = $this->getContentClasses($classesToCheck);
        unset($classesToCheck);

        $contentQuery = $this->getContentQuery($classesWithContent);
        unset($classesWithContent);

        $allIDs = $this->getContentIds($contentQuery);
        unset($contentQuery);
        if (empty($allIDs)) {
            $output->writeln('No content found to update.');
            return Command::SUCCESS;
        }

        $output->writeln('Found ' . count($allIDs) . ' content items to update.');
        $output->writeln('---------------------------------');

        foreach ($allIDs as $className => $fieldNameAndIds) {
            foreach ($fieldNameAndIds as $fieldName => $idList) {
                foreach ($idList as $id) {
                    if ($this->testonly && $this->processedCount >= 100) {
                        $output->writeln('Test only mode: Stopping after 100 updates.');
                        break 3;
                    }

                    $this->processedCount++;
                    $obj = $className::get()->setUseCache(true)->byID($id);
                    if ($obj && $obj->$fieldName) {
                        $content = $obj->$fieldName;
                        if ($content) {
                            $output->writeln("====================");
                            $updatedContent = $this->updateImageReferences($content, $className, $id, $fieldName);
                            $updatedContent = $this->updatePDFReferences($updatedContent, $className, $id, $fieldName);
                            $warningKey = $this->warningKeyMaker($className, $id, $fieldName);
                            $this->showErrorsAndWarnings('conversions', $warningKey);
                            $this->showErrorsAndWarnings('warnings', $warningKey);
                            $this->showErrorsAndWarnings('errors', $warningKey);

                            if ($content !== $updatedContent) {
                                // Update the object
                                $isModifiedOnDraft = $obj->hasMethod('isModifiedOnDraft') && $obj->isModifiedOnDraft();
                                $isPublished = $obj->hasMethod('isPublished') && $obj->isPublished();
                                $obj->$fieldName = $updatedContent;
                                if ($this->forreal) {
                                    if ($obj->hasMethod('writeToStage')) {
                                        $output->writeln(sprintf('Writing object with ID %s in class %s to stage', $id, $className));
                                        $obj->writeToStage(Versioned::DRAFT);
                                    } else {
                                        $output->writeln(sprintf('Writing object with ID %s in class %s', $id, $className));
                                        $obj->write();
                                    }
                                } else {
                                    $output->writeln(sprintf('Dry run: Updated content for %s ID %s', $className, $id));
                                }

                                if (! $isModifiedOnDraft && $isPublished) {
                                    if ($this->forreal) {
                                        $output->writeln(sprintf('Publishing object with ID %s in class %s', $id, $className));
                                        $obj->publishSingle();
                                    } else {
                                        $output->writeln(sprintf('Dry run: Published object with ID %s in class %s', $id, $className));
                                    }
                                }

                                $obj->flushCache();
                                $this->updatedCount++;
                            }
                        }
                    } else {
                        $output->writeln(sprintf('Error: Could not find object with ID %s in class %s', $id, $className));
                        $this->errorCount++;
                    }
                }
            }
        }

        $output->writeln('---------------------------------');
        $output->writeln('---------------------------------');
        $output->writeln('---------------------------------');
        $output->writeln("\nScan complete.");
        $output->writeln(sprintf('Processed objects: %d', $this->processedCount));
        $output->writeln(sprintf('Updated references: %d', $this->updatedCount));
        $output->writeln(sprintf('Errors: %d', $this->errorCount));
        $output->writeln('---------------------------------');
        $output->writeln('---------------------------------');
        $output->writeln('---------------------------------');
        $output->writeln('Conversions: ' . count($this->warnings));
        $this->printErrorsAndWarnings('conversions');
        $output->writeln('---------------------------------');
        $output->writeln('Warnings: ' . count($this->warnings));
        $this->printErrorsAndWarnings('warnings');
        $output->writeln('---------------------------------');
        $output->writeln('---------------------------------');
        $output->writeln('---------------------------------');
        $output->writeln('Errors: ' . count($this->errors));
        $this->printErrorsAndWarnings('errors');
        $output->writeln('---------------------------------');
        $output->writeln('---------------------------------');
        $output->writeln('---------------------------------');

        return Command::SUCCESS;
    }

    private function updateImageReferences($content, $className, $id, $fieldName)
    {
        $this->output->writeln("=== IMG");
        $warningKey = $this->warningKeyMaker($className, $id, $fieldName);
        $updatedContent = null;

        // Load HTML content
        $dom = new DOMDocument();
        // Suppress warnings from malformed HTML
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new DOMXPath($dom);

        // Find img tags with src containing "/my-legacy-images/"
        $path = $this->config()->get('old_path');
        $imgNodes = $xpath->query('//img[contains(@src, "' . $path . '")]');

        $changed = false;
        $replacements = [];
        $path = $this->config()->get('old_path');
        $patWithURL = Director::absoluteURL($path);
        /**
         * @var DOMElement $img
         */
        foreach ($imgNodes as $img) {

            $src = $img->getAttribute('src');
            // Skip if src is empty
            if (empty($src)) {
                $this->warnings[$warningKey][] = "Empty src attribute in img tag\n";
                continue;
            }

            if (!str_starts_with($src, '' . $path . '') && !str_contains($src, $patWithURL)) {
                $this->output->writeln(sprintf('FALSE src (%s) does not contain %s', $src, $path));
                continue;
            }

            $this->output->writeln(sprintf('Found img tag with src: %s', $src));

            // Extract filename from path (handle query parameters)
            $pathParts = parse_url($src);
            $path = $pathParts['path'];
            $filename = basename($path);
            // REMOVED FOR
            // echo "Found image: {$filename}\n";

            // Find corresponding File object
            // $file = Image::get()->filter('Name', $filename)->first();

            // Try searching by FileFilename as fallback
            $file = $this->getFromDatabaseOrLocalFile($filename, true);

            if ($file instanceof File) {
                // Get attributes from img tag
                $style = $img->getAttribute('style');
                $alt = $img->getAttribute('alt');
                $class = $img->getAttribute('class');
                $width = null;
                $height = null;

                // Extract width and height from query params if present
                if (isset($pathParts['query'])) {
                    parse_str($pathParts['query'], $query);
                    if (isset($query['width'])) {
                        $width = (float) $query['width'];
                    }

                    if (isset($query['height'])) {
                        $height = (float) $query['height'];
                    }
                }

                // If no width/height in query, try from inline style
                if ((! $width || ! $height) && $style) {
                    if (preg_match('/width: (\d+)px/', $style, $widthMatches)) {
                        $width = (float) $widthMatches[1];
                    }

                    if (preg_match('/height: (\d+)px/', $style, $heightMatches)) {
                        $height = (float) $heightMatches[1];
                    }
                }

                // If still no width/height, try from attributes
                if (! $width) {
                    $width = $img->getAttribute('width') ?: '';
                }

                if (! $height) {
                    $height = $img->getAttribute('height') ?: '';
                }

                $width = (float) $width;
                $width = round($width);
                $height = (float) $height;
                $height = round($height);

                // Create the shortcode
                $shortcode = '[image src="' . $file->getSourceURL() . '" id="' . $file->ID . '"';

                if ($width !== 0.0) {
                    $shortcode .= ' width="' . $width . '"';
                }

                if ($height !== 0.0) {
                    $shortcode .= ' height="' . $height . '"';
                }

                if ($class) {
                    $shortcode .= ' class="' . $class . ' ss-htmleditorfield-file image"';
                } else {
                    $shortcode .= ' class="leftAlone ss-htmleditorfield-file image"';
                }

                if ($style) {
                    $shortcode .= ' style="' . $style . '"';
                }

                if ($alt === 'undefined') {
                    $alt = Convert::raw2att($file->Title);
                }

                if ($alt) {
                    $shortcode .= ' alt="' . $alt . '"';
                }

                $shortcode .= ']';

                // Store the node and its replacement for later processing
                $replacements[] = [
                    'node' => $img,
                    'shortcode' => $shortcode,
                ];

                $changed = true;
                $this->output->writeln(sprintf('Found image: %s -> File ID: %d', $filename, $file->ID));
                $this->output->writeln(sprintf('Replacing with shortcode: %s', $shortcode));
            } else {
                $this->output->writeln(sprintf('Could not find matching file for %s', $filename));
                $this->errors[$warningKey][] = sprintf('Could not find matching file for %s', $filename);
            }

            $this->output->writeln('=');
        }

        // Replace nodes with shortcodes
        if ($changed) {
            // We need to manually replace in the original content because
            // replacing DOM nodes with text (shortcodes) is tricky
            $updatedContent = $content;

            foreach ($replacements as $replacement) {
                $imgNode = $replacement['node'];
                $shortcode = $replacement['shortcode'];

                // Get the HTML of the current img tag
                $imgHTML = $dom->saveHTML($imgNode);
                // Replace in the content
                $updatedContent = str_replace($imgHTML, $shortcode, $updatedContent);
            }

            if ($updatedContent === $content) {
                $this->errors[$warningKey][] = "No changes made to content.\n";
            } else {
                $this->output->writeln(sprintf('Updated content for %s ID %s', $className, $id));
                $this->conversions[$warningKey][] = "OK.\n";
            }
        }

        return $updatedContent ?: $content;
    }

    private function updatePDFReferences($content, $className, $id, $fieldName)
    {
        $this->output->writeln("=== PDF");
        $updatedContent = null;
        $warningKey = $this->warningKeyMaker($className, $id, $fieldName);

        // Load HTML content
        $dom = new DOMDocument();
        // Suppress warnings from malformed HTML
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new DOMXPath($dom);

        $path = $this->config()->get('old_path');

        $linkNodes = $xpath->query('//a[contains(@href, "' . $path . '")]');

        $changed = false;
        $replacements = [];

        /**
         * @var DOMElement $link
         */
        foreach ($linkNodes as $link) {
            $href = $link->getAttribute('href');

            // Skip if href is empty
            if (empty($href)) {
                $this->warnings[$warningKey][] = "Empty href attribute in a tag\n";
                continue;
            }

            // Skip if href does not contain "/my-legacy-images/"
            $path = $this->config()->get('old_path');
            $patWithURL = Director::absoluteURL($path);
            if (!str_starts_with($href, (string) $path) && !str_contains($href, $patWithURL)) {
                $this->output->writeln(sprintf('FALSE href (%s) does not contain %s', $href, $path));
                continue;
            }

            $this->output->writeln(sprintf('Found link tag with href: %s', $href));

            // Extract filename from path (handle query parameters)
            $pathParts = parse_url($href);
            $path = $pathParts['path'];
            $filename = basename($path);
            $this->output->writeln(sprintf('Found PDF: %s', $filename));

            // Find corresponding File object
            $file = $this->getFromDatabaseOrLocalFile($filename, false);

            if ($file instanceof File) {
                // Get the link text
                $linkText = $link->textContent;

                // Create the PDF shortcode wrapper
                $openingTag = '<a href="[file_link,id=' . $file->ID . ']">';
                $closingTag = '</a>';

                // Store the node and its replacement for later processing
                $replacements[] = [
                    'node' => $link,
                    'opening' => $openingTag,
                    'closing' => $closingTag,
                    'text' => $linkText,
                ];

                $changed = true;
                $this->output->writeln(sprintf('Found PDF: %s -> File ID: %d', $filename, $file->ID));
                $this->output->writeln(sprintf('Replacing with shortcode: %s%s%s', $openingTag, $linkText, $closingTag));
            } else {
                $this->output->writeln(sprintf('Could not find matching file for %s', $filename));
                $this->errors[$warningKey][] = sprintf('Could not find matching file for %s', $filename);
            }

            $this->output->writeln('=');
        }

        // Replace nodes with shortcodes
        if ($changed) {
            // We need to manually replace in the original content because
            // replacing DOM nodes with text (shortcodes) is tricky
            $updatedContent = $content;

            foreach ($replacements as $replacement) {
                $linkNode = $replacement['node'];
                $openingTag = $replacement['opening'];
                $closingTag = $replacement['closing'];
                $linkText = $replacement['text'];

                // Get the HTML of the current link tag
                $linkHTML = $dom->saveHTML($linkNode);

                // Replace in the content
                $updatedContent = str_replace(
                    $linkHTML,
                    $openingTag . $linkText . $closingTag,
                    $updatedContent
                );
            }

            if ($updatedContent === $content) {
                $this->errors[$warningKey][] = "No changes made to content.\n";
            } else {
                $this->conversions[$warningKey][] = "OK.\n";
            }
        }

        return $updatedContent ?: $content;
    }

    public function getFromDatabaseOrLocalFile($filename, $isImage = false): null|File|Image
    {
        // First try to find file in database
        if ($isImage) {
            $file = Image::get()->find('FileFilename:EndsWith', $filename);
        } else {
            $file = File::get()->find('FileFilename:EndsWith', $filename);
        }

        if ($file) {
            return $file;
        }

        // If not found in database, look for it in the assets directory
        $findDir = ASSETS_PATH;

        // Try to find the file in the assets directory
        $foundPath = $this->findFileInDirectory($findDir, $filename);

        if (! $foundPath) {
            // File not found in assets
            return null;
        }

        // Create a "Legacy" folder to store the imported file
        $folder = Folder::find_or_make('Legacy');

        // Create the appropriate file object
        $file = $isImage ? Image::create() : File::create();

        // Set the file contents from the found path
        $file->setFromLocalFile($foundPath, $filename);
        $file->setFilename($filename);
        $file->ParentID = $folder->ID;
        $file->write();
        $file->publishSingle();

        return $file;
    }

    /**
     * Recursively search for a file in a directory and its subdirectories
     *
     * @param string $directory Directory to search in
     * @param string $filename Filename to search for
     * @return string|null Path to the file if found, null otherwise
     */
    private function findFileInDirectory($directory, $filename)
    {
        $directory = rtrim($directory, '/');

        // Check if this is a valid directory
        if (! is_dir($directory)) {
            $this->output->writeln(sprintf('ERROR!!! Directory does not exist: %s', $directory));
            return null;
        }

        try {
            // Get all files in this directory and subdirectories
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            // Look for our file
            foreach ($iterator as $file) {
                // Skip directories and only check files
                if ($file->isFile() && basename((string) $file->getPathname()) === $filename) {
                    return $file->getPathname();
                }
            }
        } catch (Exception $exception) {
            // Log error but continue
            $this->output->writeln('ERROR!!! Error searching for file in directory: ' . $exception->getMessage());
        }

        return null;
    }

    protected static $_cacheFieldExists = [];

    protected static $_schema;

    protected function fieldExists(string $tableName, string $fieldName): bool
    {
        $key = $tableName;
        if (! isset($this->_cacheFieldExists[$key])) {
            $schema = $this->getSchema();
            $this->_cacheFieldExists[$key] = $schema->fieldList($tableName);
        }

        return $this->_cacheFieldExists[$key][$fieldName] ?? false;
    }

    protected function getSchema(): DBSchemaManager
    {
        if (null === $this->_schema) {
            $this->_schema = DB::get_schema();
        }

        return $this->_schema;
    }

    protected function getContentClasses($candidates)
    {
        $contentClasses = [];

        foreach ($candidates as $className) {
            $contentClasses[$className] = array_intersect(
                DataObject::getSchema()->databaseFields($className, false),
                ['HTMLText'],
            );
        }

        return array_filter($contentClasses);
    }

    protected array $siteTreeClasses = [];

    /**
     * Get classes that are descendents of SiteTree
     * @return array
     */
    protected function getSiteTreeClasses()
    {
        if ($this->siteTreeClasses === []) {
            $this->siteTreeClasses = $this->getManifest()->getDescendantsOf(SiteTree::class);
        }

        return $this->siteTreeClasses;
    }

    protected array $dataClasses = [];

    /**
     * Get classes that are descendents of DataObject
     * @return array
     */
    protected function getDataClasses()
    {
        if ($this->dataClasses === []) {
            $this->dataClasses = $this->getManifest()->getDescendantsOf(DataObject::class);
        }

        return $this->dataClasses;
    }

    protected function getVersionedtableName($className)
    {
        $table = DataObject::getSchema()->tableName($className);

        if ($className::has_extension('Versioned')) {
            return $table . '_Versions';
        } else {
            return $table;
        }
    }

    /**
     * Build a set of queries to get content of all HTMLText fields
     *
     * @param array $contentClasses Classes and their HTMLText fields
     */
    protected function getContentQuery($contentClasses): array
    {
        $queries = [];
        foreach ($contentClasses as $className => $fields) {
            foreach ($fields as $fieldName => $type) {
                $query = str_replace('____PATH____', $this->config()->get('old_path'), self::CONTENT_QUERY_TEMPLATE);
                $queries[$className][$fieldName] = sprintf(
                    $query,
                    $this->getVersionedTableName($className),
                    $fieldName,
                    $fieldName,
                    $fieldName,
                );
            }
        }

        return $queries;
    }

    /**
     * Get the IDs of all image and file references in HTML content
     * @param array $queries Array of SQL queries to get the content fields
     * @return array
     */
    protected function getContentIds($queries)
    {
        $allIds = [];
        foreach ($queries as $className => $queryies) {
            foreach ($queryies as $field => $query) {
                $outcome = array_unique(DB::query($query)->column());
                if ($outcome === []) {
                    continue;
                }

                if (isset($allIds[$className][$field])) {
                    $allIds[$className] = [];
                }

                $allIds[$className][$field] = $outcome;
            }
        }

        return $allIds;
    }

    /**
     * Get all the candidate classes to check for File or Image references.
     */
    protected function getClassesToCheck(): array
    {
        $classes =
            array_unique(
                array_merge(
                    $this->getSiteTreeClasses(),
                    $this->getDataClasses(),
                ),
            );

        sort($classes);

        return $classes;
    }

    protected $manifest;

    /**
     * @return ClassManifest
     */
    protected function getManifest()
    {
        if (is_null($this->manifest)) {
            $this->manifest = ClassLoader::inst()->getManifest();
        }

        return $this->manifest;
    }

    protected function showErrorsAndWarnings($propertyName, $warningKey)
    {
        $list = $this->$propertyName;
        $var = $warningKey;
        if (! empty($list[$var])) {
            foreach ($list[$var] as $message) {
                $this->output->writeln(strtoupper((string) $propertyName) . ': ' . $message);
            }
        } elseif (isset($list[$var])) {
            unset($list[$var]);
        }
    }

    protected function printErrorsAndWarnings($propertyName)
    {
        foreach ($this->$propertyName as $warningKey => $messages) {
            $list = $this->$propertyName;
            $var = $warningKey;
            if (! empty($list[$var])) {
                $vars = explode(',', (string) $warningKey);
                $className = $vars[0];
                $id = $vars[1];
                $fieldName = $vars[2];
                $obj = $className::get()->setUseCache(true)->byID($id);
                if ($obj) {
                    $this->output->writeForHtml(
                        ($obj->getTitle() ?: '[NO TITLE]') . '|'
                        . ($fieldName ?: '[NO FIELD NAME]') . '|'
                        . ($obj->hasMethod('CMSEditLink') ? $obj->CMSEditLink() : '[NO CMS LINK]') . '|'
                        . ($obj->hasMethod('Link') ? $obj->Link() : '[NO LINK]')
                    );
                } else {
                    $this->output->writeln(sprintf('Could not find object with ID %s in class %s and field: %s', $id, $className, $fieldName));
                }

                foreach ($messages as $message) {
                    $this->output->writeln(' --- ' . strtoupper($fieldName) . ': ' . $message);
                }
            } else {
                $this->output->writeln(sprintf('ERROR: No values for %s', $warningKey));
            }
        }
    }

    protected function warningKeyMaker($className, $id, $fieldName)
    {
        return $className . ',' . $id . ',' . $fieldName;
    }
}
