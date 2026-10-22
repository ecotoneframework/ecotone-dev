<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\Lite\Test\FlowTestSupport;

trait FailureRepliesTrait
{
    /**
     * @var array<string, FlowTestSupport>
     */
    private array $refusingServices = [];

    private function serviceRefuses(string $service, string $requestKind, string $message, ?string $tenant = null, ?string $queryId = null): void
    {
        $this->refusingServices[$service] ??= $this->serviceApplication($service, [], [], false);
        $this->refusingServices[$service]->getDistributedBus()->sendMessage(
            'ecotone_pulse',
            $queryId === null ? 'ecotone.pulse.snapshot_received' : 'ecotone.pulse.query_result_received',
            json_encode(['requestKind' => $requestKind, 'serviceName' => $service, 'tenant' => $tenant, 'message' => $message, 'queryId' => $queryId, 'tracingId' => null, 'failedAt' => gmdate('c')], JSON_THROW_ON_ERROR),
            MediaType::APPLICATION_JSON,
            metadata: $queryId === null ? [] : ['ecotone_pulse_query_id' => $queryId],
        );
    }
}
