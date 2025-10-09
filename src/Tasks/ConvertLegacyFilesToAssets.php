<?php

namespace Vendor\Sunnysideup\AssetsOverview\Tasks;


use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SilverStripe\Assets\Folder;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Manifest\ClassLoader;
use SilverStripe\Core\Manifest\ClassManifest;
use SilverStripe\ORM\Connect\DBSchemaManager;
use SilverStripe\Versioned\Versioned;

class ConvertLegacyFilesToAssets extends BuildTask
{
    private static $segment = 'update-html-references';

    //double %% is required for the query to work
    private const CONTENT_QUERY_TEMPLATE = 'SELECT ID FROM "%s" WHERE "%s" IS NOT NULL AND "%s" != \'\' AND "%s" LIKE \'%%____PATH____%%\'';

    protected $title = 'HTML file Reference Updater';

    protected $description = 'Updates HTML file references from a specific path (e.g. /my-legacy-images/) to SilverStripe shortcodes';

    private int $processedCount = 0;
    private int $updatedCount = 0;
    private int $errorCount = 0;
    private array $conversions = [];
    private array $warnings = [];
    private array $errors = [];

    private static string $old_path = '/my-legacy-images/';

    private bool $forreal = false;
    private bool $testonly = false;

    public function run($request)
    {
        if ($request->getVar('forreal')) {
            $this->forreal = true;
            DB::alteration_message('Running in "for real" mode. Changes will be saved to the database.', 'good');
        } else {
            DB::alteration_message('Running in "dry run" mode. No changes will be saved to the database.', 'warning');
        }
        if ($request->getVar('testonly')) {
            $this->testonly = true;
            DB::alteration_message('Running in "test only" mode. We will run only five updates.', 'warning');
        }
        $classesToCheck = $this->getClassesToCheck();
        $classesWithContent = $this->getContentClasses($classesToCheck);
        unset($classesToCheck);

        $contentQuery = $this->getContentQuery($classesWithContent);
        unset($classesWithContent);

        $allIDs = $this->getContentIds($contentQuery);
        unset($contentQuery);
        if (empty($allIDs)) {
            DB::alteration_message('No content found to update.', 'good');
            return;
        }
        DB::alteration_message('Found ' . count($allIDs) . ' content items to update.', 'good');
        echo '---------------------------------' . PHP_EOL;

        foreach ($allIDs as $className => $fieldNameAndIds) {
            foreach ($fieldNameAndIds as $fieldName => $idList) {
                foreach ($idList as $id) {
                    if ($this->testonly && $this->processedCount >= 100) {
                        DB::alteration_message('Test only mode: Stopping after 5 updates.', 'warning');
                        break 3;
                    }
                    $this->processedCount++;
                    $obj = DataObject::get_by_id($className, $id);
                    if ($obj && $obj->$fieldName) {
                        $content = $obj->$fieldName;
                        if ($content) {
                            echo "====================\n";
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
                                        DB::alteration_message("Writing object with ID {$id} in class {$className} to stage");
                                        $obj->writeToStage(Versioned::DRAFT);
                                    } else {
                                        DB::alteration_message("Writing object with ID {$id} in class {$className}");
                                        $obj->write();
                                    }
                                } else {
                                    DB::alteration_message("Dry run: Updated content for {$className} ID {$id}", 'good');
                                }
                                if ($isModifiedOnDraft !== true && $isPublished) {
                                    if ($this->forreal) {
                                        DB::alteration_message("Publishing object with ID {$id} in class {$className}");
                                        $obj->publishSingle();
                                    } else {
                                        DB::alteration_message("Dry run: Published object with ID {$id} in class {$className}", 'good');
                                    }
                                }
                                $obj->flushCache();
                                $this->updatedCount++;
                            }
                        }
                    } else {
                        echo "Error: Could not find object with ID {$id} in class {$className}\n";
                        $this->errorCount++;
                    }
                }
            }
        }
        echo '---------------------------------' . PHP_EOL;
        echo '---------------------------------' . PHP_EOL;
        echo '---------------------------------' . PHP_EOL;
        echo "\nScan complete.\n";
        echo "Processed objects: {$this->processedCount}\n";
        echo "Updated references: {$this->updatedCount}\n";
        echo "Errors: {$this->errorCount}\n";
        echo "---------------------------------" . PHP_EOL;
        echo "---------------------------------" . PHP_EOL;
        echo "---------------------------------" . PHP_EOL;
        echo "Conversions: " . count($this->warnings) . "\n";
        $this->printErrorsAndWarnings('conversions');
        echo "---------------------------------" . PHP_EOL;
        echo "Warnings: " . count($this->warnings) . "\n";
        $this->printErrorsAndWarnings('warnings');
        echo "---------------------------------" . PHP_EOL;
        echo "---------------------------------" . PHP_EOL;
        echo "---------------------------------" . PHP_EOL;
        echo "Errors: " . count($this->errors) . "\n";
        $this->printErrorsAndWarnings('errors');
        echo "---------------------------------" . PHP_EOL;
        echo "---------------------------------" . PHP_EOL;
        echo "---------------------------------" . PHP_EOL;
    }


    private function updateImageReferences($content, $className, $id, $fieldName)
    {
        echo "=== IMG \n";
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

            if (strpos($src, '' . $path . '') !== 0 && strpos($src, $patWithURL) === false) {
                echo "FALSE src ($src) does not contain $path \n";
                continue;
            }

            echo "Found img tag with src: {$src}\n";

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

            if ($file) {
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
                if ((!$width || !$height) && $style) {
                    if (preg_match('/width: (\d+)px/', $style, $widthMatches)) {
                        $width = (float) $widthMatches[1];
                    }
                    if (preg_match('/height: (\d+)px/', $style, $heightMatches)) {
                        $height = (float) $heightMatches[1];
                    }
                }

                // If still no width/height, try from attributes
                if (!$width) {
                    $width = $img->getAttribute('width') ?: '';
                }
                if (!$height) {
                    $height = $img->getAttribute('height') ?: '';
                }
                $width = (float) $width;
                $width = round($width);
                $height = (float) $height;
                $height = round($height);

                // Create the shortcode
                $shortcode = '[image src="' . $file->getSourceURL() . '" id="' . $file->ID . '"';

                if ($width) {
                    $shortcode .= ' width="' . $width . '"';
                }
                if ($height) {
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
                    'shortcode' => $shortcode
                ];

                $changed = true;
                echo "Found image: {$filename} -> File ID: {$file->ID}\n";
                echo "Replacing with shortcode: {$shortcode}\n";
            } else {
                echo "Could not find matching file for {$filename}\n";
                $this->errors[$warningKey][] = "Could not find matching file for {$filename}\n";
            }
            echo "=" . PHP_EOL;
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
                echo "Updated content for {$className} ID {$id}\n";
                $this->conversions[$warningKey][] = "OK.\n";
            }
            $updatedContent;
        }
        return $updatedContent ?: $content;
    }


    private function updatePDFReferences($content, $className, $id, $fieldName)
    {
        echo "=== PDF\n";
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
            if (strpos($href, $path) !== 0 && strpos($href, $patWithURL) === false) {
                echo "FALSE href ($href) does not contain $path\n";
                continue;
            }

            echo "Found link tag with href: {$href}\n";

            // Extract filename from path (handle query parameters)
            $pathParts = parse_url($href);
            $path = $pathParts['path'];
            $filename = basename($path);
            echo "Found PDF: {$filename}\n";

            // Find corresponding File object
            $file = $this->getFromDatabaseOrLocalFile($filename, false);

            if ($file) {
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
                    'text' => $linkText
                ];

                $changed = true;
                echo "Found PDF: {$filename} -> File ID: {$file->ID}\n";
                echo "Replacing with shortcode: {$openingTag}{$linkText}{$closingTag}\n";
            } else {
                echo "Could not find matching file for {$filename}\n";
                $this->errors[$warningKey][] = "Could not find matching file for {$filename}\n";
            }
            echo "=" . PHP_EOL;
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

        if (!$foundPath) {
            // File not found in assets
            return null;
        }


        // Create a "Legacy" folder to store the imported file
        $folder = Folder::find_or_make('Legacy');

        // Create the appropriate file object
        if ($isImage) {
            $file = Image::create();
        } else {
            $file = File::create();
        }

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
        if (!is_dir($directory)) {
            echo "ERROR!!! Directory does not exist: $directory\n";
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
                if ($file->isFile() && basename($file->getPathname()) === $filename) {
                    return $file->getPathname();
                }
            }
        } catch (Exception $e) {
            // Log error but continue
            echo "ERROR!!! Error searching for file in directory: " . $e->getMessage() . "\n";
        }

        return null;
    }




    protected static $_cacheFieldExists = [];
    protected static $_schema = null;

    protected function fieldExists(string $tableName, string $fieldName): bool
    {
        $key = $tableName;
        if (!isset($this->_cacheFieldExists[$key])) {
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

        $contentClasses = array_filter($contentClasses);

        return $contentClasses;
    }

    protected array $siteTreeClasses = [];

    /**
     * Get classes that are descendents of SiteTree
     * @return array
     */
    protected function getSiteTreeClasses()
    {
        if (empty($this->siteTreeClasses)) {
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
        if (empty($this->dataClasses)) {
            $this->dataClasses = $this->getManifest()->getDescendantsOf(DataObject::class);
        }

        return $this->dataClasses;
    }


    protected function getVersionedtableName($className)
    {
        $table = DataObject::getSchema()->tableName($className);

        if ($className::has_extension('Versioned')) {
            return "{$table}_Versions";
        } else {
            return $table;
        }
    }


    /**
     * Build a set of queries to get content of all HTMLText fields
     *
     * @param array $contentClasses Classes and their HTMLText fields
     * @return array
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
                if (empty($outcome)) {
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
     *
     * @return array
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


    protected $manifest = null;
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
        if (!empty($this->$propertyName[$warningKey]) && count($this->$propertyName[$warningKey]) > 0) {
            foreach ($this->$propertyName[$warningKey] as $message) {
                echo strtoupper($propertyName) . ": $message";
            }
        } elseif (isset($this->$propertyName[$warningKey])) {
            unset($this->$propertyName[$warningKey]);
        }
    }

    protected function printErrorsAndWarnings($propertyName)
    {
        foreach ($this->$propertyName as $warningKey => $messages) {
            if (!empty($this->$propertyName[$warningKey]) && count($this->$propertyName[$warningKey]) > 0) {
                $vars = explode(',', $warningKey);
                $className = $vars[0];
                $id = $vars[1];
                $fieldName = $vars[2];
                $obj = DataObject::get_by_id($className, $id);
                if ($obj) {
                    echo ($obj->getTitle() ?: '[NO TITLE]') . "|"
                        . ($fieldName ?: '[NO FIELD NAME]') . "|"
                        . ($obj->hasMethod('CMSEditLink') ? $obj->CMSEditLink() : '[NO CMS LINK]') . "|"
                        . ($obj->hasMethod('Link') ? $obj->Link() : '[NO LINK]')
                        . "\n";
                } else {
                    echo "Could not find object with ID {$id} in class {$className} and field: {$fieldName} \n";
                }
                foreach ($messages as $message) {
                    echo ' --- ' . strtoupper($fieldName) . ": $message";
                }
            } else {
                echo "ERROR: No values for $warningKey\n";
            }
        }
    }

    protected function warningKeyMaker($className, $id, $fieldName)
    {
        return $className . ',' . $id . ',' . $fieldName;
    }
}
