<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\AsynchronousMessageHandler;

/**
 * @internal
 * licence Apache-2.0
 */
final class AsyncCommand
{
    public function __construct(
        public readonly string $id = '1',
        public readonly string $message = 'test message'
    ) {}
}
