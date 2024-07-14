<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow;

/**
 * licence Apache-2.0
 */
final class MerchantCreated
{
    public function __construct(public string $merchantId)
    {
    }
}
