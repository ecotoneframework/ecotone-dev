<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit;

use Ecotone\Messaging\BatchMessage;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * licence Apache-2.0
 * @internal
 */
final class BatchMessageTest extends TestCase
{
    public function test_appending_payloads_with_and_without_headers(): void
    {
        $orderPlaced = new stdClass();

        $batch = BatchMessage::constructEmpty()
            ->append('first payload')
            ->append($orderPlaced, ['priority' => 5]);

        $this->assertSame(
            [
                ['payload' => 'first payload', 'headers' => []],
                ['payload' => $orderPlaced, 'headers' => ['priority' => 5]],
            ],
            $batch->getEntries(),
        );
    }

    public function test_counting_appended_messages(): void
    {
        $this->assertCount(0, BatchMessage::constructEmpty());
        $this->assertCount(2, BatchMessage::constructEmpty()->append('one')->append('two'));
    }
}
