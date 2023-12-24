<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbaBusinessMethod;

use Ecotone\Dbal\Attribute\DbalParameter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;
use Ecotone\Messaging\Message;

final readonly class DbalBusinessMethodHandler
{
    public const SQL_HEADER = "ecotone.dbal.business_method.sql";
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

    public function executeQuery(string $sql, int $fetchMode, array $headers): Message
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

        return MessageBuilder::withPayload($result)
                    ->setContentType(MediaType::createApplicationXPHPWithTypeParameter('array<string, string>'))
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

        $parameters = [];
        foreach ($headers as $headerName => $headerValue) {
            if (str_starts_with($headerName, self::HEADER_PARAMETER_VALUE_PREFIX)) {
                $parameterName = substr($headerName, strlen(self::HEADER_PARAMETER_VALUE_PREFIX));

                if (isset($parameterTypes[$parameterName])) {
                    $dbalParameter = $parameterTypes[$parameterName];

                    if ($dbalParameter->getExpression()) {
                        $headerValue = $this->expressionEvaluationService->evaluate(
                            $dbalParameter->getExpression(),
                            [
                                'payload' => $headerValue
                            ]
                        );
                    }

                    if ($dbalParameter->getConvertToMediaType()) {
                        $headerValue = $this->conversionService->convert(
                            $headerValue,
                            TypeDescriptor::createFromVariable($headerValue),
                            MediaType::createApplicationXPHP(),
                            TypeDescriptor::createStringType(),
                            MediaType::parseMediaType($dbalParameter->getConvertToMediaType())
                        );
                    }
                }

                $parameters[$parameterName] = $headerValue;
            }
        }
        return $parameters;
    }

    private function getConnection(): \Doctrine\DBAL\Connection
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();
        return $context->getDbalConnection();
    }
}