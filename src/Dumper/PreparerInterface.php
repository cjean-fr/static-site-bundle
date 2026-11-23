<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Dumper;

/**
 * Prepares the export's additional files (the headers file, a sitemap, ...) alongside the
 * pages, under the generator's control. Tag an implementation with
 * "bakslash_static_site.preparer"; with "staging", throwing from prepare() aborts the run
 * before anything is published.
 */
interface PreparerInterface
{
    /**
     * Resets the per-run state before any page is resolved.
     */
    public function start(): void;

    /**
     * Prepares the additional files once every page has been processed, before publication.
     * Throwing aborts the run: with staging, nothing is published.
     *
     * @param bool $partial    whether the run was filtered with --route or --path
     * @param bool $successful whether every page was generated
     */
    public function prepare(bool $partial, bool $successful): void;
}
