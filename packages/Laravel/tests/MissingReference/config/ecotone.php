<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'loadAppNamespaces' => false,
    'namespaces' => [
        env('ECOTONE_MISSING_REF_NS', 'App\MissingReference\Laravel\Shared'),
    ],
    'skippedModulePackageNames' => ModulePackageList::allPackagesExcept([
        ModulePackageList::LARAVEL_PACKAGE,
    ]),
];
