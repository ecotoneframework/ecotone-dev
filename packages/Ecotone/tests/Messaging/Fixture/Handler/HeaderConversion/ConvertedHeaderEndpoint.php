<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\HeaderConversion;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Header;
use Ramsey\Uuid\UuidInterface;

/**
 * licence Apache-2.0
 */
final class ConvertedHeaderEndpoint
{
    private mixed $result;

    #[Asynchronous('async')]
    #[CommandHandler('withScalarConversion', endpointId: 'withScalarConversionEndpoint')]
    public function handleWithScalarConversion(
        #[Header('token')] UuidInterface $token
    ) {
        $this->result = $token;
    }

    #[Asynchronous('async')]
    #[CommandHandler('withFallbackConversion', endpointId: 'withFallbackConversionEndpoint')]
    public function handleWithFallbackConversion(
        #[Header('tokens')] array $tokens
    ) {
        $this->result = $tokens;
    }

    public function result(): mixed
    {
        return $this->result;
    }
}
