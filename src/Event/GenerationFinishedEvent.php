<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Event;

/**
 * Dispatched once every page has been processed, even when some failed, e.g. to write a
 * sitemap. Not dispatched when the run is aborted.
 */
final class GenerationFinishedEvent
{
    /**
     * @param list<string>     $uris    the URIs of the pages generated during this run; with staging, they reached the storage only when the status is Published
     * @param bool             $partial whether the run was filtered with --route or --path
     * @param GenerationStatus $status  whether the generated pages were published to the storage or discarded with the failed staged run
     */
    public function __construct(
        public readonly array $uris,
        public readonly bool $partial = false,
        public readonly GenerationStatus $status = GenerationStatus::Published,
    ) {
    }

    public function isPublished(): bool
    {
        return GenerationStatus::Published === $this->status;
    }
}
