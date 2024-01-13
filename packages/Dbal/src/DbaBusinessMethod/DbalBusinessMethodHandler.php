<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbaBusinessMethod;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Handler\UnionTypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalContext;
use Generator;
use Interop\Queue\ConnectionFactory;
use InvalidArgumentException;

final class DbalBusinessMethodHandler
{
    public const SQL_HEADER = 'ecotone.dbal.business_method.sql';
    public const IS_INTERFACE_NULLABLE = 'ecotone.dbal.business_method.return_type_is_nullable';
    public const HEADER_FETCH_MODE = 'ecotone.dbal.business_method.fetch_mode';
    public const HEADER_PARAMETER_VALUE_PREFIX = 'ecotone.dbal.business_method.parameter.value.';
    public const HEADER_PARAMETER_TYPE_PREFIX = 'ecotone.dbal.business_method.parameter.type.';

    public function __construct(
        private ConnectionFactory $connectionFactory,
        private ConversionService $conversionService,
        private ExpressionEvaluationService $expressionEvaluationService,
    ) {

    }

    public function executeQuery(
        string $sql,
        bool   $isInterfaceNullable,
        int    $fetchMode,
        array  $headers
    ): ?Message {
        [$parameters, $parameterTypes] = $this->getParameters($headers);
        $query = $this->getConnection()->executeQuery($sql, $parameters, $parameterTypes);

        $result = match($fetchMode) {
            FetchMode::ASSOCIATIVE => $query->fetchAllAssociative(),
            FetchMode::FIRST_COLUMN => $query->fetchFirstColumn(),
            FetchMode::FIRST_ROW => $query->fetchAssociative(),
            FetchMode::FIRST_COLUMN_OF_FIRST_ROW => $query->fetchOne(),
            FetchMode::ITERATE => $this->prepareGenerator($query),
            default => throw new InvalidArgumentException("Unsupported fetch mode {$fetchMode}")
        };

        if ($result === false && $isInterfaceNullable) {
            return null;
        }

        return MessageBuilder::withPayload($result)
                    ->setContentType(MediaType::createApplicationXPHPWithTypeParameter(TypeDescriptor::createFromVariable($result)->toString()))
                    ->build();
    }

    public function executeWrite(string $sql, array $headers): int
    {
        [$parameters, $parameterTypes] = $this->getParameters($headers);

        return $this->getConnection()->executeStatement($sql, $parameters, $parameterTypes);
    }

    /**
     * @return array<array<string, mixed>, array<string, string>>
     */
    private function getParameters(array $headers): array
    {
        /** @var array<string, DbalParameter> $parameterTypes */
        $parameterTypes = [];
        foreach ($headers as $headerName => $headerValue) {
            if (str_starts_with($headerName, self::HEADER_PARAMETER_TYPE_PREFIX)) {
                $parameterTypes[substr($headerName, strlen(self::HEADER_PARAMETER_TYPE_PREFIX))] = $headerValue;
            }
        }

        /** @var array<string, mixed> $originalParameters */
        $originalParameters = [];
        /** @var array<string, mixed> $preparedParameters */
        $preparedParameters = [];
        $preparedParameterTypes = [];
        foreach ($headers as $headerName => $headerValue) {
            if (str_starts_with($headerName, self::HEADER_PARAMETER_VALUE_PREFIX)) {
                $parameterName = substr($headerName, strlen(self::HEADER_PARAMETER_VALUE_PREFIX));

                $originalParameters[$parameterName] = $headerValue;
            }
        }

        /** DbalParameter for parameter */
        foreach ($originalParameters as $parameterName => $parameterValue) {
            if (isset($parameterTypes[$parameterName])) {
                $dbalParameter = $parameterTypes[$parameterName];
                unset($parameterTypes[$parameterName]);

                $parameterValue = $this->getParameterValue($dbalParameter, ['payload' => $parameterValue], $parameterValue);
                if ($dbalParameter->getName()) {
                    $parameterName = $dbalParameter->getName();
                }

                if ($dbalParameter->getType()) {
                    $preparedParameterTypes[$parameterName] = $dbalParameter->getType();
                }
            }

            $preparedParameters[$parameterName] = $this->getParameterValueWithDefaultConversion($parameterValue);
        }

        /** Class/Method leve DbalParameters */
        foreach ($parameterTypes as $dbalParameter) {
            $preparedParameters[$dbalParameter->getName()] = $this->getParameterValue($dbalParameter, $originalParameters, null);
            if ($dbalParameter->getType()) {
                $preparedParameterTypes[$dbalParameter->getName()] = $dbalParameter->getType();
            }
        }

        return [$preparedParameters, $this->autoResolveTypesIfNeeded($preparedParameters, $preparedParameterTypes)];
    }

    private function getConnection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();
        return $context->getDbalConnection();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function getParameterValue(DbalParameter $dbalParameter, array $context, mixed $parameterValue): mixed
    {
        if ($dbalParameter->getExpression()) {
            $parameterValue = $this->expressionEvaluationService->evaluate(
                $dbalParameter->getExpression(),
                $context
            );
        }

        if ($dbalParameter->getConvertToMediaType()) {
            $parameterValue = $this->conversionService->convert(
                $parameterValue,
                TypeDescriptor::createFromVariable($parameterValue),
                MediaType::createApplicationXPHP(),
                TypeDescriptor::createStringType(),
                MediaType::parseMediaType($dbalParameter->getConvertToMediaType())
            );
        }

        return $this->getParameterValueWithDefaultConversion($parameterValue);
    }

    private function autoResolveTypesIfNeeded(array $preparedParameters, array $preparedParameterTypes): array
    {
        foreach ($preparedParameters as $parameterName => $parameterValue) {
            if (! isset($preparedParameterTypes[$parameterName])) {
                $typeDescriptor = TypeDescriptor::createFromVariable($parameterValue);
                if ($typeDescriptor->isCollection() && $typeDescriptor->isSingleTypeCollection()) {
                    $typeDescriptor = $typeDescriptor->resolveGenericTypes()[0];
                    if ($typeDescriptor->isInteger()) {
                        $preparedParameterTypes[$parameterName] = Connection::PARAM_INT_ARRAY;
                    } else {
                        $preparedParameterTypes[$parameterName] = Connection::PARAM_STR_ARRAY;
                    }
                } else {
                    if ($typeDescriptor->isInteger()) {
                        $preparedParameterTypes[$parameterName] = ParameterType::INTEGER;
                    } elseif ($typeDescriptor->isString()) {
                        $preparedParameterTypes[$parameterName] = ParameterType::STRING;
                    } elseif ($typeDescriptor->isBoolean()) {
                        $preparedParameterTypes[$parameterName] = ParameterType::BOOLEAN;
                    }
                }
            }
        }
        return $preparedParameterTypes;
    }

    private function prepareGenerator(\Doctrine\DBAL\Result $query): Generator
    {
        while ($row = $query->fetchAssociative()) {
            yield $row;
        }
    }

    private function getParameterValueWithDefaultConversion(mixed $parameterValue): mixed
    {
        $type = TypeDescriptor::createFromVariable($parameterValue);
        if ($type->isClassOrInterface() && $this->conversionService->canConvert(
            $type,
            MediaType::createApplicationXPHP(),
            UnionTypeDescriptor::createWith([TypeDescriptor::createStringType(), TypeDescriptor::createIntegerType()]),
            MediaType::createApplicationXPHP()
        )) {
            return $this->conversionService->convert(
                $parameterValue,
                $type,
                MediaType::createApplicationXPHP(),
                UnionTypeDescriptor::createWith([TypeDescriptor::createStringType(), TypeDescriptor::createIntegerType()]),
                MediaType::createApplicationXPHP(),
            );
        }

        if ($parameterValue instanceof DateTimeInterface) {
            return $parameterValue->format('Y-m-d H:i:s.u');
        }

        if ($type->isClassOrInterface() && method_exists($parameterValue, '__toString')) {
            return (string) $parameterValue;
        }

        return $parameterValue;
    }
}
