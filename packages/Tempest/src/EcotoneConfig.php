<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Ecotone\Messaging\Config\FrameworkConfigurationKeys;

/**
 * licence Apache-2.0
 */
final class EcotoneConfig
{
    public function __construct(
        public array $namespaces = [],
        public bool $loadAppNamespaces = true,
        public ?array $enabledModulePackageNames = null,
        public bool $test = false,
        public string $licenceKey = '',
        mixed ...$unrecognisedKeys,
    ) {
        FrameworkConfigurationKeys::refuseUnrecognisedKeys(
            givenKeys: array_keys($unrecognisedKeys),
            recognisedKeys: array_keys(get_object_vars($this)),
            configurationLocation: 'EcotoneConfig',
        );
    }
}
