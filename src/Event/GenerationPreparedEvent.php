<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Event;

/**
 * Prepares the bundle's additional output files before publication.
 *
 * @internal
 */
final readonly class GenerationPreparedEvent
{
    public function __construct(
        public bool $partial,
        public bool $successful,
    ) {
    }
}
