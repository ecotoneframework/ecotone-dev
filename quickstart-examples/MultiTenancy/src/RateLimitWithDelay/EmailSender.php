<?php

declare(strict_types=1);

namespace App\MultiTenancy\RateLimitWithDelay;

final class EmailSender
{
    /** @var string[] */
    private array $emails = [];

    public function send(string $email) : void
    {
        $this->emails[] = $email;
    }

    public function getEmails() : array
    {
        return $this->emails;
    }
}