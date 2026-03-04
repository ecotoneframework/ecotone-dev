<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Channel\Collector;

use Ecotone\Messaging\Channel\Collector\CollectorStorage;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CollectorStoragePauseTest extends TestCase
{
    protected function tearDown(): void
    {
        while (CollectorStorage::isPaused()) {
            CollectorStorage::resume();
        }
    }

    public function test_not_paused_by_default(): void
    {
        self::assertFalse(CollectorStorage::isPaused());
    }

    public function test_pause_and_resume(): void
    {
        CollectorStorage::pause();
        self::assertTrue(CollectorStorage::isPaused());

        CollectorStorage::resume();
        self::assertFalse(CollectorStorage::isPaused());
    }

    public function test_nested_pause_requires_matching_resume_count(): void
    {
        CollectorStorage::pause();
        CollectorStorage::pause();
        self::assertTrue(CollectorStorage::isPaused());

        CollectorStorage::resume();
        self::assertTrue(CollectorStorage::isPaused());

        CollectorStorage::resume();
        self::assertFalse(CollectorStorage::isPaused());
    }
}
