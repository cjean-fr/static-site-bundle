<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Tests\Integration;

use Bakslash\StaticSiteBundle\Tests\Fixtures\PruneWithoutManifestKernel;
use Bakslash\StaticSiteBundle\Tests\Fixtures\PruneWithoutStagingKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class PruneConfigurationTest extends TestCase
{
    public function testRejectsPruneWithoutStaging(): void
    {
        $kernel = new PruneWithoutStagingKernel('test', true);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"prune" option requires "staging"/');

        $kernel->boot();
    }

    public function testRejectsPruneWithoutManifest(): void
    {
        $kernel = new PruneWithoutManifestKernel('test', true);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/"prune" option requires a manifest/');

        $kernel->boot();
    }
}
