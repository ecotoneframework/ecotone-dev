<?php

declare(strict_types=1);

namespace App\Ui;

use App\Home\Severity;
use InvalidArgumentException;

final readonly class StatusView
{
    private function __construct(public Status $status, public ?string $reason)
    {
    }

    public static function of(self|Status|Severity|string|null $status, ?string $reason = null): self
    {
        if ($status instanceof self) {
            return $reason === null || $reason === '' ? $status : new self($status->status, $reason);
        }
        $resolved = Status::of($status);
        $reason = $reason === '' ? null : $reason;
        if ($resolved->needsReason() && $reason === null) {
            throw new InvalidArgumentException('An unknown status needs a reason that says what Pulse could not read and why. Pass it as reason, for example {status: \'unknown\', reason: \'the broker did not report the depth\'}, or pass a known status (critical, warning, info, ok).');
        }

        return new self($resolved, $reason);
    }
}
