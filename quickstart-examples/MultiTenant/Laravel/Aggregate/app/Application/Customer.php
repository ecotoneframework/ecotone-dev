<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\IdentifierMethod;
use Illuminate\Database\Eloquent\Model;

#[Aggregate]
class Customer extends Model
{
    protected $table = 'persons';
    protected $fillable = ['customer_id', 'name'];
    protected $primaryKey = 'customer_id';
    public $timestamps = false;

    #[CommandHandler]
    public static function register(RegisterCustomer $command): static
    {
        return self::create([
            'customer_id' => $command->customerId,
            'name' => $command->name
        ]);
    }

    public function getName(): string
    {
        return $this->name;
    }

    #[IdentifierMethod('customer_id')]
    public function getCustomerId(): int
    {
        return $this->customer_id;
    }
}
