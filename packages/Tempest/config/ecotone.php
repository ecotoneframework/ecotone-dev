<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Ecotone Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the default configuration for Ecotone Framework
    | when used with Tempest.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The environment Ecotone should run in. This affects caching, error
    | handling, and other behaviors.
    |
    */
    'environment' => $_ENV['APP_ENV'] ?? 'dev',

    /*
    |--------------------------------------------------------------------------
    | Namespaces
    |--------------------------------------------------------------------------
    |
    | Namespaces that Ecotone should scan for message handlers, aggregates,
    | and other components. Leave empty to auto-discover.
    |
    */
    'namespaces' => [],

    /*
    |--------------------------------------------------------------------------
    | Load Catalog
    |--------------------------------------------------------------------------
    |
    | The directory to load classes from. Relative to the application root.
    |
    */
    'loadCatalog' => 'src',

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Whether to enable caching for production environments.
    |
    */
    'cacheConfiguration' => $_ENV['ECOTONE_CACHE'] ?? false,

    /*
    |--------------------------------------------------------------------------
    | Default Error Channel
    |--------------------------------------------------------------------------
    |
    | The default channel to send error messages to.
    |
    */
    'defaultErrorChannel' => $_ENV['ECOTONE_DEFAULT_ERROR_CHANNEL'] ?? null,

    /*
    |--------------------------------------------------------------------------
    | Service Name
    |--------------------------------------------------------------------------
    |
    | The name of this service for distributed systems.
    |
    */
    'serviceName' => $_ENV['ECOTONE_SERVICE_NAME'] ?? 'tempest-app',

    /*
    |--------------------------------------------------------------------------
    | Skipped Module Package Names
    |--------------------------------------------------------------------------
    |
    | Module packages to skip during discovery.
    |
    */
    'skippedModulePackageNames' => [],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Enable testing features and test package.
    |
    */
    'test' => $_ENV['APP_ENV'] === 'test',
];
