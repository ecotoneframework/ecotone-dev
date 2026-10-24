<?php

declare(strict_types=1);

namespace App\Snapshot;

use App\Settings\PulseSettings;
use DateTimeImmutable;
use Ecotone\Api\Attribute\InternalHandler;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class SnapshotReceiver
{
    public const ROUTING_KEY = 'ecotone.pulse.snapshot_received';
    public const SUPPORTED_SCHEMA_VERSION = 1;

    public function __construct(
        private SnapshotStore $store,
        #[Autowire(service: 'pulse.clock')] private ClockInterface $clock,
        private PulseSettings $settings,
        private LoggerInterface $logger,
    ) {
    }

    #[InternalHandler(self::ROUTING_KEY)]
    public function receive(string $snapshot): void
    {
        $decoded = json_decode($snapshot, true);
        $service = is_array($decoded) ? ($decoded['serviceName'] ?? null) : null;
        if (! is_string($service) || ! isset($this->settings->serviceChannels[$service])) {
            $this->logger->warning('Pulse ignored a snapshot reply it cannot attribute to a configured service.', ['reply' => mb_substr($snapshot, 0, 200)]);

            return;
        }

        $tenant = $decoded['tenant'] ?? null;
        $scope = is_string($tenant) ? ServiceScope::ofTenant($service, $tenant) : ServiceScope::ofService($service);
        if (FailureReply::isOne($decoded)) {
            $failure = FailureReply::of($decoded);
            $now = DateTimeImmutable::createFromInterface($this->clock->now());
            $this->store->update($scope, fn (ServiceRecord $record) => $record->refused($failure->requestKind, $failure->message, $failure->failedAt, $now));

            return;
        }
        $problem = $this->problemWith($decoded, $scope);
        $this->store->update($scope, fn (ServiceRecord $record) => $problem === null
            ? $record->received($decoded, DateTimeImmutable::createFromInterface($this->clock->now()))
            : $record->replyRejected($problem));
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function problemWith(array $snapshot, ServiceScope $scope): ?string
    {
        $schemaVersion = $snapshot['schemaVersion'] ?? null;
        if ($schemaVersion !== self::SUPPORTED_SCHEMA_VERSION) {
            return sprintf('Snapshot with schema version %s is not supported, Pulse reads schema version %d. Update Pulse or the service so both speak the same schema version.', is_scalar($schemaVersion) ? (string) $schemaVersion : 'unknown', self::SUPPORTED_SCHEMA_VERSION);
        }
        if ($scope->tenant === null && ! is_array($snapshot['overview'] ?? null)) {
            return 'Snapshot has no overview section. Update the service to a version that answers Pulse snapshot requests.';
        }

        return null;
    }
}
