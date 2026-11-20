<?php

use Ecotone\Api\ExtensionObject\ModulePackageList;

return [
    'namespaces' => ['Monorepo\\SwooleApp\\Common\\', 'Monorepo\\SwooleApp\\Laravel\\app\\'],
    'enabledModulePackageNames' => [
        ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::DBAL_PACKAGE,
        ModulePackageList::EVENT_SOURCING_PACKAGE,
        ModulePackageList::JMS_CONVERTER_PACKAGE,
    ],
];
