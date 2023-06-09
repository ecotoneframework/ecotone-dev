<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'namespaces' => [
        'Monorepo\ExampleApp\Common',
    ],
    'skippedModulePackageNames' => ModulePackageList::allPackagesExcept([ModulePackageList::ASYNCHRONOUS_PACKAGE]),
];
