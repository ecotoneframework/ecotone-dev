<?php

use Ecotone\Messaging\Config\ModulePackageList;

return [
    'namespaces' => ['Monorepo\\ExampleApp\\Common\\'],
    'modulePackages' => \json_decode(\getenv('APP_MODULE_PACKAGES'), true) ?? [],
    'cacheConfiguration' => \getenv('APP_ENV') === 'prod',
    'defaultErrorChannel' => 'errorChannel',
];
