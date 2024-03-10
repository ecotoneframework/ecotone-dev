<?php

namespace Monorepo\ExampleApp\Common\Infrastructure;

use Monorepo\ExampleApp\Common\Domain\Money;
use Monorepo\ExampleApp\Common\Domain\Product\Product;
use Monorepo\ExampleApp\Common\Domain\Product\ProductDetails;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Domain\User\User;
use Monorepo\ExampleApp\Common\Domain\User\UserRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryProductRepository;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryUserRepository;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class Configuration
{
    private UuidInterface $failToNotifyOrder;

    private UuidInterface $userId;

    private UuidInterface $productId;

    private array $registeredUser;


    public function __construct()
    {
        $this->userId = Uuid::uuid4();
        $this->productId = Uuid::uuid4();
        $this->registeredUser = [new User($this->userId, 'John Travolta')];
        $this->failToNotifyOrder = Uuid::uuid4();
    }

    public function users(): array
    {
        return $this->registeredUser;
    }

    public function userRepository(): UserRepository
    {
        return new InMemoryUserRepository($this->users());
    }

    public function productRepository(): ProductRepository
    {
        return new InMemoryProductRepository([new Product($this->productId, new ProductDetails("Table", new Money()))]);
    }

    public function authentication(): AuthenticationService
    {
        return new AuthenticationService($this->userId);
    }

    public function userId(): UuidInterface
    {
        return $this->userId;
    }

    public function productId(): UuidInterface
    {
        return $this->productId;
    }

    public function failToNotifyOrder(): UuidInterface
    {
        return $this->failToNotifyOrder;
    }
}