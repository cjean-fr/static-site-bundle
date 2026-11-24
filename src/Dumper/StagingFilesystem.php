<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Dumper;

use Bakslash\StaticSiteBundle\Exception\DumpingException;
use Bakslash\StaticSiteBundle\Exception\LogicException;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use Psr\Log\LoggerInterface;

/**
 * Stages generated files locally before copying them to the configured storage.
 *
 * @internal
 */
final class StagingFilesystem extends Filesystem
{
    /**
     * Default path of the manifest describing the last fully published run: which files
     * belong to it, and their hashes. Kept under the underscore prefix reserved for the
     * bundle's control files (like "_headers"), away from any route of the site.
     */
    public const string DEFAULT_MANIFEST_PATH = '_static-site/manifest.json';

    private readonly string $root;

    private bool $published = false;

    private string $runId;

    /** @var array<string, string> the SHA-256 of each staged file, computed as it is written */
    private array $hashes = [];

    public function __construct(
        private readonly FilesystemOperator $destination,
        ?string $stagingDirectory = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $prune = false,
        private readonly ?string $manifestPath = self::DEFAULT_MANIFEST_PATH,
    ) {
        // The staged pages are not published yet: keep them unreadable to other local users.
        $visibility = new PortableVisibilityConverter(filePublic: 0600, directoryPublic: 0700);
        $this->root = self::createRoot($stagingDirectory ?? sys_get_temp_dir());
        $this->runId = self::newRunId();

        parent::__construct(
            new LocalFilesystemAdapter($this->root, $visibility),
            [Config::OPTION_VISIBILITY => Visibility::PRIVATE],
        );
    }

    /**
     * Marks the beginning of a run: writing is allowed again, until its publication.
     */
    public function start(): void
    {
        $this->published = false;
        $this->runId = self::newRunId();
        $this->hashes = [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->guardAgainstPublishedExport();

        parent::write($location, $contents, $config);

        // Hashed now, while the content is at hand: the publication compares it against the
        // previous manifest without re-reading the staged file.
        $this->hashes[$location] = hash('sha256', $contents);
    }

    public function delete(string $location): void
    {
        $this->guardAgainstPublishedExport();

        parent::delete($location);

        unset($this->hashes[$location]);
    }

    /**
     * @param resource             $contents
     * @param array<string, mixed> $config
     */
    public function writeStream(string $location, $contents, array $config = []): void
    {
        $this->guardAgainstPublishedExport();

        parent::writeStream($location, $contents, $config);
    }

    /**
     * @return list<string> the paths the pruning removed from the storage
     */
    public function publish(bool $partial = false): array
    {
        $pruned = [];

        try {
            $previous = $this->readManifest();
            $files = [];
            foreach ($this->listContents('', true) as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }

                $files[$entry->path()] = $this->hashes[$entry->path()] ?? hash_file('sha256', $this->root.'/'.$entry->path());

                // Unchanged since the last published run: already on the storage.
                if (null !== $previous && ($previous['files'][$entry->path()] ?? null) === $files[$entry->path()]) {
                    continue;
                }

                $stream = $this->readStream($entry->path());
                try {
                    $this->destination->writeStream($entry->path(), $stream);
                } finally {
                    fclose($stream);
                }
            }

            // Last, so a failed transfer leaves the previous manifest untouched; a filtered run
            // only sees part of the site and cannot describe it.
            if (!$partial) {
                if ($this->prune && null !== $previous) {
                    $current = $this->readManifest();
                    if (null !== $current && $current['runId'] === $previous['runId']) {
                        $pruned = $this->pruneObsoleteFiles($previous, $files);
                    } else {
                        // The manifest moved during this publication: another run published
                        // meanwhile. Refuse to prune rather than delete that run's files.
                        $this->logger?->warning('Another run published to the storage during this one: skipping the pruning of obsolete files.');
                    }
                }

                if (null !== $this->manifestPath) {
                    ksort($files);
                    // Compact: nobody reads this file, and thousands of pages make indentation costly.
                    $this->destination->write($this->manifestPath, json_encode([
                        'version' => 1,
                        'runId' => $this->runId,
                        'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                        'files' => $files,
                    ], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
                }
            }
        } catch (FilesystemException $exception) {
            throw new DumpingException('Cannot publish the generated site. The destination may have been partially updated.', previous: $exception);
        }

        $this->published = true;

        return $pruned;
    }

    /**
     * The files a full publication of the staged export would remove, or null when pruning
     * is disabled: the pruning preview of a dry run.
     *
     * @return ?list<string>
     */
    public function wouldPrune(): ?array
    {
        if (!$this->prune || null === $this->manifestPath) {
            return null;
        }

        $previous = $this->readManifest();
        if (null === $previous) {
            return [];
        }

        $current = [];
        foreach ($this->listContents('', true) as $entry) {
            if ($entry->isFile()) {
                $current[$entry->path()] = true;
            }
        }

        $prunable = [];
        foreach (array_keys($previous['files']) as $path) {
            if (!isset($current[$path]) && $path !== $this->manifestPath) {
                $prunable[] = $path;
            }
        }
        sort($prunable);

        return $prunable;
    }

    /**
     * Removes the files the previous published run generated and this one no longer does, so
     * the storage reflects the new run. Only the files listed by the previous manifest are
     * candidates: anything else on the storage is left alone, and without a readable manifest
     * nothing is pruned.
     *
     * @param array{runId: ?string, files: array<string, string>}|null $previous the manifest of the previous published run
     * @param array<string, string|false>                              $files    the files of the run being published, with their hashes
     *
     * @return list<string> the removed paths
     */
    private function pruneObsoleteFiles(?array $previous, array $files): array
    {
        if (null === $previous) {
            // No readable manifest: the previous run cannot be described, so nothing is pruned.
            return [];
        }

        $pruned = [];
        foreach (array_keys($previous['files']) as $path) {
            if (isset($files[$path]) || $path === $this->manifestPath) {
                continue;
            }

            try {
                $this->destination->delete($path);
                $pruned[] = $path;
            } catch (FilesystemException $exception) {
                // The publication itself succeeded: only an obsolete file is left behind,
                // report it instead of failing the command.
                $this->logger?->warning('Cannot remove the obsolete file "{path}" from the storage: {message}', [
                    'path' => $path,
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
            }
        }

        if ([] !== $pruned) {
            $this->logger?->info('Removed {count} obsolete file(s) from the storage: {paths}', [
                'count' => \count($pruned),
                'paths' => implode(', ', $pruned),
            ]);
        }

        return $pruned;
    }

    /**
     * Reads the manifest of the previous published run, or null when there is no readable,
     * well-formed one.
     *
     * @return array{runId: ?string, files: array<string, string>}|null
     */
    private function readManifest(): ?array
    {
        if (null === $this->manifestPath) {
            return null;
        }

        try {
            $manifest = json_decode($this->destination->read($this->manifestPath), true);
        } catch (FilesystemException) {
            return null;
        }

        if (!\is_array($manifest) || !\is_array($manifest['files'] ?? null)) {
            return null;
        }

        $files = [];
        foreach ($manifest['files'] as $path => $hash) {
            if (\is_string($path) && \is_string($hash)) {
                $files[$path] = $hash;
            }
        }

        return [
            'runId' => \is_string($manifest['runId'] ?? null) ? $manifest['runId'] : null,
            'files' => $files,
        ];
    }

    private static function newRunId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function discard(?\Throwable $failure = null): void
    {
        try {
            $this->deleteDirectory('');
        } catch (FilesystemException $exception) {
            // The run itself succeeded: only a temporary directory is left behind, report it
            // instead of failing the command.
            if (null === $failure) {
                $this->logger?->warning('Cannot remove the temporary export directory: {message}', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);

                return;
            }

            throw new DumpingException($failure->getMessage().' Temporary export cleanup also failed: '.$exception->getMessage(), previous: $failure);
        }
    }

    private function guardAgainstPublishedExport(): void
    {
        if ($this->published) {
            // The staged files are deleted once the run unwinds: a file written now would
            // silently never reach the storage.
            throw new LogicException('The export of this run was already published; a file written to the staging area now would be deleted without ever reaching the storage. Write it to the "bakslash_static_site.filesystem" destination directly instead.');
        }
    }

    private static function createRoot(string $stagingDirectory): string
    {
        $root = rtrim($stagingDirectory, '/').'/bakslash-static-site-'.bin2hex(random_bytes(16));
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new DumpingException(\sprintf('Cannot create the temporary export directory "%s".', $stagingDirectory));
        }

        return $root;
    }
}
