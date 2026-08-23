<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'modulePackages' => [ModulePackageList::LARAVEL_PACKAGE, ],
    'licenceKey' => env('LARAVEL_LICENCE_KEY'),
];
