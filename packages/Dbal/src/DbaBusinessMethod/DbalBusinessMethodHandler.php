<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbaBusinessMethod;

use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;
use Ecotone\Messaging\Message;

final readonly class DbalBusinessMethodHandler
{
    public const SQL_HEADER = "ecotone.dbal.business_method.sql";
    public const IS_INTERFACE_NULLABLE = "ecotone.dbal.business_method.return_type";
    public const HEADER_FETCH_MODE = "ecotone.dbal.business_method.fetch_mode";
    public const HEADER_PARAMETER_VALUE_PREFIX = "ecotone.dbal.business_method.parameter.value.";
    public const HEADER_PARAMETER_TYPE_PREFIX = "ecotone.dbal.business_method.parameter.type.";

    public function __construct(
        private ConnectionFactory $connectionFactory,
        private ConversionService $conversionService,
        private ExpressionEvaluationService $expressionEvaluationService,
    )
    {

    }

    public function executeQuery(
        string $sql,
        bool   $isInterfaceNullable,
        int    $fetchMode,
        array  $headers
    ): ?Message
    {
        $parameters = $this->getParameters($headers);
        $query = $this->getConnection()->executeQuery($sql, $parameters);

        $result = match($fetchMode) {
            FetchMode::ASSOCIATIVE => $query->fetchAllAssociative(),
            FetchMode::FIRST_COLUMN => $query->fetchFirstColumn(),
            FetchMode::FIRST_ROW => $query->fetchAssociative(),
            FetchMode::FIRST_COLUMN_OF_FIRST_ROW => $query->fetchOne(),
            default => throw new \InvalidArgumentException("Unsupported fetch mode {$fetchMode}")
        };

        $type = TypeDescriptor::createFromVariable($result);
        if ($type->isNonCollectionArray()) {
            $type = TypeDescriptor::create('array<string, string>');
        }

        if ($result === false && $isInterfaceNullable) {
            return null;
        }

        return MessageBuilder::withPayload($result)
                    ->setContentType(MediaType::createApplicationXPHPWithTypeParameter($type->toString()))
                    ->build();
    }

    public function executeWrite(string $sql, array $headers): int
    {
        $parameters = $this->getParameters($headers);

        return $this->getConnection()->executeStatement($sql, $parameters);
    }

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
        foreach ($headers as $headerName => $headerValue) {
            if (str_starts_with($headerName, self::HEADER_PARAMETER_VALUE_PREFIX)) {
                $parameterName = substr($headerName, strlen(self::HEADER_PARAMETER_VALUE_PREFIX));

                $originalParameters[$parameterName] = $headerValue;
                $preparedParameters[$parameterName] = $headerValue;
            }
        }

        /** DbalParameter for parameter */
        foreach ($originalParameters as $parameterName => $parameterValue) {
            if (isset($parameterTypes[$parameterName])) {
                $dbalParameter = $parameterTypes[$parameterName];

                $parameterValue = $this->getParameterValue($dbalParameter, ['payload' => $parameterValue], $parameterValue);
                if ($dbalParameter->getName()) {
                    $parameterName = $dbalParameter->getName();
                }
                unset($parameterTypes[$parameterName]);
            }

            $preparedParameters[$parameterName] = $parameterValue;
        }

        /** Class/Method leve DbalParameters */
        foreach ($parameterTypes as $dbalParameter) {
            if ($dbalParameter->getExpression()) {
                $preparedParameters[$dbalParameter->getName()] = $this->getParameterValue($dbalParameter, $originalParameters, null);
            }
        }

        return $preparedParameters;
    }

    private function getConnection(): \Doctrine\DBAL\Connection
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

        return $parameterValue;
    }
}