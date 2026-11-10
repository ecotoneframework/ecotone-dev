<?php

declare(strict_types=1);

namespace App\Settings;

final readonly class TraceUrl
{
    public const TRACE_ID = '{traceId}';
    public const TRACE_ID_LOW_64 = '{traceIdLow64}';
    private const FORMAT = 'Set it to the trace page of your tracing UI, with {traceId} where the 32-hex trace id goes or {traceIdLow64} where the decimal of its low 64 bits goes, for example http://localhost:16686/trace/{traceId} (Jaeger), http://localhost:9411/zipkin/traces/{traceId} (Zipkin) or https://app.datadoghq.com/apm/trace/{traceIdLow64} (Datadog), or unset it to show no link.';

    private function __construct(private ?string $template)
    {
    }

    public static function fromEnvironment(?string $template): self
    {
        if ($template === null) {
            return new self(null);
        }
        if (preg_match('#^https?://[^\s/?\#]+#i', $template) !== 1 || preg_match('/\s/', $template) === 1) {
            throw new PulseConfigurationException(sprintf('PULSE_TRACE_URL "%s" is not an http:// or https:// URL, so Pulse cannot link a dead letter to its trace in your tracing UI. %s', $template, self::FORMAT));
        }
        if (! str_contains($template, self::TRACE_ID) && ! str_contains($template, self::TRACE_ID_LOW_64)) {
            throw new PulseConfigurationException(sprintf('PULSE_TRACE_URL "%s" contains neither {traceId} nor {traceIdLow64}, so every dead letter would link to the same page instead of its trace. %s', $template, self::FORMAT));
        }

        return new self($template);
    }

    public function of(string $traceId): ?string
    {
        if ($this->template === null || preg_match('/^[0-9a-f]{32}$/', strtolower($traceId)) !== 1) {
            return null;
        }

        return strtr($this->template, [
            self::TRACE_ID => strtolower($traceId),
            self::TRACE_ID_LOW_64 => self::decimalOf(substr(strtolower($traceId), 16)),
        ]);
    }

    private static function decimalOf(string $hexadecimal): string
    {
        $digits = [0];
        foreach (str_split($hexadecimal) as $nibble) {
            $carry = (int) hexdec($nibble);
            foreach ($digits as $position => $digit) {
                $value = $digit * 16 + $carry;
                $digits[$position] = $value % 10;
                $carry = intdiv($value, 10);
            }
            while ($carry > 0) {
                $digits[] = $carry % 10;
                $carry = intdiv($carry, 10);
            }
        }
        return implode('', array_reverse($digits));
    }
}
