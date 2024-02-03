<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'skippedModulePackageNames' => ModulePackageList::allPackagesExcept([
        ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::DBAL_PACKAGE,
        ModulePackageList::ASYNCHRONOUS_PACKAGE,
    ]),
];
