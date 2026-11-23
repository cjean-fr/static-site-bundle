<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Event;

/**
 * What happened to the pages of a finished run.
 */
enum GenerationStatus
{
    /**
     * The generated pages reached the storage: written page by page, or published from the
     * staged export once every page succeeded.
     */
    case Published;

    /**
     * A staged run failed: its prepared pages were discarded and never reached the storage.
     */
    case Discarded;
}
