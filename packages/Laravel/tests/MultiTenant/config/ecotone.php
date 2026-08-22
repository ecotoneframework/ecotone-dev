<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'modulePackages' => [ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::DBAL_PACKAGE,],
    'licenceKey' => env('LARAVEL_LICENCE_KEY'),
];
