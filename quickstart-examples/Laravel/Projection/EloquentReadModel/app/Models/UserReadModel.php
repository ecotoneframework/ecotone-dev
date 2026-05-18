<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserReadModel extends Model
{
    public $table = 'user_list_eloquent';

    public $primaryKey = 'user_id';

    public $incrementing = false;

    public $keyType = 'string';

    public $timestamps = false;

    public $fillable = ['user_id', 'name', 'email', 'active'];
}
