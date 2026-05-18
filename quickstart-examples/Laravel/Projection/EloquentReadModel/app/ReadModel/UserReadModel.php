<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\ReadModel;

use App\ReadModel\Command\ChangeUserReadModelName;
use App\ReadModel\Command\DeactivateUserReadModel;
use App\ReadModel\Command\RegisterUserReadModel;
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

    #[CommandHandler(RegisterUserReadModel::class)]
    public static function register(RegisterUserReadModel $command): self
    {
        return self::create([
            'user_id' => $command->userId,
            'name' => $command->name,
            'email' => $command->email,
            'active' => true,
        ]);
    }

    #[CommandHandler(ChangeUserReadModelName::class, identifierMapping: ['user_id' => 'payload.userId'])]
    public function changeName(ChangeUserReadModelName $command): void
    {
        $this->name = $command->name;
    }

    #[CommandHandler(DeactivateUserReadModel::class, identifierMapping: ['user_id' => 'payload.userId'])]
    public function deactivate(DeactivateUserReadModel $command): void
    {
        $this->active = false;
    }
}
