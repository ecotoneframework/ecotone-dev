<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\SymfonyBundle\DependencyInjection\Compiler\CacheClearer;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CacheClearerTest extends TestCase
{
    public function test_clearing_preserves_compiled_container_together_with_generated_handler_classes(): void
    {
        $cacheDirectory = sys_get_temp_dir() . '/ecotone_cache_clearer_test/' . uniqid('', true);
        mkdir($cacheDirectory . '/handlers', 0777, true);
        file_put_contents($cacheDirectory . '/ecotone_container.php', '<?php');
        file_put_contents($cacheDirectory . '/EcotoneCachedContainer_abc.php', '<?php');
        file_put_contents($cacheDirectory . '/handlers/MessageProcessor__OrderService_place_abc12345.php', '<?php');
        file_put_contents($cacheDirectory . '/Ecotone_Modelling_CommandBus.php', '<?php');

        (new CacheClearer(new ServiceCacheConfiguration($cacheDirectory, true)))->clear('');

        self::assertFileExists($cacheDirectory . '/ecotone_container.php');
        self::assertFileExists($cacheDirectory . '/EcotoneCachedContainer_abc.php');
        self::assertFileExists($cacheDirectory . '/handlers/MessageProcessor__OrderService_place_abc12345.php');
        self::assertFileDoesNotExist($cacheDirectory . '/Ecotone_Modelling_CommandBus.php');
    }
}
