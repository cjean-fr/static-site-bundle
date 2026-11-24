<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Enables "prune" without "staging", which the bundle configuration must reject.
 */
final class PruneWithoutStagingKernel extends TestKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->extension('bakslash_static_site', ['prune' => true]);
    }
}
