<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'persons';
    protected $fillable = ['customer_id', 'name'];
    protected $primaryKey = 'customer_id';
    public $timestamps = false;

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
}
