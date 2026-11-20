<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Dumper;

use Bakslash\StaticSiteBundle\Exception\DumpingException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Stages generated files locally before copying them to the configured storage.
 *
 * @internal
 */
final class StagingFilesystem extends Filesystem
{
    public function __construct(private readonly FilesystemOperator $destination)
    {
        parent::__construct(new LocalFilesystemAdapter(sys_get_temp_dir().'/bakslash-static-site-'.bin2hex(random_bytes(16))));
    }

    public function publish(): void
    {
        try {
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
            }
        } catch (FilesystemException $exception) {
            throw new DumpingException('Cannot publish the generated site. The destination may have been partially updated.', previous: $exception);
        }
    }

    public function discard(?\Throwable $failure = null): void
    {
        try {
            $this->deleteDirectory('');
        } catch (FilesystemException $exception) {
            throw new DumpingException(null === $failure ? 'Cannot remove the temporary export directory.' : $failure->getMessage().' Temporary export cleanup also failed: '.$exception->getMessage(), previous: $failure ?? $exception);
        }
    }
}
