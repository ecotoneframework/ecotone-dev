<?php

declare(strict_types=1);

namespace Ecotone\Modelling\AggregateFlow\SaveAggregate;

use Ecotone\Messaging\Handler\Enricher\PropertyEditorAccessor;
use Ecotone\Messaging\Handler\Enricher\PropertyPath;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\AggregateIdResolver;
use Ecotone\Modelling\AggregateMessage;
use Ecotone\Modelling\NoAggregateFoundToBeSaved;
use Ecotone\Modelling\NoCorrectIdentifierDefinedException;
use Ecotone\Modelling\SaveAggregateService;
use Ecotone\Modelling\StandardRepository;

final class SaveStateBasedAggregateService implements SaveAggregateService
{
    public function __construct(
        private string                 $calledClass,
        private bool                   $isFactoryMethod,
        private StandardRepository     $aggregateRepository,
        private PropertyEditorAccessor $propertyEditorAccessor,
        private PropertyReaderAccessor $propertyReaderAccessor,
        private array                  $aggregateIdentifierMapping,
        private array                  $aggregateIdentifierGetMethods,
        private ?string                $aggregateVersionProperty,
        private bool                   $isAggregateVersionAutomaticallyIncreased
    ) {
    }

    public function save(Message $message, array $metadata): Message
    {
        $aggregate = SaveAggregateServiceTemplate::resolveAggregate($this->calledClass, $message, $this->isFactoryMethod);
        $versionBeforeHandling = SaveAggregateServiceTemplate::resolveVersionBeforeHandling($message);
        SaveAggregateServiceTemplate::enrichVersionIfNeeded(
            $this->propertyEditorAccessor,
            $versionBeforeHandling,
            $aggregate,
            $message,
            $this->aggregateVersionProperty,
            $this->isAggregateVersionAutomaticallyIncreased
        );

        $aggregateIds = $metadata[AggregateMessage::AGGREGATE_ID] ?? [];
        $aggregateIds = $this->getAggregateIds($aggregateIds, $aggregate, false);

        $metadata = MessageHeaders::unsetNonUserKeys($metadata);
        $this->aggregateRepository->save($aggregateIds, $aggregate, $metadata, $versionBeforeHandling);

        $aggregateIds = $this->getAggregateIds($aggregateIds, $aggregate, true);
        if ($this->isFactoryMethod) {
            if (count($aggregateIds) === 1) {
                $aggregateIds = reset($aggregateIds);
            }

            $message = MessageBuilder::fromMessage($message)
                ->setPayload($aggregateIds)
                ->build()
            ;
        }

        return MessageBuilder::fromMessage($message)
            ->build();
    }

    private function getAggregateIds(mixed $aggregateIds, object|string $aggregate, bool $throwOnNoIdentifier): array
    {
        $aggregateIds = SaveAggregateServiceTemplate::getAggregateIds(
            $this->propertyReaderAccessor,
            $this->calledClass,
            $this->aggregateIdentifierMapping,
            $this->aggregateIdentifierGetMethods,
            $aggregateIds,
            $aggregate,
            $throwOnNoIdentifier
        );
        return $aggregateIds;
    }
}
