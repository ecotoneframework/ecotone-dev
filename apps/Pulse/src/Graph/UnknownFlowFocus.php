<?php

declare(strict_types=1);

namespace App\Graph;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(404)]
final class UnknownFlowFocus extends InvalidArgumentException
{
    /**
     * @param list<string> $available
     */
    public static function ofService(string $requested, string $service, array $available): self
    {
        sort($available);

        return new self(sprintf('Cannot follow "%s": service "%s" is not among the services that answered. Choose one of: %s; type the name of the node into Find on the flow graph; or remove the focus parameter to see every service.', $requested, $service, implode(', ', $available)));
    }

    public static function ofNode(string $requested, string $service, string $nodeId): self
    {
        return new self(sprintf('Cannot follow "%s": %s reports no node "%s", so its flow may have changed since the link was made. Type the name of the node into Find on the flow graph, or remove the focus parameter to see every service.', $requested, $service, $nodeId));
    }
}
