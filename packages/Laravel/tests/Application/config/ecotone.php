<?php

use Ecotone\Api\ExtensionObject\ModulePackageList;

return [
    'namespaces' => [
        'Test\Ecotone\Laravel\Fixture',
    ],
    'modulePackages' => [
        ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::JMS_CONVERTER_PACKAGE,
    ],
    'test' => true,
];
