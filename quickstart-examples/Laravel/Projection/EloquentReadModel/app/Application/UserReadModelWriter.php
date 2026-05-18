<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Application;

use App\Models\UserReadModel;
use Ecotone\Messaging\Attribute\InternalHandler;

final class UserReadModelWriter
{
    #[InternalHandler(inputChannelName: 'user_read_model.apply_registered')]
    public function applyRegistered(ApplyUserRegistered $dto): void
    {
        UserReadModel::create([
            'user_id' => $dto->userId,
            'name'    => $dto->name,
            'email'   => $dto->email,
            'active'  => true,
        ]);
    }

    #[InternalHandler(inputChannelName: 'user_read_model.apply_name_changed')]
    public function applyNameChanged(ApplyUserNameChanged $dto): void
    {
        UserReadModel::where('user_id', $dto->userId)->update(['name' => $dto->name]);
    }

    #[InternalHandler(inputChannelName: 'user_read_model.apply_deactivated')]
    public function applyDeactivated(ApplyUserDeactivated $dto): void
    {
        UserReadModel::where('user_id', $dto->userId)->update(['active' => false]);
    }
}
