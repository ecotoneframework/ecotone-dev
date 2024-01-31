<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifierMethod;
use Ecotone\Modelling\Attribute\CommandHandler;
use Illuminate\Database\Eloquent\Model;

#[Aggregate]
class Customer extends Model
{
    protected $table = 'persons';
    protected $fillable = ['customer_id', 'name'];
    protected $primaryKey = 'customer_id';
    public $timestamps = false;

    private function __construct(int $customerId, string $name)
    {
        $this->customer_id = $customerId;
        $this->name = $name;
    }

    #[CommandHandler]
    public static function register(RegisterCustomer $command): static
    {
        return new self($command->customerId, $command->name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    #[AggregateIdentifierMethod('customer_id')]
    public function getCustomerId(): int
    {
        return $this->customer_id;
    }
}
