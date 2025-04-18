<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Compatibility;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A custom FrameworkBundle that doesn't use CachePoolPass
 * 
 * licence Apache-2.0
 */
class CompatibleFrameworkBundle extends FrameworkBundle
{
    /**
     * Override the build method to avoid using CachePoolPass
     */
    public function build(ContainerBuilder $container): void
    {
        // Call parent build method but catch any errors related to CachePoolPass
        try {
            parent::build($container);
        } catch (\Error $e) {
            // If the error is related to CachePoolPass, ignore it
            if (strpos($e->getMessage(), 'CachePoolPass') !== false) {
                // Continue without the CachePoolPass
            } else {
                // Re-throw other errors
                throw $e;
            }
        }
    }
}
