<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Ecotone\Modelling\Attribute\QueryHandler;
use Illuminate\Support\Facades\DB;

final readonly class PersonQueryService
{
    #[QueryHandler('person.getAllRegistered')]
    public function getAllRegisteredPersonIds(): array
    {
        return DB::connection()->getPdo()->query(<<<SQL
            SELECT person_id FROM persons;    
SQL)->fetchAll(\PDO::FETCH_COLUMN);
    }
}