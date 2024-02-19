<?php

namespace Test\Ecotone\Laravel;

use DateTime;
use Ecotone\Laravel\EloquentRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Orchestra\Testbench\TestCase;

/**
 * @internal
 */
class EloquentRepositoryTest extends TestCase
{
    public function test_it_does_not_support_non_models()
    {
        $repository = new EloquentRepository();

        $this->assertFalse($repository->canHandle(DateTime::class));
    }

    public function test_it_does_support_models()
    {
        $repository = new EloquentRepository();

        $this->assertTrue($repository->canHandle(User::class));
    }
}
