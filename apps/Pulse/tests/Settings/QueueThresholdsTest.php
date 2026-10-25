<?php

declare(strict_types=1);

namespace App\Tests\Settings;

use App\Settings\PulseConfigurationException;
use App\Settings\PulseSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class QueueThresholdsTest extends TestCase
{
    public function test_a_channel_is_warning_at_one_thousand_and_critical_at_ten_thousand_unless_told_otherwise(): void
    {
        $thresholds = $this->settings([])->queueThresholds;

        self::assertSame('ok', $thresholds->levelOf('orders', 'invoices', 999));
        self::assertSame('warning', $thresholds->levelOf('orders', 'invoices', 1000));
        self::assertSame('warning', $thresholds->levelOf('orders', 'invoices', 9999));
        self::assertSame('critical', $thresholds->levelOf('orders', 'invoices', 10000));
    }

    public function test_a_bare_number_sets_the_threshold_of_every_channel(): void
    {
        $thresholds = $this->settings(['PULSE_QUEUE_WARNING' => '50', 'PULSE_QUEUE_CRITICAL' => '60'])->queueThresholds;

        self::assertSame('warning', $thresholds->levelOf('billing', 'mails', 50));
        self::assertSame('critical', $thresholds->levelOf('orders', 'invoices', 60));
    }

    public function test_a_channel_override_wins_over_the_wildcard_and_the_wildcard_over_the_default(): void
    {
        $thresholds = $this->settings(['PULSE_QUEUE_WARNING' => 'orders:invoices=500,*=2000', 'PULSE_QUEUE_CRITICAL' => 'orders:invoices=800,*=3000'])->queueThresholds;

        self::assertSame('warning', $thresholds->levelOf('orders', 'invoices', 500));
        self::assertSame('critical', $thresholds->levelOf('orders', 'invoices', 800));
        self::assertSame('ok', $thresholds->levelOf('orders', 'emails', 1999));
        self::assertSame('warning', $thresholds->levelOf('orders', 'emails', 2000));
        self::assertSame('critical', $thresholds->levelOf('billing', 'invoices', 3000));
    }

    public function test_a_channel_that_was_not_measured_has_no_level(): void
    {
        self::assertSame('unknown', $this->settings([])->queueThresholds->levelOf('orders', 'invoices', null));
    }

    #[DataProvider('invalid_values')]
    public function test_an_invalid_value_is_refused_at_boot_with_the_format_that_is_accepted(string $variable, string $value): void
    {
        try {
            $this->settings([$variable => $value]);
            self::fail('The value should have been refused');
        } catch (PulseConfigurationException $exception) {
            self::assertStringContainsString(sprintf('%s "%s"', $variable, $value), $exception->getMessage());
            self::assertStringContainsString('PULSE_QUEUE_WARNING=orders:invoices=500,*=1000', $exception->getMessage());
            self::assertStringContainsString('a whole number of messages', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid_values(): iterable
    {
        yield 'text' => ['PULSE_QUEUE_WARNING', 'many'];
        yield 'zero' => ['PULSE_QUEUE_WARNING', '0'];
        yield 'negative' => ['PULSE_QUEUE_CRITICAL', '-5'];
        yield 'channel without a service' => ['PULSE_QUEUE_WARNING', 'invoices=500'];
        yield 'override without a number' => ['PULSE_QUEUE_WARNING', 'orders:invoices='];
        yield 'override with text' => ['PULSE_QUEUE_CRITICAL', 'orders:invoices=lots'];
        yield 'empty entry' => ['PULSE_QUEUE_WARNING', '500,,*=1000'];
    }

    public function test_a_warning_that_is_not_below_the_critical_threshold_is_refused(): void
    {
        $this->expectException(PulseConfigurationException::class);
        $this->expectExceptionMessage('PULSE_QUEUE_WARNING "20000" is not below PULSE_QUEUE_CRITICAL "10000"');

        $this->settings(['PULSE_QUEUE_WARNING' => '20000']);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function settings(array $overrides): PulseSettings
    {
        return PulseSettings::fromEnvironment(['ECOTONE_LICENCE_KEY' => 'key', 'PULSE_VERSION' => 'test', 'PULSE_TRANSPORT_DSN' => 'amqp://guest:guest@rabbitmq:5672', 'PULSE_SERVICES' => 'orders', ...$overrides], __DIR__);
    }
}
