<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\User;

use Ecotone\Modelling\Attribute\CommandHandler;

final class UserService
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    #[CommandHandler('user.register')]
    public function register(string $userId)
    {
        $this->userRepository->save(User::register($userId));
    }
}
