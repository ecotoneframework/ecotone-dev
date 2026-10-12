<?php

declare(strict_types=1);

namespace Ecotone\Api\Amqp;

use Attribute;
use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Messaging\Attribute\MessageConsumer;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Support\Assert;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
#[Attribute]
final class RabbitConsumer extends MessageConsumer implements DefinedObject
{
    public function __construct(
        string $endpointId,
        private string $queueName,
        private FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::STOP,
        private string $connectionReference = AmqpConnectionReference::DEFAULT,
    ) {
        Assert::notNullAndEmpty($queueName, "Queue name can't be empty");

        parent::__construct($endpointId);
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function getConnectionReference(): string
    {
        return $this->connectionReference;
    }

    public function getFinalFailureStrategy(): FinalFailureStrategy
    {
        return $this->finalFailureStrategy;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            self::class,
            [
                $this->getEndpointId(),
                $this->queueName,
                $this->finalFailureStrategy,
                $this->connectionReference,
            ]
        );
    }
}
