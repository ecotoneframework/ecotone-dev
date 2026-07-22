<?php

declare(strict_types=1);

namespace App\BootValidation\Laravel\Shared;

use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class ReportGenerator
{
    #[CommandHandler('boot.generate')]
    public function generate(string $reportName, MissingServiceContract $renderer): string
    {
        return $reportName;
    }
}
