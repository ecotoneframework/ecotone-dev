<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'loadAppNamespaces' => false,
    'namespaces' => [
        env('ECOTONE_BOOT_NS', 'App\BootValidation\Laravel\Shared'),
    ],
    'skippedModulePackageNames' => ModulePackageList::allPackagesExcept([
        ModulePackageList::LARAVEL_PACKAGE,
    ]),
];
