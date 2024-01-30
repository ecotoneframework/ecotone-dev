<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Ecotone\Modelling\Attribute\CommandHandler;

final class RegisterPersonHandler
{
    #[CommandHandler]
    public function handle(RegisterPerson $command)
    {
        Person::register($command)->save();
    }
}