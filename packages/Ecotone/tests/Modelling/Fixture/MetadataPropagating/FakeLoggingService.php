<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\MetadataPropagating;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Interceptor\Around;
use Ecotone\Api\Attribute\Parameter\Headers;
use Ecotone\Api\Attribute\Parameter\Reference;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
final class FakeLoggingService
{
    private array $beforeLogHeaders = [];
    private array $afterLogHeaders = [];

    #[Around(pointcut: PropagatingGateway::class)]
    public function intercept(MethodInvocation $methodInvocation, Message $message, #[Reference] FakeLoggingGateway $fakeLoggingGateway): mixed
    {
        $fakeLoggingGateway->logBefore($message);
        $result = $methodInvocation->proceed();
        $fakeLoggingGateway->logAfter($message);

        return $result;
    }

    #[CommandHandler('beforeLog')]
    public function beforeLog(#[Headers] array $headers): void
    {
        $this->beforeLogHeaders = $headers;
    }

    #[CommandHandler('afterLog')]
    public function afterLog(#[Headers] array $headers): void
    {
        $this->afterLogHeaders = $headers;
    }

    #[QueryHandler('getBeforeLogHeaders')]
    public function getBeforeLogHeaders(): array
    {
        return $this->beforeLogHeaders;
    }

    #[QueryHandler('getAfterLogHeaders')]
    public function getAfterLogHeaders(): array
    {
        return $this->afterLogHeaders;
    }
}
