<?php

declare(strict_types=1);

namespace Test\Infrastructure\Deserialization;

use App\Testing\Domain\User\User;
use Ecotone\Lite\EcotoneLite;
use PHPUnit\Framework\TestCase;

final class RegisterUserDeserialization extends TestCase
{
    public function test_deserializing_from_json()
    {
        $ecotoneLite = EcotoneLite::bootstrapForTesting([User::class]);

        $ecotoneLite->getCommandBus()->sendWithRouting("user.register", [

        ]);
    }
}