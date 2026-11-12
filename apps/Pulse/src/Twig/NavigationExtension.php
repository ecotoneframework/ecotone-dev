<?php

declare(strict_types=1);

namespace App\Twig;

use App\DeadLetter\DeadLetterOverview;
use App\Snapshot\ServiceSnapshot;
use App\Snapshot\Snapshots;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NavigationExtension extends AbstractExtension
{
    public function __construct(private readonly Snapshots $snapshots)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('dead_letter_failures', $this->deadLetterFailures(...)), new TwigFunction('nav_services', $this->services(...))];
    }

    public function deadLetterFailures(): int
    {
        return DeadLetterOverview::failures($this->snapshots->scopes());
    }

    /**
     * @return list<array{name: string, status: string, failures: int}>
     */
    public function services(): array
    {
        return array_map(static fn (ServiceSnapshot $snapshot): array => [
            'name' => $snapshot->service,
            'status' => $snapshot->status->value,
            'failures' => $snapshot->failureCount() ?? 0,
        ], $this->snapshots->all());
    }
}
