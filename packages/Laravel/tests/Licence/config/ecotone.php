<?php

use Ecotone\Api\ExtensionObject\ModulePackageList;

return [
    'enabledModulePackageNames' => [ModulePackageList::LARAVEL_PACKAGE, ],
    'licenceKey' => env('LARAVEL_LICENCE_KEY'),
];
