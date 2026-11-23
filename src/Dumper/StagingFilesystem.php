<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Dumper;

use Bakslash\StaticSiteBundle\Exception\DumpingException;
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
    public function __construct(
        private readonly FilesystemOperator $destination,
        ?string $stagingDirectory = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        // The staged pages are not published yet: keep them unreadable to other local users.
        $visibility = new PortableVisibilityConverter(filePublic: 0600, directoryPublic: 0700);

        parent::__construct(
            new LocalFilesystemAdapter(self::createRoot($stagingDirectory ?? sys_get_temp_dir()), $visibility),
            [Config::OPTION_VISIBILITY => Visibility::PRIVATE],
        );
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

    private static function createRoot(string $stagingDirectory): string
    {
        $root = rtrim($stagingDirectory, '/').'/bakslash-static-site-'.bin2hex(random_bytes(16));
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new DumpingException(\sprintf('Cannot create the temporary export directory "%s".', $stagingDirectory));
        }

        return $root;
    }
}
