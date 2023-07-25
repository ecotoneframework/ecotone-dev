<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\AggregateWithGateway;

use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ramsey\Uuid\UuidInterface;

interface BucketGateway
{
    #[MessageGateway(Bucket::ADD)]
    public function add(#[AggregateIdentifier] UuidInterface $bucketId, #[Payload] array $command): void;

    #[MessageGateway(Bucket::GET)]
    public function get(#[AggregateIdentifier] UuidInterface $bucketId, #[Payload] UuidInterface $key): string;
}
