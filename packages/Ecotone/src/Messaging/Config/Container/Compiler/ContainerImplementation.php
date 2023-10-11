<?php

namespace Ecotone\Messaging\Config\Container\Compiler;

interface ContainerImplementation extends CompilerPass
{
    public const REFERENCE_ID = 'compiled_container';
}
