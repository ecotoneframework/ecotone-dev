<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Tooling;

trait WaitForUserInputTrait
{
    private bool $isActive = false;

    public static function getMessage(): string
    {
        return "Press enter to continue...\n";
    }

    protected function waitForUserInput(): void
    {
        if ($this->isActive) {
            echo $this->getMessage();
            fgets(STDIN);
        }
    }

    public function enable(): void
    {
        $this->isActive = true;
    }

    public function disable(): void
    {
        $this->isActive = false;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
