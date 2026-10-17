<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use Ecotone\Api\Messaging\Flow\MessageFlowGraph;

final class DeclaredMessageFlow
{
    /**
     * @param array<string, array<string, string>> $collaborators handler node id => (receiver => class)
     */
    public function __construct(
        public readonly MessageFlowGraph $graph,
        public readonly array $collaborators,
    ) {
    }
}
