<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'namespaces' => [
        'Test\Ecotone\Laravel\Fixture',
    ],
    'skippedModulePackageNames' => ModulePackageList::allPackages(),
    'test' => true,
];
