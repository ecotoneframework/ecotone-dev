<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\MessageHeaders;
use stdClass;

class InterceptorOrderingStack
{
    private array $calls = [];
    public function add(string $name, array $metadata, mixed $result = null): self
    {
        $call = [$name, MessageHeaders::unsetAllFrameworkHeaders($metadata)];
        if ($result) {
            $call[] = \get_class($result);
        }
        $this->calls[] = $call;
        return $this;
    }

    public function getCalls(): array
    {
        return $this->calls;
    }
}