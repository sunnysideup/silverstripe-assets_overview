<?php

namespace Sunnysideup\AssetsOverview\Api;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

class ImageFieldFinder
{
    protected static $myCache = [];

    public function Fields()
    {
        if (empty(self::$myCache)) {
            $types = ['has_one', 'has_many', 'many_many', 'belongs_many_many', 'belongs_to'];
            $classNames = ClassInfo::subclassesFor(DataObject::class, false);
            foreach ($classNames as $className) {
                $obj = Injector::inst()->get($className);
                $fieldLabels = $obj->fieldLabels(true);
                foreach ($types as $type) {
                    $rels = Config::inst()->get($className, $type, Config::UNINHERITED);
                    if (is_array($rels) && ! empty($rels)) {
                        foreach ($rels as $relName => $relType) {
                            if (is_a($relType, File::class, true)) {
                                $title = $obj->i18n_singular_name() . ' - ' . ($fieldLabels[$relName] ?? $relName);
                                $key = $className . ',' . $relName . ',' . $type;
                                self::$myCache[$key] = $title;
                            }
                        }
                    }
                }
            }

            asort(self::$myCache);
        }

        return self::$myCache;
    }
}
