<?php

declare(strict_types=1);

namespace App\Settings;

final readonly class QueueThresholds
{
    public const DEFAULT_WARNING = 1000;
    public const DEFAULT_CRITICAL = 10000;
    private const FORMAT = 'Set it to a whole number of messages, optionally followed by per-channel overrides as service:channel=number and *=number for every other channel, for example PULSE_QUEUE_WARNING=orders:invoices=500,*=1000 (PULSE_QUEUE_CRITICAL takes the same form).';

    /**
     * @param array<string, int> $warningOverrides service:channel => messages
     * @param array<string, int> $criticalOverrides service:channel => messages
     */
    private function __construct(
        private int $warning,
        private array $warningOverrides,
        private int $critical,
        private array $criticalOverrides,
    ) {
    }

    public static function fromEnvironment(?string $warning, ?string $critical): self
    {
        [$warningDefault, $warningOverrides] = self::parse('PULSE_QUEUE_WARNING', $warning, self::DEFAULT_WARNING);
        [$criticalDefault, $criticalOverrides] = self::parse('PULSE_QUEUE_CRITICAL', $critical, self::DEFAULT_CRITICAL);
        $thresholds = new self($warningDefault, $warningOverrides, $criticalDefault, $criticalOverrides);

        foreach ([null, ...array_keys([...$warningOverrides, ...$criticalOverrides])] as $channel) {
            if ($thresholds->warningOf($channel) >= $thresholds->criticalOf($channel)) {
                throw new PulseConfigurationException(sprintf(
                    'PULSE_QUEUE_WARNING "%d" is not below PULSE_QUEUE_CRITICAL "%d"%s, so a channel would skip the warning level. Set a warning threshold below the critical one, for example PULSE_QUEUE_WARNING=%d and PULSE_QUEUE_CRITICAL=%d.',
                    $thresholds->warningOf($channel),
                    $thresholds->criticalOf($channel),
                    $channel === null ? '' : sprintf(' for channel "%s"', $channel),
                    self::DEFAULT_WARNING,
                    self::DEFAULT_CRITICAL,
                ));
            }
        }

        return $thresholds;
    }

    public function levelOf(string $service, string $channel, ?int $ready): string
    {
        if ($ready === null) {
            return 'unknown';
        }

        $key = $service . ':' . $channel;

        return match (true) {
            $ready >= $this->criticalOf($key) => 'critical',
            $ready >= $this->warningOf($key) => 'warning',
            default => 'ok',
        };
    }

    public function describeWarning(): string
    {
        return self::describe($this->warning, $this->warningOverrides);
    }

    public function describeCritical(): string
    {
        return self::describe($this->critical, $this->criticalOverrides);
    }

    private function warningOf(?string $key): int
    {
        return $key === null ? $this->warning : $this->warningOverrides[$key] ?? $this->warning;
    }

    private function criticalOf(?string $key): int
    {
        return $key === null ? $this->critical : $this->criticalOverrides[$key] ?? $this->critical;
    }

    /**
     * @param array<string, int> $overrides
     */
    private static function describe(int $default, array $overrides): string
    {
        if ($overrides === []) {
            return (string) $default;
        }

        $entries = [];
        foreach ($overrides as $channel => $messages) {
            $entries[] = $channel . '=' . $messages;
        }

        return implode(', ', [...$entries, '*=' . $default]);
    }

    /**
     * @return array{int, array<string, int>}
     */
    private static function parse(string $variable, ?string $value, int $default): array
    {
        if ($value === null) {
            return [$default, []];
        }

        $wildcard = $default;
        $overrides = [];
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if (preg_match('/^(?:(?<target>\*|[^\s:=,]+:[^\s:=,]+)=)?(?<messages>[1-9][0-9]*)$/', $entry, $matches) !== 1) {
                throw new PulseConfigurationException(sprintf('%s "%s" is not valid: "%s" is not a number of messages above zero, optionally prefixed with service:channel= or *=. %s', $variable, $value, $entry, self::FORMAT));
            }

            $target = $matches['target'];
            if ($target === '' || $target === '*') {
                $wildcard = (int) $matches['messages'];
            } else {
                $overrides[$target] = (int) $matches['messages'];
            }
        }

        return [$wildcard, $overrides];
    }
}
