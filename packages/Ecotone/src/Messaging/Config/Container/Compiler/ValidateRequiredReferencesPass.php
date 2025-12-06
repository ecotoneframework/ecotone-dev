<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Container\Compiler;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\ContainerBuilder;

/**
 * Validates that all required references are registered in the container.
 * If a required reference is missing, throws a ConfigurationException with a user-friendly error message.
 *
 * licence Apache-2.0
 */
class ValidateRequiredReferencesPass implements CompilerPass
{
    /**
     * @param array<string, string> $requiredReferences Map of referenceId => errorMessage
     * @param string[] $availableExternalReferences List of reference IDs available in external container
     */
    public function __construct(
        private array $requiredReferences,
        private array $availableExternalReferences = []
    ) {
    }

    public function process(ContainerBuilder $builder): void
    {
        $definitions = $builder->getDefinitions();
        $externalReferences = $builder->getExternalReferences();
        $availableExternalReferencesMap = array_flip($this->availableExternalReferences);

        foreach ($this->requiredReferences as $referenceId => $errorMessage) {
            if (! isset($definitions[$referenceId]) && ! isset($externalReferences[$referenceId]) && ! isset($availableExternalReferencesMap[$referenceId])) {
                throw ConfigurationException::create($errorMessage);
            }
        }
    }
}

