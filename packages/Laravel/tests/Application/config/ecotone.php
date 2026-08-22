<?php

use Ecotone\Messaging\Config\ModulePackageList;

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
