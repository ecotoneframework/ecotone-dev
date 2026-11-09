<?php

declare(strict_types=1);

namespace App\Ui;

use App\Home\Severity;

enum Status: string
{
    case Critical = 'critical';
    case Warning = 'warning';
    case Info = 'info';
    case Ok = 'ok';
    case Unknown = 'unknown';

    public static function of(self|Severity|string|null $status): self
    {
        return match (true) {
            $status instanceof self => $status,
            $status instanceof Severity => self::from($status->value),
            $status === null => self::Ok,
            default => self::from($status),
        };
    }

    public static function worstOf(self|Severity|string|null ...$statuses): self
    {
        $worst = self::Ok;
        foreach ($statuses as $status) {
            $candidate = self::of($status);
            if ($candidate->weight() > $worst->weight()) {
                $worst = $candidate;
            }
        }

        return $worst;
    }

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Warning => 'Warning',
            self::Info => 'Info',
            self::Ok => 'OK',
            self::Unknown => 'Unknown',
        };
    }

    public function meaning(): string
    {
        return match ($this) {
            self::Critical => 'needs action now',
            self::Warning => 'watch or plan',
            self::Info => 'running',
            self::Ok => 'nothing to do',
            self::Unknown => 'Pulse could not determine it',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::Warning => 3,
            self::Unknown => 2,
            self::Info => 1,
            self::Ok => 0,
        };
    }

    public function needsReason(): bool
    {
        return $this === self::Unknown;
    }
}
