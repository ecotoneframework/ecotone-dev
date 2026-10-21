<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class BreakableExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('breakable', $this->breakable(...), ['is_safe' => ['html']])];
    }

    public function breakable(mixed $value): string
    {
        return preg_replace('/([._:])(?=[^\s<])/', '$1<wbr>', htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE)) ?? '';
    }
}
