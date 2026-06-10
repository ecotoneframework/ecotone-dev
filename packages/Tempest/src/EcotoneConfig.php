<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use function Tempest\env;

/**
 * licence Apache-2.0
 */
final class EcotoneConfig
{
    public function __construct(
        public string $serviceName = '',
        public array $namespaces = [],
        public bool $loadAppNamespaces = true,
        public bool $cacheConfiguration = false,
        public string $defaultSerializationMediaType = '',
        public string $defaultErrorChannel = '',
        public array $skippedModulePackageNames = [],
        public bool $test = false,
        public string $licenceKey = '',
    ) {
        if ($this->serviceName === '') {
            $this->serviceName = (string) env('ECOTONE_SERVICE_NAME', '');
        }
        if (! $this->cacheConfiguration) {
            $this->cacheConfiguration = (bool) env('ECOTONE_CACHE_CONFIGURATION', false);
        }
    }
}
