<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Illuminate\Database\Eloquent\Model;

class Person extends Model
{
    protected $table = 'persons';
    protected $fillable = ['person_id', 'name'];
    protected $primaryKey = 'person_id';
    public $timestamps = false;

    private function __construct(int $personId, string $name)
    {
        $this->person_id = $personId;
        $this->name = $name;
    }

    public static function register(RegisterPerson $command): static
    {
        return new self($command->personId, $command->name);
    }

    public function getName(): string
    {
        return $this->name;
    }
}
