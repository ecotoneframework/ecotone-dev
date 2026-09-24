<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'namespaces' => [],
    'modulePackages' => [
        ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::DBAL_PACKAGE,
        ModulePackageList::EVENT_SOURCING_PACKAGE,
    ],
    'licenceKey' => env('LARAVEL_DCB_SMOKE_LICENCE_KEY'),
    'test' => false,
];
