<?php

namespace Ecotone\Messaging\Config\Container;

interface ContainerImplementation
{
    public const REFERENCE_ID = "compiled_container";
    public const EXTERNAL_REFERENCE_SEARCH_SERVICE_ID = "external_reference_search_service";

    /**
     * @param array<string, Definition> $definitions
     * @param array<string, Reference> $externalReferences
     */
    public function process(array $definitions, array $externalReferences): ContainerHydrator;
}