<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Hardening\Fixture\MissingReference;

use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class ReportGenerator
{
    #[CommandHandler('missing_reference.generate')]
    public function generate(string $reportName, MissingServiceContract $renderer): string
    {
        return $reportName;
    }
}
