<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'namespaces' => [
        'Monorepo\ExampleApp\Common',
    ],
    'skippedModulePackageNames' => \json_decode(\getenv('APP_SKIPPED_PACKAGES'), true),
    'cacheConfiguration' => \getenv('APP_ENV') === 'prod',
    'defaultErrorChannel' => 'errorChannel',
];
