<?php

namespace Sunnysideup\AssetsOverview\Control;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Core\Extension;


class AssetAdminExtension extends Extension
{
    public function updateEditForm($form)
    {
        $form->Fields()->push(
            LiteralField::create('AssetsOverviewLink', '<h2>Quick Overview of Images</h2><p>Here is a <a href="/assetsoverview/">quick overview tool</a> for all CMS Images.</p>')
        );
    }
}
