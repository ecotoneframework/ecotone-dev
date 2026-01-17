<?php

$databaseDsn = getenv('DATABASE_DSN') ?: '';
$secondaryDatabaseDsn = getenv('SECONDARY_DATABASE_DSN') ?: '';
$isSQLitePrimary = str_starts_with($databaseDsn, 'sqlite:');
$isSQLiteSecondary = str_starts_with($secondaryDatabaseDsn, 'sqlite:');

$getSqlitePath = function (string $dsn): string {
    $path = preg_replace('/^sqlite:(\/\/)?/', '', $dsn);
    if ($path === '/:memory:') {
        return ':memory:';
    }
    $path = ltrim($path, '/');
    $path = '/' . $path;
    if (!file_exists($path)) {
        touch($path);
    }
    return $path;
};

$tenantAConfig = $isSQLitePrimary
    ? [
        'driver' => 'sqlite',
        'database' => $getSqlitePath($databaseDsn),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]
    : [
        'url' => $databaseDsn ?: 'pgsql://ecotone:secret@localhost:5432/ecotone',
    ];

$tenantBConfig = $isSQLiteSecondary
    ? [
        'driver' => 'sqlite',
        'database' => $getSqlitePath($secondaryDatabaseDsn),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]
    : [
        'url' => $secondaryDatabaseDsn ?: 'mysql://ecotone:secret@localhost:3306/ecotone',
    ];

return [
    'default' => 'tenant_a_connection',
    'connections' => [
        'tenant_a_connection' => $tenantAConfig,
        'tenant_b_connection' => $tenantBConfig,
    ],
];
