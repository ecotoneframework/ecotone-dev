<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Converter;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Payload;
use Ecotone\Api\Messaging\CollectedMessages;
use RuntimeException;

/**
 * licence Apache-2.0
 */
return new class () {
    public array $batches = [];

    #[Collected(maxSize: 2, groupBy: static function (CollectedEventForConformance $event, #[Header('tenant')] string $tenant): string {
        return $tenant . ':' . $event->tenant;
    })]
    #[Asynchronous('batches')]
    #[CommandHandler('collect', endpointId: 'collect.handler')]
    public function collect(CollectedMessages $batch): void
    {
        $this->batches[] = $batch;
    }

    #[Collected(maxSize: 2, groupBy: static function (#[Header('tenant')] string $tenant, #[Payload] CollectedEventForConformance $event): string {
        return $tenant . ':' . $event->tenant;
    })]
    #[Asynchronous('batches')]
    #[CommandHandler('collect.explicit', endpointId: 'collect.explicit.handler')]
    public function explicit(CollectedMessages $batch): void
    {
        $this->batches[] = $batch;
    }

    #[Collected(maxSize: 2, groupBy: static function (CollectedEventForConformance $event): string {
        throw new RuntimeException('group key unavailable');
    })]
    #[Asynchronous('batches')]
    #[CommandHandler('collect.failing', endpointId: 'collect.failing.handler')]
    public function failingKey(CollectedMessages $batch): void
    {
    }

    #[Collected(maxSize: 2, groupBy: static function (CollectedEventForConformance $event): ?string {
        return null;
    })]
    #[Asynchronous('batches')]
    #[CommandHandler('collect.missing', endpointId: 'collect.missing.handler')]
    public function missingKey(CollectedMessages $batch): void
    {
    }

    #[Converter]
    public function convert(string $payload): CollectedEventForConformance
    {
        [$tenant, $value] = explode(':', $payload);
        return new CollectedEventForConformance($tenant, $value);
    }
};
