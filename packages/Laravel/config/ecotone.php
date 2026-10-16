<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Load Namespaces
    |--------------------------------------------------------------------------
    |
    | Whether or not Ecotone should automatically load all namespaces.
    |
    */
    'loadAppNamespaces' => true,

    /*
    |--------------------------------------------------------------------------
    | Namespaces
    |--------------------------------------------------------------------------
    |
    | A list of namespaces that Ecotone should look in for configurations,
    | command handlers, aggregates, projections, etc.
    |
    */
    'namespaces' => [],

    /*
    |--------------------------------------------------------------------------
    | Enabled Module Package Names
    |--------------------------------------------------------------------------
    |
    | The Ecotone module packages to load, as ModulePackageList constants
    | (e.g. [ModulePackageList::LARAVEL_PACKAGE, ModulePackageList::DBAL_PACKAGE]).
    | null loads every installed module package. A list loads Core, which
    | includes Asynchronous, and the packages you name; an empty list loads
    | Core alone.
    |
    */
    'enabledModulePackageNames' => null,

    /*
    |--------------------------------------------------------------------------
    | test
    |--------------------------------------------------------------------------
    |
    | Whether ecotone test module should be loaded
    |
    */
    'test' => false,

    /*
    |--------------------------------------------------------------------------
    | licenceKey
    |--------------------------------------------------------------------------
    |
    | Licence key to use Ecotone Enterprise features
    |
    */
    'licenceKey' => null,

    /*
    |--------------------------------------------------------------------------
    | Everything else is ServiceConfiguration
    |--------------------------------------------------------------------------
    |
    | The service name, default serialization media type, default error
    | channel, consumer memory limit, connection retry template and headers
    | in log context are returned from a #[ServiceContext] method:
    |
    |   #[ServiceContext]
    |   public function serviceConfiguration(): ServiceConfiguration
    |   {
    |       return ServiceConfiguration::createWithDefaults()
    |           ->withServiceName('my_service')
    |           ->withDefaultErrorChannel('errorChannel');
    |   }
    |
    | The container cache is reused as is in production or once config:cache
    | has run, and rebuilt whenever a scanned file changes otherwise.
    |
    */
];
