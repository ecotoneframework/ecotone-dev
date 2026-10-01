<?php

use Ecotone\Api\ExtensionObject\ModulePackageList;

return [
    'modulePackages' => [ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::DBAL_PACKAGE, ],
    'licenceKey' => env('LARAVEL_LICENCE_KEY'),
];
