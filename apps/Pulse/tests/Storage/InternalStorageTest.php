<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Tests\Support\PulseEnvironmentTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
final class InternalStorageTest extends KernelTestCase
{
    use PulseEnvironmentTrait;

    public function test_flow_tracking_is_stored_in_the_internal_database_while_the_queue_stays_in_the_transport_database(): void
    {
        $this->configurePulse(['PULSE_INTERNAL_DATABASE_PATH' => sys_get_temp_dir() . '/pulse-internal-' . uniqid() . '.sqlite']);

        $features = $this->featuresByConnection();

        self::assertSame('Ecotone\Api\Dbal\DbalConnectionFactory', $features['tracing'] ?? null);
        self::assertSame('pulse_transport', $features['message_queue'] ?? null);
        self::assertSame('pulse_transport', $features['deduplication'] ?? null);
    }

    public function test_a_broker_transport_leaves_every_table_in_the_internal_database(): void
    {
        $this->configurePulse([
            'PULSE_INTERNAL_DATABASE_PATH' => sys_get_temp_dir() . '/pulse-internal-' . uniqid() . '.sqlite',
            'PULSE_TRANSPORT_DSN' => 'amqp://guest:guest@rabbitmq:5672',
        ]);

        $features = $this->featuresByConnection();

        self::assertSame('Ecotone\Api\Dbal\DbalConnectionFactory', $features['tracing'] ?? null);
        self::assertArrayNotHasKey('message_queue', $features);
    }

    public function test_the_internal_database_waits_five_seconds_for_a_lock_and_keeps_a_write_ahead_log(): void
    {
        $this->configurePulse(['PULSE_INTERNAL_DATABASE_PATH' => sys_get_temp_dir() . '/pulse-internal-' . uniqid() . '.sqlite']);
        $kernel = self::bootKernel();

        $setup = new CommandTester((new Application($kernel))->find('pulse:setup'));
        $setup->execute([]);

        self::assertStringContainsString('journal_mode=wal', $setup->getDisplay());
        self::assertStringContainsString('busy_timeout=5000', $setup->getDisplay());
    }

    /**
     * @return array<string, string>
     */
    private function featuresByConnection(): array
    {
        $kernel = self::bootKernel();
        $command = new CommandTester((new Application($kernel))->find('ecotone:migration:database:setup'));
        $command->execute([]);

        $features = [];
        foreach (explode("\n", $command->getDisplay()) as $line) {
            $cells = array_map('trim', explode('|', trim($line, " |\n")));
            if (count($cells) >= 3 && preg_match('/^[a-z_]+$/', $cells[0]) === 1 && $cells[2] === 'Yes') {
                $features[$cells[0]] = $cells[1];
            }
        }
        self::ensureKernelShutdown();

        return $features;
    }
}
