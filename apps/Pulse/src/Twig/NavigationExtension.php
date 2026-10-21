<?php

declare(strict_types=1);

namespace App\Twig;

use App\DeadLetter\DeadLetterOverview;
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
        return [new TwigFunction('dead_letter_failures', $this->deadLetterFailures(...))];
    }

    public function deadLetterFailures(): int
    {
        return DeadLetterOverview::failures($this->snapshots->all());
    }
}
