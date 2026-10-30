<?php

declare(strict_types=1);

namespace Ecotone\Api\Attribute;

use Attribute;
use Ecotone\Api\Gateway\DistributedBus;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
/**
 * licence Apache-2.0
 */
final class SendsDistributedMessage
{
    public function __construct(
        public readonly string $targetServiceName,
        public readonly string $routingKey,
        public readonly ?string $replyRoutingKey = null,
        public readonly string $distributionReference = DistributedBus::class,
    ) {
    }
}
