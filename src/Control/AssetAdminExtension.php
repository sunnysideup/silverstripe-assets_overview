<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\LiteralField;

/**
 * Class \Sunnysideup\AssetsOverview\Control\AssetAdminExtension
 *
 * @property \SilverStripe\AssetAdmin\Controller\AssetAdmin|\Sunnysideup\AssetsOverview\Control\AssetAdminExtension $owner
 */
class AssetAdminExtension extends Extension
{
    public function updateEditForm($form)
    {
        $form->Fields()->push(
            LiteralField::create(
                'AssetsOverviewLink',
                '
                    <h2>Quick Overview of Files and Images</h2>
                    <p>Here is a <a href="/admin/assets-overview/">quick overview tool</a> for all CMS Files and Images.</p>
                '
            )
        );
    }
}
