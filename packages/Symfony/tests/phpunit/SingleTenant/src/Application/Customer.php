<?php

declare(strict_types=1);

namespace Symfony\App\SingleTenant\Application;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Distributed;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithEvents;
use RuntimeException;
use Symfony\App\SingleTenant\Application\Command\RegisterCustomer;
use Symfony\App\SingleTenant\Application\Event\CustomerWasRegistered;
use Symfony\App\SingleTenant\Application\External\ExternalRegistrationHappened;

// Important Attribute to tell Ecotone that this is an Aggregate
#[Aggregate]
#[Entity]
#[Table(name: 'persons')]
class Customer
{
    use WithEvents;
    // Important Attribute to tell Ecotone that this is an Identifier
    #[Identifier]
    #[Id]
    #[Column(type: 'integer', name: 'customer_id')]
    private int $customerId;
    #[Column(type: 'string')]
    private string $name;

    private function __construct()
    {
    }

    #[CommandHandler]
    public static function register(
        RegisterCustomer $command,
        #[Header('shouldThrowException')] bool $shouldThrowException = false
    ): static {
        $self = new self();
        $self->customerId = $command->customerId;
        $self->name = $command->name;
        $self->recordThat(new CustomerWasRegistered($command->customerId));

        if ($shouldThrowException) {
            throw new RuntimeException('Rollback transaction');
        }

        return $self;
    }

    #[EventHandler]
    public static function registerFromEvent(ExternalRegistrationHappened $event): self
    {
        $self = new self();
        $self->customerId = $event->customerId;
        $self->name = $event->name;
        $self->recordThat(new CustomerWasRegistered($event->customerId));

        return $self;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCustomerId(): int
    {
        return $this->customerId;
    }
}
