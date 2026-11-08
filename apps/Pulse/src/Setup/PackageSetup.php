<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class PackageSetup
{
    /**
     * @param list<SetupItem> $tables
     * @param list<Feature> $features
     */
    public function __construct(
        public string $name,
        public string $composerPackage,
        public ?string $version,
        public array $tables,
        public array $features,
    ) {
    }
}
