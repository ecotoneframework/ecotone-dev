<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'namespaces' => \json_decode(\getenv('APP_NAMESPACES_TO_LOAD'), true),
    'skippedModulePackageNames' => \json_decode(\getenv('APP_SKIPPED_PACKAGES'), true),
    'cacheConfiguration' => \getenv('APP_ENV') === 'prod',
    'defaultErrorChannel' => 'errorChannel',
];
