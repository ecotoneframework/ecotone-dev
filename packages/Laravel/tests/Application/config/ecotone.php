<?php

use Ecotone\Api\ExtensionObject\ModulePackageList;

return [
    'namespaces' => [
        'Test\Ecotone\Laravel\Fixture',
    ],
    'enabledModulePackageNames' => [
        ModulePackageList::LARAVEL_PACKAGE,
        ModulePackageList::JMS_CONVERTER_PACKAGE,
    ],
    'test' => true,
    'defaultConnectionExceptionRetry' => [
        'initialDelay' => 1,
        'maxAttempts' => 4,
        'multiplier' => 2,
    ],
];
