<?php

namespace Sunnysideup\AssetsOverview\Control;

class AssetAdminExtension extends \Extension
{

    function updateEditForm($form)
    {
        $form->Fields()->push(
            \LiteralField::create('AssetsOverviewLink', '<a href="/assetsoverview/">quick overview</a>')
        );
    }
}
