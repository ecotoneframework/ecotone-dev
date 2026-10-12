<?php

return [
    'namespaces' => ['Monorepo\\ExampleAppEventSourcing\\Common\\', 'Monorepo\\ExampleAppEventSourcing\\EcotoneProjection\\'],
    'enabledModulePackageNames' => \json_decode(\getenv('APP_ENABLED_MODULE_PACKAGE_NAMES'), true) ?? [],
    'cacheConfiguration' => \getenv('APP_ENV') === 'prod',
    'defaultErrorChannel' => 'errorChannel',
];
