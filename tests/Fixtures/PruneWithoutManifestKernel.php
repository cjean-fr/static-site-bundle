<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Enables "prune" with the manifest disabled, which the bundle configuration must reject.
 */
final class PruneWithoutManifestKernel extends TestKernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->extension('bakslash_static_site', ['staging' => true, 'prune' => true, 'manifest' => null]);
    }
}
