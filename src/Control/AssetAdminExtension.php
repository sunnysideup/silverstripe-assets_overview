<?php

namespace Sunnysideup\AssetsOverview\Control;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\LiteralField;

class AssetAdminExtension extends Extension
{
    public function updateEditForm($form)
    {
        $form->Fields()->push(
            LiteralField::create(
                'AssetsOverviewLink',
                '
                    <h2>Quick Overview of Images</h2>
                    <p>Here is a <a href="/assets-overview/">quick overview tool</a> for all CMS Images.</p>
                ')
        );
    }
}
