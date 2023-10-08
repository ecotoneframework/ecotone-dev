<?php

namespace Ecotone\Messaging\Config\Container;

interface ContainerImplementation
{
    public const REFERENCE_ID = 'compiled_container';

    /**
     * @param array<string, Definition> $definitions
     * @param array<string, Reference> $externalReferences
     */
    public function process(array $definitions, array $externalReferences): void;
}
