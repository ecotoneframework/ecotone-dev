<?php

return [
    'namespaces' => ['Monorepo\\ExampleAppEventSourcing\\Common\\', 'Monorepo\\ExampleAppEventSourcing\\ProophProjection\\'],
    'modulePackages' => \json_decode(\getenv('APP_MODULE_PACKAGES'), true) ?? [],
    'cacheConfiguration' => \getenv('APP_ENV') === 'prod',
    'defaultErrorChannel' => 'errorChannel',
];
