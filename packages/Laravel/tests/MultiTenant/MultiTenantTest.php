<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\MultiTenant;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Laravel\EcotoneProvider;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\LicenceTesting;
use Fiber;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Ecotone\Laravel\RestoresLaravelHandlers;

require_once __DIR__ . '/boostrap.php';

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class MultiTenantTest extends TestCase
{
    use RestoresLaravelHandlers;

    private Application $app;
    private QueryBus $queryBus;
    private CommandBus $commandBus;

    public function setUp(): void
    {
        putenv('LARAVEL_LICENCE_KEY=' . LicenceTesting::VALID_LICENCE);
        $app = require __DIR__ . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        runMigrationForTenants(DB::connection('tenant_a_connection'), DB::connection('tenant_b_connection'));
        $this->app = $app;
        EcotoneCacheClear::clearEcotoneCacheDirectories(EcotoneProvider::getCacheDirectoryPath());
        $this->queryBus = $app->get(QueryBus::class);
        $this->commandBus = $app->get(CommandBus::class);
    }

    public function test_optimize_clear_triggers_ecotone_cache_clear_via_event(): void
    {
        $laravelCacheDirectory = storage_path('framework/cache/data/ecotone');
        $liteCacheDirectory = storage_path('framework/cache/data/ecotone');

        // Create cache directories and test files
        if (! File::exists($laravelCacheDirectory)) {
            File::makeDirectory($laravelCacheDirectory, 0755, true);
        }
        File::put($laravelCacheDirectory . '/test_cache_file', 'test content');

        if (! File::exists($liteCacheDirectory)) {
            File::makeDirectory($liteCacheDirectory, 0755, true);
        }
        File::put($liteCacheDirectory . '/test_lite_cache_file', 'lite test content');

        $this->assertTrue(File::exists($laravelCacheDirectory . '/test_cache_file'));
        $this->assertTrue(File::exists($liteCacheDirectory . '/test_lite_cache_file'));

        // Run optimize:clear command - this should trigger the CommandFinished event
        Artisan::call('optimize:clear');

        // Verify both Ecotone cache files were removed via the event listener
        $this->assertFalse(File::exists($laravelCacheDirectory . '/test_cache_file'));
        $this->assertFalse(File::exists($liteCacheDirectory . '/test_lite_cache_file'));
    }

    public function tearDown(): void
    {
        putenv('LARAVEL_LICENCE_KEY');
        DB::connection('tenant_a_connection')->disconnect();
        DB::connection('tenant_b_connection')->disconnect();
        restore_exception_handler();
        restore_error_handler();
    }

    public function test_run_message_handlers_for_multi_tenant_connection(): void
    {
        $this->commandBus->send(new RegisterCustomer(1, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_a']);
        $this->commandBus->send(new RegisterCustomer(2, 'John Doe'), metadata: ['tenant' => 'tenant_b']);

        $this->assertSame(
            [1, 2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            [2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_tenant_activated_while_a_suspended_execution_has_another_tenant_active_is_refused(): void
    {
        $suspended = new Fiber(fn () => $this->commandBus->sendWithRouting('customer.register_after_being_suspended', new RegisterCustomer(1, 'John Doe'), metadata: ['tenant' => 'tenant_a']));
        $suspended->start();

        try {
            $this->commandBus->send(new RegisterCustomer(2, 'Jane Doe'), metadata: ['tenant' => 'tenant_b']);
            $this->fail();
        } catch (ConfigurationException $exception) {
            $this->assertSame(
                'A tenant connection is activated while another message, handled at once in this process on a coroutine or a fiber, has its tenant active. '
                . "Laravel's default database connection is one setting for the whole process, so the Eloquent models and DB facade calls of both messages would go to the tenant activated last. "
                . 'Handle one message at a time: run consumers without the `coroutines` option of ecotone:run and PollingMetadata::withCoroutines(), set `enable_coroutine` to false on a Swoole or OpenSwoole server, and do not suspend a fiber while it handles a message.',
                $exception->getMessage(),
            );
        } finally {
            $suspended->resume();
        }

        $this->assertSame([1], $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a']));
        $this->assertSame([], $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b']));
    }

    public function test_using_dbal_based_business_interfaces(): void
    {
        $this->commandBus->sendWithRouting(
            'customer.register_with_business_interface',
            new RegisterCustomer(1, 'John Doe'),
            metadata: ['tenant' => 'tenant_a']
        );

        $this->assertSame(
            [1],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            [],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_sending_events_using_laravel_db_queue(): void
    {
        $this->commandBus->sendWithRouting(
            'customer.register_with_event',
            new RegisterCustomer(1, 'John Doe'),
            metadata: ['tenant' => 'tenant_a']
        );
        $this->commandBus->sendWithRouting(
            'customer.register_with_event',
            new RegisterCustomer(2, 'John Doe'),
            metadata: ['tenant' => 'tenant_a']
        );

        /** Consume Messages for Tenant A */
        Artisan::call('ecotone:run', ['consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);

        /** This is not yet consumed */
        $this->commandBus->sendWithRouting(
            'customer.register_with_event',
            new RegisterCustomer(2, 'John Doe'),
            metadata: ['tenant' => 'tenant_b']
        );

        $this->assertSame(
            2,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            0,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_b'])
        );

        /** Consume Messages for Tenant B */
        Artisan::call('ecotone:run', ['consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);

        $this->assertSame(
            2,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
        );

        $this->assertSame(
            1,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_b'])
        );
    }

    public function test_transactions_rollbacks_model_changes_and_published_events(): void
    {
        /** This one will be rolled back */
        try {
            $this->commandBus->sendWithRouting(
                'customer.register_with_event',
                new RegisterCustomer(1, 'John Doe'),
                metadata: ['tenant' => 'tenant_a', 'shouldThrowException' => true]
            );
        } catch (RuntimeException $exception) {
        }

        $this->commandBus->sendWithRouting(
            'customer.register_with_event',
            new RegisterCustomer(2, 'John Doe'),
            metadata: ['tenant' => 'tenant_a']
        );

        /** Consume Messages for Tenant A */
        Artisan::call('ecotone:run', ['consumerName' => 'notifications', '--stopOnFailure' => true, '--executionTimeLimit' => 1000]);

        $this->assertSame(
            1,
            $this->queryBus->sendWithRouting('getNotificationsCount', metadata: ['tenant' => 'tenant_a'])
        );
        $this->assertSame(
            [2],
            $this->queryBus->sendWithRouting('customer.getAllRegistered', metadata: ['tenant' => 'tenant_a'])
        );
    }
}
