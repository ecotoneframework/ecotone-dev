<?php

declare(strict_types=1);

namespace App\Customer;

use DomainException;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\IdentifierMethod;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\Uuid;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 */
#[Aggregate]
final class Customer extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'name', 'email'];

    #[CommandHandler]
    public static function register(RegisterCustomer $command): self
    {
        $registered = self::query()->where('email', $command->email)->first();
        if ($registered !== null) {
            throw new DomainException(sprintf(
                'Customer %s is already registered with email %s, so RegisterCustomer cannot register %s with it: an email belongs to one customer. Use customer %s, register with another email, or run php artisan demo:seed, which registers only the demo customers not registered yet.',
                $registered->id,
                $command->email,
                $command->name,
                $registered->id,
            ));
        }

        return new self([
            'id' => Uuid::v7()->toRfc4122(),
            'name' => $command->name,
            'email' => $command->email,
        ]);
    }

    #[IdentifierMethod('customerId')]
    public function customerId(): string
    {
        return $this->id;
    }
}
