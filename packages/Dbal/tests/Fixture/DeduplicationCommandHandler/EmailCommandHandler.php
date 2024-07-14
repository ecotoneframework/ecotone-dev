<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeduplicationCommandHandler;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use RuntimeException;

/**
 * licence Apache-2.0
 */
final class EmailCommandHandler
{
    private int $called = 0;

    public function __construct(private ?int $failTillCall = null)
    {
    }

    #[Deduplicated]
    #[Asynchronous('email')]
    #[CommandHandler('email_event_handler.handle', endpointId: 'email_event_handler.handle.endpoint')]
    public function handle(): void
    {
        $this->called++;

        if ($this->failTillCall !== null && $this->called <= $this->failTillCall) {
            throw new RuntimeException('Failed');
        }
    }

    #[Deduplicated('emailId')]
    #[CommandHandler('email_event_handler.handle_with_custom_deduplication_header')]
    public function handleWithCustomDeduduplication(): void
    {
        $this->called++;
    }

    #[QueryHandler('email_event_handler.getCallCount')]
    public function getCallCount(): int
    {
        return $this->called;
    }
}
