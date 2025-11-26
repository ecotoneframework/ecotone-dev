<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Metadata;

use Ecotone\EventSourcing\EventStore\FieldType as EcotoneFieldType;
use Ecotone\EventSourcing\EventStore\MetadataMatcher as EcotoneMetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator as EcotoneOperator;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Prooph\EventStore\Metadata\FieldType as ProophFieldType;
use Prooph\EventStore\Metadata\MetadataMatcher as ProophMetadataMatcher;
use Prooph\EventStore\Metadata\Operator as ProophOperator;

/**
 * Wrapper around Ecotone's MetadataMatcher that can build Prooph's MetadataMatcher
 * licence Apache-2.0
 */
final class MetadataMatcher implements DefinedObject
{
    private EcotoneMetadataMatcher $ecotoneMetadataMatcher;

    public function __construct(?EcotoneMetadataMatcher $ecotoneMetadataMatcher = null)
    {
        $this->ecotoneMetadataMatcher = $ecotoneMetadataMatcher ?? new EcotoneMetadataMatcher();
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->ecotoneMetadataMatcher], 'createFromEcotone');
    }

    public static function createFromEcotone(EcotoneMetadataMatcher $ecotoneMetadataMatcher): self
    {
        return new self($ecotoneMetadataMatcher);
    }

    public static function create(array $data): self
    {
        return new self(EcotoneMetadataMatcher::create($data));
    }

    public function data(): array
    {
        return $this->ecotoneMetadataMatcher->data();
    }

    public function withMetadataMatch(
        string $field,
        Operator $operator,
        $value,
        ?FieldType $fieldType = null
    ): self {
        $ecotoneOperator = $this->mapOperatorToEcotone($operator);
        $ecotoneFieldType = $fieldType !== null ? $this->mapFieldTypeToEcotone($fieldType) : null;

        $newEcotoneMetadataMatcher = $this->ecotoneMetadataMatcher->withMetadataMatch(
            $field,
            $ecotoneOperator,
            $value,
            $ecotoneFieldType
        );

        return new self($newEcotoneMetadataMatcher);
    }

    public function build(): ProophMetadataMatcher
    {
        $metadataMatcher = new ProophMetadataMatcher();

        foreach ($this->ecotoneMetadataMatcher->data() as $data) {
            $metadataMatcher = $metadataMatcher->withMetadataMatch(
                field: $data['field'],
                operator: ProophOperator::byValue($data['operator']->value),
                value: $data['value'],
                fieldType: $data['fieldType'] !== null ? ProophFieldType::byValue($data['fieldType']->value) : null,
            );
        }

        return $metadataMatcher;
    }

    public function getEcotoneMetadataMatcher(): EcotoneMetadataMatcher
    {
        return $this->ecotoneMetadataMatcher;
    }

    private function mapOperatorToEcotone(Operator $operator): EcotoneOperator
    {
        return match ($operator) {
            Operator::EQUALS => EcotoneOperator::EQUALS,
            Operator::GREATER_THAN => EcotoneOperator::GREATER_THAN,
            Operator::GREATER_THAN_EQUALS => EcotoneOperator::GREATER_THAN_EQUALS,
            Operator::IN => EcotoneOperator::IN,
            Operator::LOWER_THAN => EcotoneOperator::LOWER_THAN,
            Operator::LOWER_THAN_EQUALS => EcotoneOperator::LOWER_THAN_EQUALS,
            Operator::NOT_EQUALS => EcotoneOperator::NOT_EQUALS,
            Operator::NOT_IN => EcotoneOperator::NOT_IN,
            Operator::REGEX => EcotoneOperator::REGEX,
        };
    }

    private function mapFieldTypeToEcotone(FieldType $fieldType): EcotoneFieldType
    {
        return match ($fieldType) {
            FieldType::METADATA => EcotoneFieldType::METADATA,
            FieldType::MESSAGE_PROPERTY => EcotoneFieldType::MESSAGE_PROPERTY,
        };
    }
}
