<?php

use Ecotone\Api\ExtensionObject\ModulePackageList;

return [
    'namespaces' => [],
    'enabledModulePackageNames' => [ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::DBAL_PACKAGE, ],
    'test' => false,
];
