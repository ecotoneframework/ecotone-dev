<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\ReadModel;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifierMethod;
use Ecotone\Modelling\Attribute\CommandHandler;
use Illuminate\Database\Eloquent\Model;

#[Aggregate]
final class UserReadModel extends Model
{
    public $table = 'user_list_eloquent';

    public $primaryKey = 'user_id';

    public $incrementing = false;

    public $keyType = 'string';

    public $timestamps = false;

    public $fillable = ['user_id', 'name', 'email', 'active'];

    #[AggregateIdentifierMethod('user_id')]
    public function getUserId(): string
    {
        return $this->user_id;
    }

    #[CommandHandler('RegisterUserReadModel')]
    public static function register(array $data): self
    {
        return self::create($data);
    }

    #[CommandHandler('ChangeUserReadModelName', identifierMapping: ['user_id' => "payload['user_id']"])]
    public function changeName(array $data): void
    {
        $this->name = $data['name'];
    }

    #[CommandHandler('DeactivateUserReadModel', identifierMapping: ['user_id' => "payload['user_id']"])]
    public function deactivate(array $data): void
    {
        $this->active = false;
    }
}
