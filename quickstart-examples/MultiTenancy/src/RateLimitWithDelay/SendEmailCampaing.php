<?php

namespace App\MultiTenancy\RateLimitWithDelay;

readonly class SendEmailCampaing
{
    /**
     * @param string[] $emails
     */
    public function __construct(public array $emails)
    {
    }
}