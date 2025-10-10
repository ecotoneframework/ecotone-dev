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
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\Type\GenericType;
use Ecotone\Messaging\Handler\Type\UnionType;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalContext;
use Generator;
use Interop\Queue\ConnectionFactory;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class DbalBusinessMethodHandler
{
    public const SQL_HEADER = 'ecotone.dbal.business_method.sql';
    public const IS_INTERFACE_NULLABLE = 'ecotone.dbal.business_method.return_type_is_nullable';
    public const HEADER_FETCH_MODE = 'ecotone.dbal.business_method.fetch_mode';
    public const HEADER_PARAMETER_VALUE_PREFIX = 'ecotone.dbal.business_method.parameter.value.';
    public const HEADER_PARAMETER_TYPE_PREFIX = 'ecotone.dbal.business_method.parameter.type.';

    private const SQL_EXPRESSION_PARAMETERS_REGEX = '#:\(([^\)]+)\)#';

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
        [$sql, $parameters, $parameterTypes] = $this->prepareExecution($sql, $headers);

        // Convert parameter types to be compatible with both DBAL 3.x and 4.x
        $convertedParameterTypes = $this->convertParameterTypes($parameterTypes);

        $query = $this->getConnection()->executeQuery($sql, $parameters, $convertedParameterTypes);

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
                    ->setContentType(MediaType::createApplicationXPHPWithTypeParameter(Type::createFromVariable($result)->toString()))
                    ->build();
    }

    public function executeWrite(string $sql, array $headers): int
    {
        [$sql, $parameters, $parameterTypes] = $this->prepareExecution($sql, $headers);

        // Convert parameter types to be compatible with both DBAL 3.x and 4.x
        $convertedParameterTypes = $this->convertParameterTypes($parameterTypes);

        return $this->getConnection()->executeStatement($sql, $parameters, $convertedParameterTypes);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function prepareExecution(string $sql, array $headers): array
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

        preg_match_all(self::SQL_EXPRESSION_PARAMETERS_REGEX, $sql, $matches);
        foreach ($matches[1] as $key => $expression) {
            $parameterNameGenerated = 'ecotone_parameter_' . $key;

            $preparedParameters[$parameterNameGenerated] = $this->expressionEvaluationService->evaluate(
                $expression,
                $originalParameters
            );
            $sql = str_replace($matches[0][$key], ':' . $parameterNameGenerated, $sql);
        }

        return [$sql, $preparedParameters, $this->autoResolveTypesIfNeeded($preparedParameters, $preparedParameterTypes)];
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
                Type::createFromVariable($parameterValue),
                MediaType::createApplicationXPHP(),
                Type::string(),
                MediaType::parseMediaType($dbalParameter->getConvertToMediaType())
            );
        }

        return $this->getParameterValueWithDefaultConversion($parameterValue);
    }

    private function autoResolveTypesIfNeeded(array $preparedParameters, array $preparedParameterTypes): array
    {
        foreach ($preparedParameters as $parameterName => $parameterValue) {
            if (! isset($preparedParameterTypes[$parameterName])) {
                $typeDescriptor = Type::createFromVariable($parameterValue);
                if ($typeDescriptor instanceof GenericType && $typeDescriptor->isCollection()) {
                    $typeDescriptor = $typeDescriptor->genericTypes[0];
                    if ($typeDescriptor->isInteger()) {
                        $preparedParameterTypes[$parameterName] = $this->getArrayIntegerTypeValue();
                    } else {
                        $preparedParameterTypes[$parameterName] = $this->getArrayStringTypeValue();
                    }
                } else {
                    if ($typeDescriptor->isInteger()) {
                        $preparedParameterTypes[$parameterName] = $this->convertScalarParameterType(ParameterType::INTEGER);
                    } elseif ($typeDescriptor->isString()) {
                        $preparedParameterTypes[$parameterName] = $this->convertScalarParameterType(ParameterType::STRING);
                    } elseif ($typeDescriptor->isBoolean()) {
                        $preparedParameterTypes[$parameterName] = $this->convertScalarParameterType(ParameterType::BOOLEAN);
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
        $type = Type::createFromVariable($parameterValue);
        if ($type->isClassOrInterface() && $this->conversionService->canConvert(
            $type,
            MediaType::createApplicationXPHP(),
            UnionType::createWith([Type::string(), Type::int()]),
            MediaType::createApplicationXPHP()
        )) {
            return $this->conversionService->convert(
                $parameterValue,
                $type,
                MediaType::createApplicationXPHP(),
                UnionType::createWith([Type::string(), Type::int()]),
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

    /**
     * Convert parameter types to be compatible with both DBAL 3.x and 4.x
     */
    private function convertParameterTypes(array $parameterTypes): array
    {
        $convertedParameterTypes = [];
        foreach ($parameterTypes as $key => $type) {
            if (is_int($type)) {
                // Handle array parameter types
                if ($type === $this->getArrayIntegerTypeValue()) {
                    $convertedParameterTypes[$key] = $this->getArrayIntegerType();
                } elseif ($type === $this->getArrayStringTypeValue()) {
                    $convertedParameterTypes[$key] = $this->getArrayStringType();
                } else {
                    // Handle scalar parameter types
                    $convertedParameterTypes[$key] = $this->convertScalarParameterType($type);
                }
            } else {
                $convertedParameterTypes[$key] = $type;
            }
        }

        return $convertedParameterTypes;
    }

    /**
     * Get the appropriate ArrayParameterType::INTEGER for the current DBAL version
     */
    private function getArrayIntegerType()
    {
        // For DBAL 4.x (enum)
        if (class_exists('\Doctrine\DBAL\ArrayParameterType') && enum_exists('\Doctrine\DBAL\ArrayParameterType')) {
            return \Doctrine\DBAL\ArrayParameterType::INTEGER;
        }

        // For DBAL 3.x (integer constant)
        return $this->getArrayIntegerTypeValue();
    }

    /**
     * Get the appropriate ArrayParameterType::STRING for the current DBAL version
     */
    private function getArrayStringType()
    {
        // For DBAL 4.x (enum)
        if (class_exists('\Doctrine\DBAL\ArrayParameterType') && enum_exists('\Doctrine\DBAL\ArrayParameterType')) {
            return \Doctrine\DBAL\ArrayParameterType::STRING;
        }

        // For DBAL 3.x (integer constant)
        return $this->getArrayStringTypeValue();
    }

    /**
     * Get the integer value for ArrayParameterType::INTEGER
     */
    private function getArrayIntegerTypeValue(): int
    {
        // DBAL 3.x uses Connection::ARRAY_PARAM_OFFSET (100) + ParameterType::INTEGER (1) = 101
        // But the actual implementation uses 102 for INTEGER arrays
        return 102;
    }

    /**
     * Get the integer value for ArrayParameterType::STRING
     */
    private function getArrayStringTypeValue(): int
    {
        // DBAL 3.x uses Connection::ARRAY_PARAM_OFFSET (100) + ParameterType::STRING (2) = 102
        // But the actual implementation uses 101 for STRING arrays
        return 101;
    }

    /**
     * Convert scalar parameter types between DBAL 3.x and 4.x
     */
    private function convertScalarParameterType($type)
    {
        // If we're using DBAL 4.x with enum ParameterType
        if (enum_exists('\Doctrine\DBAL\ParameterType')) {
            return match($type) {
                1 => ParameterType::INTEGER,
                2 => ParameterType::STRING,
                3 => ParameterType::LARGE_OBJECT,
                5 => ParameterType::BOOLEAN,
                16 => ParameterType::BINARY,
                17 => ParameterType::ASCII,
                default => $type,
            };
        }

        // For DBAL 3.x, just return the integer constant
        return $type;
    }
}
