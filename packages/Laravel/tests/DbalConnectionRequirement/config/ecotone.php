<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'namespaces' => [],
    'skippedModulePackageNames' => ModulePackageList::allPackagesExcept([
        ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::DBAL_PACKAGE,
    ]),
    'test' => true,
];

