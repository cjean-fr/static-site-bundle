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
     * Describes the last fully published run: which files belong to it, and their hashes.
     */
    public const string MANIFEST_FILE = '_static-site.json';

    private readonly string $root;

    private bool $published = false;

    public function __construct(
        private readonly FilesystemOperator $destination,
        ?string $stagingDirectory = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $prune = false,
    ) {
        // The staged pages are not published yet: keep them unreadable to other local users.
        $visibility = new PortableVisibilityConverter(filePublic: 0600, directoryPublic: 0700);
        $this->root = self::createRoot($stagingDirectory ?? sys_get_temp_dir());

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
    }

    /**
     * @param array<string, mixed> $config
     */
    public function write(string $location, string $contents, array $config = []): void
    {
        $this->guardAgainstPublishedExport();

        parent::write($location, $contents, $config);
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

    public function publish(bool $partial = false): void
    {
        try {
            $files = [];
            foreach ($this->listContents('', true) as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }

                $stream = $this->readStream($entry->path());
                try {
                    $this->destination->writeStream($entry->path(), $stream);
                } finally {
                    fclose($stream);
                }
                $files[$entry->path()] = hash_file('sha256', $this->root.'/'.$entry->path());
            }

            // Last, so a failed transfer leaves the previous manifest untouched; a filtered run
            // only sees part of the site and cannot describe it.
            if (!$partial) {
                if ($this->prune) {
                    $this->pruneObsoleteFiles($files);
                }

                ksort($files);
                $this->destination->write(self::MANIFEST_FILE, json_encode([
                    'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'files' => $files,
                ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
            }
        } catch (FilesystemException $exception) {
            throw new DumpingException('Cannot publish the generated site. The destination may have been partially updated.', previous: $exception);
        }

        $this->published = true;
    }

    /**
     * Removes the files the previous published run generated and this one no longer does, so
     * the storage reflects the new run. Only the files listed by the previous manifest are
     * candidates: anything else on the storage is left alone, and without a readable manifest
     * nothing is pruned.
     *
     * @param array<string, string|false> $files the files of the run being published, with their hashes
     */
    private function pruneObsoleteFiles(array $files): void
    {
        try {
            $manifest = json_decode($this->destination->read(self::MANIFEST_FILE), true);
        } catch (FilesystemException) {
            // No readable manifest: the previous run cannot be described, so nothing is pruned.
            return;
        }

        if (!\is_array($manifest) || !\is_array($manifest['files'] ?? null)) {
            return;
        }

        foreach (array_keys($manifest['files']) as $path) {
            if (!\is_string($path) || isset($files[$path]) || self::MANIFEST_FILE === $path) {
                continue;
            }

            try {
                $this->destination->delete($path);
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
