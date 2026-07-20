<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Ecotone\Messaging\Console\ConsoleProgressBar;
use Tempest\Console\Console;

/**
 * licence Apache-2.0
 */
final class TempestConsoleProgressBar implements ConsoleProgressBar
{
    private int $currentStep = 0;

    public function __construct(private Console $console, private int $maxSteps)
    {
    }

    public function advance(int $steps = 1): void
    {
        $this->currentStep += $steps;
        $this->console->writeRaw("\r" . $this->renderProgress());
    }

    public function finish(): void
    {
        if ($this->maxSteps > 0 && $this->currentStep < $this->maxSteps) {
            $this->currentStep = $this->maxSteps;
            $this->console->writeRaw("\r" . $this->renderProgress());
        }
        $this->console->writeln();
    }

    private function renderProgress(): string
    {
        return $this->maxSteps > 0
            ? "{$this->currentStep}/{$this->maxSteps}"
            : (string) $this->currentStep;
    }
}
