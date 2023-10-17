<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure\InMemory;

use Monorepo\ExampleApp\Common\Domain\User\User;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Ramsey\Uuid\UuidInterface;

final class InMemoryUserRepository implements UserRepository
{
    /** @var User[] */
    private array $users;

    /**
     * @param User[] $users
     */
    public function __construct(array $users)
    {
        foreach ($users as $user) {
            $this->save($user);
        }
    }

    public function getBy(UuidInterface $userId): User
    {
        if (!isset($this->users[$userId->toString()])) {
            throw new \RuntimeException(sprintf("User with id %s not found", $userId->toString()));
        }

        return $this->users[$userId->toString()];
    }

    public function save(User $user): void
    {
        $this->users[$user->getUserId()->toString()] = $user;
    }
}