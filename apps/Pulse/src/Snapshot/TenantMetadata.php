<?php

declare(strict_types=1);

namespace App\Snapshot;

use LogicException;

final readonly class TenantMetadata
{
    public function __construct(private SnapshotStore $store)
    {
    }

    /**
     * @return array<string, string>
     */
    public function of(ServiceScope $scope): array
    {
        if ($scope->tenant === null) {
            return [];
        }

        $header = $this->store->read(ServiceScope::ofService($scope->service))->snapshot['tenancy']['header'] ?? null;
        if (! is_string($header)) {
            throw new LogicException(sprintf('Pulse asked service "%s" for tenant "%s", but the service has not advertised a tenant header. A multi-tenant service advertises it in the `tenancy` section of its snapshot, so ask for the service snapshot first, and give the service a MultiTenantConfiguration.', $scope->service, $scope->tenant));
        }

        return [$header => $scope->tenant];
    }
}
