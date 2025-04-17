<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Metadata;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;

use function is_array;
use function is_scalar;
use function is_string;

use Prooph\EventStore\Exception\InvalidArgumentException;
use Prooph\EventStore\Metadata\FieldType as ProophFieldType;
use Prooph\EventStore\Metadata\MetadataMatcher as ProophMetadataMatcher;
use Prooph\EventStore\Metadata\Operator as ProophOperator;

use function sprintf;

/**
 * licence Apache-2.0
 */
final class MetadataMatcher implements DefinedObject
{
    private array $data = [];

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->data], 'create');
    }

    public static function create(array $data): self
    {
        $mather = new self();
        $mather->data = $data;

        return $mather;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function withMetadataMatch(
        string $field,
        Operator $operator,
        $value,
        ?FieldType $fieldType = null
    ): self {
        $this->validateValue($operator, $value);

        if (null === $fieldType) {
            $fieldType = FieldType::METADATA;
        }

        $self = clone $this;
        $self->data[] = ['field' => $field, 'operator' => $operator, 'value' => $value, 'fieldType' => $fieldType];

        return $self;
    }

    /**
     * @param Operator $operator
     * @param mixed $value
     * @throws InvalidArgumentException
     */
    private function validateValue(Operator $operator, $value): void
    {
        if ($operator === Operator::IN || $operator === Operator::NOT_IN) {
            if (is_array($value)) {
                return;
            }

            throw new InvalidArgumentException(sprintf('Value must be an array for the operator %s.', $operator->name));
        }

        if ($operator === Operator::REGEX && ! is_string($value)) {
            throw new InvalidArgumentException('Value must be a string for the regex operator.');
        }

        if (! is_scalar($value)) {
            throw new InvalidArgumentException(sprintf('Value must have a scalar type for the operator %s.', $operator->name));
        }
    }

    public function build(): ProophMetadataMatcher
    {
        $metadataMatcher = new ProophMetadataMatcher();

        foreach ($this->data as $data) {
            $metadataMatcher = $metadataMatcher->withMetadataMatch(
                field: $data['field'],
                operator: ProophOperator::byValue($data['operator']->value),
                value: $data['value'],
                fieldType: $data['fieldType'] !== null ? ProophFieldType::byValue($data['fieldType']->value) : null,
            );
        }

        return $metadataMatcher;
    }
}
