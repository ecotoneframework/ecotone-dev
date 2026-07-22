<?php

declare(strict_types=1);

namespace Ecotone\SymfonyContainer;

use Ecotone\Messaging\Config\ConfigurationException;

/**
 * Boot-time validation of the external references wired into the compiled
 * messaging container. External references are the finite, compile-time
 * recorded set of host-framework services that Ecotone's own definitions
 * depend on (handler classes, referenced services like a Mailer). Validating
 * them at boot converts a runtime wiring failure — which leaves a partially
 * built channel behind and surfaces as a misleading "no message handler
 * registered" error — into one aggregate, honest error before any message
 * flows.
 *
 * licence Apache-2.0
 */
final class ExternalReferenceBootValidator
{
    public static function validate(
        EcotoneContainer $ecotoneContainer,
        ExternalServiceAvailabilityProbe $probe,
        string $registrationHint,
    ): void {
        $missing = [];

        foreach ($ecotoneContainer->getRequiredExternalReferenceIds() as $referenceId) {
            if (! $probe->canProvide($referenceId)) {
                $missing[] = $referenceId;
            }
        }

        if ($missing === []) {
            return;
        }

        throw ConfigurationException::create(sprintf(
            "Ecotone boot validation failed. %d external service(s) referenced by your messaging configuration cannot be provided by the application container:\n - %s\n%s\nThese references are wired into message handlers and would otherwise fail at runtime with a misleading error.",
            count($missing),
            implode("\n - ", $missing),
            $registrationHint,
        ));
    }
}
