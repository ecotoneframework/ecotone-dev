<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Config;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Projecting\Attribute\Projection;

interface StreamSourceBuilder
{
    public function canHandle(Projection $projection): bool;

    /**
     * @param non-empty-list<Projection> $projections
     */
    public function compile(MessagingContainerBuilder $builder, array $projections): Definition|Reference;
}