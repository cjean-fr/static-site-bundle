<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Tests;

use Bakslash\StaticSiteBundle\Dumper\FlysystemDumper;
use Bakslash\StaticSiteBundle\Dumper\PreparerInterface;
use Bakslash\StaticSiteBundle\Dumper\StagingFilesystem;
use Bakslash\StaticSiteBundle\Event\GenerationFinishedEvent;
use Bakslash\StaticSiteBundle\Event\GenerationStatus;
use Bakslash\StaticSiteBundle\Exception\DumpingException;
use Bakslash\StaticSiteBundle\Exception\LogicException;
use Bakslash\StaticSiteBundle\Exception\RenderingException;
use Bakslash\StaticSiteBundle\Generator\StaticSiteGenerator;
use Bakslash\StaticSiteBundle\Renderer\RenderedPage;
use Bakslash\StaticSiteBundle\Renderer\RendererInterface;
use Bakslash\StaticSiteBundle\UriProvider\GenerationFilter;
use Bakslash\StaticSiteBundle\UriProvider\UriGenerationFailure;
use Bakslash\StaticSiteBundle\UriProvider\UriProviderInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Service\ResetInterface;

final class StagingFilesystemTest extends TestCase
{
    private Filesystem $destination;
    private StagingFilesystem $staging;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->destination = new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir().'/bakslash-staging-test-'.bin2hex(random_bytes(8))));
        $this->staging = new StagingFilesystem($this->destination);
        $this->dispatcher = new EventDispatcher();
        $this->destination->write('a.html', 'previous');
        $this->destination->write('untouched.html', 'keep');
    }

    protected function tearDown(): void
    {
        $this->staging->discard();
        $this->destination->deleteDirectory('');
    }

    public function testPublishesPreparedFilesBeforeTheFinishedEvent(): void
    {
        $published = null;
        $preparer = new class($this->destination, $this->staging) implements PreparerInterface {
            public ?string $destinationBeforePublication = null;

            public function __construct(
                private readonly FilesystemOperator $destination,
                private readonly StagingFilesystem $staging,
            ) {
            }

            public function start(): void
            {
            }

            public function prepare(bool $partial, bool $successful): void
            {
                $this->destinationBeforePublication = $this->destination->read('a.html');
                $this->staging->write('sitemap.xml', '<urlset/>');
            }
        };
        $this->dispatcher->addListener(GenerationFinishedEvent::class, function (GenerationFinishedEvent $event) use (&$published): void {
            $published = $event;
            $this->assertSame('new', $this->destination->read('a.html'));
            $this->assertSame('<urlset/>', $this->destination->read('sitemap.xml'));
        });

        $run = $this->generator(['/a', '/nested/b'], preparer: $preparer)->generate();
        $this->assertInstanceOf(\Generator::class, $run);
        $run->rewind();
        $this->assertSame('previous', $this->destination->read('a.html'));
        iterator_to_array($run);

        // The preparation ran before the publication: the destination still held the previous export.
        $this->assertSame('previous', $preparer->destinationBeforePublication);

        $this->assertEquals(new GenerationFinishedEvent(['/a', '/nested/b']), $published);
        $this->assertSame('new', $this->destination->read('nested/b.html'));
        $this->assertSame('keep', $this->destination->read('untouched.html'));
        $this->assertSame([], $this->staging->listContents('', true)->toArray());
    }

    public function testFailedPageDiscardsTheWholeRun(): void
    {
        $finished = null;
        $this->dispatcher->addListener(GenerationFinishedEvent::class, static function (GenerationFinishedEvent $event) use (&$finished): void {
            $finished = $event;
        });
        $results = iterator_to_array($this->generator(['/a', '/broken'])->generate());

        // The prepared URIs are still reported, marked as discarded.
        $this->assertEquals(new GenerationFinishedEvent(['/a'], status: GenerationStatus::Discarded), $finished);

        $this->assertInstanceOf(RenderingException::class, $results[1]->error);
        $this->assertSame('previous', $this->destination->read('a.html'));
        $this->assertSame([], $this->staging->listContents('', true)->toArray());
    }

    public function testEmptyRunLeavesTheDestinationUntouched(): void
    {
        iterator_to_array($this->generator([])->generate());

        $this->assertSame('previous', $this->destination->read('a.html'));
        $this->assertSame('keep', $this->destination->read('untouched.html'));
        $this->assertSame([], $this->staging->listContents('', true)->toArray());
    }

    public function testUnexpectedFailureDiscardsStagedFiles(): void
    {
        $this->dispatcher->addListener(\Bakslash\StaticSiteBundle\Event\PageRenderedEvent::class, static function (): void {
            throw new \LogicException('listener failed');
        });
        try {
            iterator_to_array($this->generator(['/a'])->generate());
            $this->fail('The listener should abort the run.');
        } catch (\LogicException) {
        }

        $this->assertSame('previous', $this->destination->read('a.html'));
        $this->assertSame([], $this->staging->listContents('', true)->toArray());
    }

    public function testUriGenerationFailureAlsoDiscardsSuccessfulPages(): void
    {
        iterator_to_array($this->generator(['/a', new UriGenerationFailure('route', new \RuntimeException('bad parameters'))])->generate());

        $this->assertSame('previous', $this->destination->read('a.html'));
    }

    public function testPartialRunKeepsFilesOutsideTheSelection(): void
    {
        $published = null;
        $this->dispatcher->addListener(GenerationFinishedEvent::class, static function (GenerationFinishedEvent $event) use (&$published): void {
            $published = $event;
        });
        iterator_to_array($this->generator(['/a'])->generate(new GenerationFilter(paths: ['/a'])));

        $this->assertSame('new', $this->destination->read('a.html'));
        $this->assertSame('keep', $this->destination->read('untouched.html'));
        $this->assertEquals(new GenerationFinishedEvent(['/a'], partial: true), $published);
    }

    public function testFailedPreparationDoesNotTouchTheDestination(): void
    {
        $preparer = new class implements PreparerInterface {
            public function start(): void
            {
            }

            public function prepare(bool $partial, bool $successful): void
            {
                throw new DumpingException('cannot generate sitemap');
            }
        };

        try {
            iterator_to_array($this->generator(['/a'], preparer: $preparer)->generate());
            $this->fail('The preparation should abort the run.');
        } catch (DumpingException) {
        }

        $this->assertSame('previous', $this->destination->read('a.html'));
        $this->assertSame([], $this->staging->listContents('', true)->toArray());
    }

    public function testInterruptedIterationDiscardsStagedFiles(): void
    {
        $run = $this->generator(['/a', '/b'])->generate();
        $this->assertInstanceOf(\Generator::class, $run);
        $run->rewind();
        unset($run);

        $this->assertSame('previous', $this->destination->read('a.html'));
        $this->assertSame([], $this->staging->listContents('', true)->toArray());
    }

    public function testRepeatedRunsDoNotPublishFilesFromAFailedRun(): void
    {
        iterator_to_array($this->generator(['/abandoned', '/broken'])->generate());
        iterator_to_array($this->generator(['/a'])->generate());

        $this->assertFalse($this->destination->fileExists('abandoned.html'));
        $this->assertSame('new', $this->destination->read('a.html'));
    }

    public function testPublishesStreamsReadingOnlyTheManifest(): void
    {
        $destination = $this->createMock(FilesystemOperator::class);
        // The manifest is the only file ever read from the destination.
        $destination->expects($this->once())->method('read')->with(StagingFilesystem::DEFAULT_MANIFEST_PATH)->willReturn('{invalid');
        $destination->expects($this->never())->method('readStream');
        $destination->expects($this->never())->method('fileExists');
        $destination->expects($this->never())->method('listContents');
        $destination->expects($this->never())->method('delete');
        $destination->expects($this->once())->method('writeStream')->willReturnCallback(function (string $path, $stream): void {
            $this->assertSame('page.html', $path);
            $this->assertIsResource($stream);
            $this->assertSame('content', stream_get_contents($stream));
        });
        $staging = new StagingFilesystem($destination);
        try {
            $staging->write('page.html', 'content');
            $staging->publish();
        } finally {
            $staging->discard();
        }
    }

    public function testOnlyChangedFilesAreUploadedAgain(): void
    {
        iterator_to_array($this->generator(['/a', '/nested/b'])->generate());
        $manifest = $this->destination->read(StagingFilesystem::DEFAULT_MANIFEST_PATH);

        $destination = $this->createMock(FilesystemOperator::class);
        $destination->expects($this->once())->method('read')->with(StagingFilesystem::DEFAULT_MANIFEST_PATH)->willReturn($manifest);
        // a.html is unchanged: only nested/b.html, whose content changed, is uploaded again.
        $destination->expects($this->once())->method('writeStream')->with('nested/b.html');
        $destination->expects($this->once())->method('write')->with(StagingFilesystem::DEFAULT_MANIFEST_PATH);
        $staging = new StagingFilesystem($destination);

        $provider = $this->createStub(UriProviderInterface::class);
        $provider->method('provide')->willReturn(['/a', '/nested/b']);
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('render')->willReturnCallback(static fn (string $uri): RenderedPage => new RenderedPage('/a' === $uri ? 'new' : 'changed', 'html'));
        $generator = new StaticSiteGenerator($provider, $renderer, new FlysystemDumper($staging), $this->dispatcher, $this->createStub(ResetInterface::class), $staging);

        try {
            iterator_to_array($generator->generate());
        } finally {
            $staging->discard();
        }
    }

    public function testTransferFailureClosesTheStreamAndReportsPartialPublication(): void
    {
        $stream = null;
        $destination = $this->createStub(FilesystemOperator::class);
        $destination->method('writeStream')->willReturnCallback(static function (string $path, $input) use (&$stream): void {
            $stream = $input;
            throw UnableToWriteFile::atLocation($path);
        });
        $staging = new StagingFilesystem($destination);
        try {
            $staging->write('page.html', 'content');
            $staging->publish();
            $this->fail('The transfer should fail.');
        } catch (DumpingException $exception) {
            $this->assertStringContainsString('partially updated', $exception->getMessage());
            $this->assertInstanceOf(UnableToWriteFile::class, $exception->getPrevious());
            $this->assertFalse(\is_resource($stream));
        } finally {
            $staging->discard();
        }
    }

    public function testInterruptedRunDoesNotLeakHeadersIntoTheNextRun(): void
    {
        $dumper = new \Bakslash\StaticSiteBundle\Dumper\HeadersFileDumper(new FlysystemDumper($this->staging), $this->staging, '_headers', previousExport: $this->destination);
        $provider = $this->createStub(UriProviderInterface::class);
        $provider->method('provide')->willReturnOnConsecutiveCalls(['/abandoned', '/abort'], ['/a']);
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('render')->willReturnCallback(static function (string $uri): RenderedPage {
            return match ($uri) {
                '/abort' => throw new \LogicException('interrupted'),
                '/abandoned' => new RenderedPage('discarded', 'html', ['x-frame-options' => ['DENY']]),
                default => new RenderedPage('new', 'html'),
            };
        });
        $generator = new StaticSiteGenerator($provider, $renderer, $dumper, $this->dispatcher, $this->createStub(ResetInterface::class), $this->staging, [$dumper]);

        try {
            iterator_to_array($generator->generate());
            $this->fail('The first run should abort.');
        } catch (\LogicException) {
        }
        iterator_to_array($generator->generate());

        $this->assertFalse($this->destination->fileExists('abandoned.html'));
        $this->assertStringNotContainsString('DENY', $this->destination->read('_headers'));
    }

    public function testCleanupFailureKeepsThePrimaryError(): void
    {
        [$staging, $restore] = $this->stagingWithAnUndeletableRoot();
        $failure = new DumpingException('Publication failed; destination may be partially updated.');

        try {
            $staging->discard($failure);
            $this->fail('Cleanup should fail.');
        } catch (DumpingException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
            $this->assertStringStartsWith('Publication failed; destination may be partially updated.', $exception->getMessage());
            $this->assertStringContainsString('cleanup also failed', $exception->getMessage());
        } finally {
            $restore();
        }
    }

    public function testCleanupFailureAfterASuccessfulRunIsOnlyLogged(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Cannot remove the temporary export directory: {message}');
        [$staging, $restore] = $this->stagingWithAnUndeletableRoot($logger);

        try {
            $staging->discard();
        } finally {
            $restore();
        }
    }

    public function testStagedFilesArePrivateAndTheStagingDirectoryIsConfigurable(): void
    {
        $parent = sys_get_temp_dir().'/bakslash-staging-parent-'.bin2hex(random_bytes(8));
        mkdir($parent, 0700, true);
        $staging = new StagingFilesystem($this->destination, $parent);

        try {
            $staging->write('nested/page.html', 'content');

            $roots = glob($parent.'/bakslash-static-site-*') ?: [];
            $this->assertCount(1, $roots);
            clearstatcache();
            $this->assertSame(0700, fileperms($roots[0]) & 0777);
            $this->assertSame(0700, fileperms($roots[0].'/nested') & 0777);
            $this->assertSame(0600, fileperms($roots[0].'/nested/page.html') & 0777);
        } finally {
            $staging->discard();
            rmdir($parent);
        }
    }

    public function testPublishFailureAbortsTheRunWithoutDispatchingTheFinishedEvent(): void
    {
        $destination = $this->createStub(FilesystemOperator::class);
        $destination->method('writeStream')->willThrowException(UnableToWriteFile::atLocation('a.html'));
        $staging = new StagingFilesystem($destination);
        $finished = false;
        $this->dispatcher->addListener(GenerationFinishedEvent::class, static function () use (&$finished): void {
            $finished = true;
        });

        $provider = $this->createStub(UriProviderInterface::class);
        $provider->method('provide')->willReturn(['/a']);
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('render')->willReturn(new RenderedPage('new', 'html'));
        $generator = new StaticSiteGenerator($provider, $renderer, new FlysystemDumper($staging), $this->dispatcher, $this->createStub(ResetInterface::class), $staging);

        try {
            iterator_to_array($generator->generate());
            $this->fail('The publication should fail.');
        } catch (DumpingException $exception) {
            $this->assertStringContainsString('partially updated', $exception->getMessage());
        }

        $this->assertFalse($finished);
        $this->assertSame([], $staging->listContents('', true)->toArray());
    }

    /**
     * Stages in a directory whose parent denies removal, so discard() fails without touching the
     * staged files. The returned closure restores the permissions.
     *
     * @return array{StagingFilesystem, callable(): void}
     */
    private function stagingWithAnUndeletableRoot(?\Psr\Log\LoggerInterface $logger = null): array
    {
        $parent = sys_get_temp_dir().'/bakslash-staging-parent-'.bin2hex(random_bytes(8));
        mkdir($parent, 0700, true);
        $staging = new StagingFilesystem($this->destination, $parent, $logger);
        $staging->write('page.html', 'content');
        chmod($parent, 0500);

        return [$staging, static function () use ($parent, $staging): void {
            chmod($parent, 0700);
            $staging->discard();
            rmdir($parent);
        }];
    }

    public function testFailuresCanStillPublishTheSuccessfulPages(): void
    {
        $provider = $this->createStub(UriProviderInterface::class);
        $provider->method('provide')->willReturn(['/a', '/broken']);
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('render')->willReturnCallback(static function (string $uri): RenderedPage {
            if ('/broken' === $uri) {
                throw new RenderingException('failed');
            }

            return new RenderedPage('new', 'html');
        });
        $published = null;
        $this->dispatcher->addListener(GenerationFinishedEvent::class, static function (GenerationFinishedEvent $event) use (&$published): void {
            $published = $event;
        });
        $generator = new StaticSiteGenerator($provider, $renderer, new FlysystemDumper($this->staging), $this->dispatcher, $this->createStub(ResetInterface::class), $this->staging, publishesFailures: true);

        iterator_to_array($generator->generate());

        $this->assertSame('new', $this->destination->read('a.html'));
        $this->assertEquals(new GenerationFinishedEvent(['/a']), $published);
        // A run with failures cannot describe the whole site: no manifest.
        $this->assertFalse($this->destination->fileExists(StagingFilesystem::DEFAULT_MANIFEST_PATH));
    }

    public function testPublishesAManifestDescribingTheRunAfterItsFiles(): void
    {
        iterator_to_array($this->generator(['/a', '/nested/b'])->generate());

        $raw = $this->destination->read(StagingFilesystem::DEFAULT_MANIFEST_PATH);
        // Compact JSON: nobody reads this file, and thousands of pages make indentation costly.
        $this->assertStringStartsWith('{"version":1,', $raw);
        /** @var array{version: int, runId: string, generatedAt: string, files: array<string, string>} $manifest */
        $manifest = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(1, $manifest['version']);
        $this->assertNotSame('', $manifest['runId']);
        $this->assertArrayHasKey('generatedAt', $manifest);
        $this->assertSame(
            ['a.html' => hash('sha256', 'new'), 'nested/b.html' => hash('sha256', 'new')],
            $manifest['files'],
        );
    }

    public function testTheManifestCanMoveOrBeDisabled(): void
    {
        $staging = new StagingFilesystem($this->destination, manifestPath: 'meta/build.json');
        iterator_to_array($this->generator(['/a'], staging: $staging)->generate());
        $this->assertTrue($this->destination->fileExists('meta/build.json'));
        $this->assertFalse($this->destination->fileExists(StagingFilesystem::DEFAULT_MANIFEST_PATH));

        $this->destination->delete('meta/build.json');
        $staging = new StagingFilesystem($this->destination, manifestPath: null);
        iterator_to_array($this->generator(['/a'], staging: $staging)->generate());
        $this->assertFalse($this->destination->fileExists(StagingFilesystem::DEFAULT_MANIFEST_PATH));
        $this->assertFalse($this->destination->fileExists('meta/build.json'));
    }

    public function testFilteredOrFailedRunsLeaveNoManifest(): void
    {
        iterator_to_array($this->generator(['/a'])->generate(new GenerationFilter(paths: ['/a'])));
        $this->assertFalse($this->destination->fileExists(StagingFilesystem::DEFAULT_MANIFEST_PATH));

        iterator_to_array($this->generator(['/a', '/broken'])->generate());
        $this->assertFalse($this->destination->fileExists(StagingFilesystem::DEFAULT_MANIFEST_PATH));
    }

    public function testPruneRemovesTheFilesTheRunNoLongerGenerates(): void
    {
        $staging = new StagingFilesystem($this->destination, prune: true);
        $this->destination->write('old.html', 'obsolete');
        $this->writePreviousManifest(['a.html', 'old.html']);

        iterator_to_array($this->generator(['/a', '/nested/b'], staging: $staging)->generate());

        $this->assertSame('new', $this->destination->read('a.html'));
        $this->assertFalse($this->destination->fileExists('old.html'));
        // Not listed by the previous manifest: not the bundle's to remove.
        $this->assertSame('keep', $this->destination->read('untouched.html'));

        /** @var array{files: array<string, string>} $manifest */
        $manifest = json_decode($this->destination->read(StagingFilesystem::DEFAULT_MANIFEST_PATH), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(['a.html', 'nested/b.html'], array_keys($manifest['files']));
    }

    public function testFilteredAndFailedRunsNeverPrune(): void
    {
        $staging = new StagingFilesystem($this->destination, prune: true);
        $this->destination->write('old.html', 'obsolete');
        $this->writePreviousManifest(['a.html', 'old.html']);

        // A filtered run only sees part of the site: it cannot know old.html is obsolete.
        iterator_to_array($this->generator(['/a'], staging: $staging)->generate(new GenerationFilter(paths: ['/a'])));
        $this->assertTrue($this->destination->fileExists('old.html'));

        // A failed run publishes nothing at all.
        iterator_to_array($this->generator(['/a', '/broken'], staging: $staging)->generate());
        $this->assertSame('obsolete', $this->destination->read('old.html'));
    }

    public function testPruneIgnoresADestinationWithoutUsableManifest(): void
    {
        $staging = new StagingFilesystem($this->destination, prune: true);
        $this->destination->write('old.html', 'obsolete');

        // No manifest at all.
        iterator_to_array($this->generator(['/a'], staging: $staging)->generate());
        $this->assertSame('obsolete', $this->destination->read('old.html'));

        // A manifest that is not one.
        $this->destination->write(StagingFilesystem::DEFAULT_MANIFEST_PATH, '{invalid');
        iterator_to_array($this->generator(['/a'], staging: $staging)->generate());
        $this->assertSame('obsolete', $this->destination->read('old.html'));
    }

    public function testPruningIsRefusedWhenAnotherRunPublishedMeanwhile(): void
    {
        $previous = json_encode(['version' => 1, 'runId' => 'run-a', 'generatedAt' => '2026-11-23T00:00:00+00:00', 'files' => ['old.html' => 'hash']]);
        $concurrent = json_encode(['version' => 1, 'runId' => 'run-b', 'generatedAt' => '2026-11-24T00:00:00+00:00', 'files' => ['other.html' => 'hash']]);
        $destination = $this->createMock(FilesystemOperator::class);
        // The manifest read at the start of the publication, then re-read before pruning.
        $destination->expects($this->exactly(2))->method('read')->willReturnOnConsecutiveCalls($previous, $concurrent);
        $destination->expects($this->never())->method('delete');
        $destination->expects($this->once())->method('write')->with(StagingFilesystem::DEFAULT_MANIFEST_PATH);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Another run published to the storage during this one: skipping the pruning of obsolete files.');
        $staging = new StagingFilesystem($destination, logger: $logger, prune: true);

        try {
            $staging->write('a.html', 'new');
            $staging->publish();
        } finally {
            $staging->discard();
        }
    }

    public function testPruneReturnsAndLogsTheRemovedFiles(): void
    {
        $this->destination->write('old.html', 'obsolete');
        $this->destination->write('other.html', 'obsolete too');
        $this->writePreviousManifest(['a.html', 'old.html', 'other.html']);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('Removed {count} obsolete file(s) from the storage: {paths}');
        $staging = new StagingFilesystem($this->destination, logger: $logger, prune: true);

        try {
            $staging->write('a.html', 'new');
            $this->assertSame(['old.html', 'other.html'], $staging->publish());
        } finally {
            $staging->discard();
        }
    }

    public function testADryRunReportsTheFilesItWouldPrune(): void
    {
        $staging = new StagingFilesystem($this->destination, prune: true);
        $this->destination->write('old.html', 'obsolete');
        $this->writePreviousManifest(['a.html', 'old.html']);

        $run = $this->generator(['/a'], staging: $staging)->generate(dryRun: true);
        iterator_to_array($run);

        $this->assertSame(['old.html'], $run->getReturn());
        // Nothing was published nor removed.
        $this->assertSame('previous', $this->destination->read('a.html'));
        $this->assertSame('obsolete', $this->destination->read('old.html'));
    }

    public function testWouldPruneIsNullWhenPruningIsDisabled(): void
    {
        $staging = new StagingFilesystem($this->destination);

        try {
            $this->assertNull($staging->wouldPrune());
        } finally {
            $staging->discard();
        }
    }

    public function testAPruningFailureIsLoggedWithoutFailingThePublication(): void
    {
        $destination = $this->createMock(FilesystemOperator::class);
        $destination->method('read')->willReturn(json_encode(['files' => ['old.html' => 'hash']]));
        $destination->method('delete')->willThrowException(\League\Flysystem\UnableToDeleteFile::atLocation('old.html'));
        $destination->expects($this->once())->method('write')->with(StagingFilesystem::DEFAULT_MANIFEST_PATH);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Cannot remove the obsolete file "{path}" from the storage: {message}');
        $staging = new StagingFilesystem($destination, logger: $logger, prune: true);

        try {
            $staging->write('a.html', 'new');
            $staging->publish();
        } finally {
            $staging->discard();
        }
    }

    public function testWritingToAnAlreadyPublishedExportIsAClearError(): void
    {
        $staging = new StagingFilesystem($this->destination);
        $staging->write('a.html', 'new');
        $staging->publish();

        try {
            $staging->write('late.html', 'too late');
            $this->fail('Writing after the publication should fail.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('already published', $exception->getMessage());
        } finally {
            $staging->discard();
        }

        // The next run stages again.
        $staging->start();
        $staging->write('next.html', 'next run');
        $staging->discard();
    }

    public function testAListenerWritingThroughTheBundleDumperAfterPublicationGetsAClearError(): void
    {
        $dumper = new FlysystemDumper($this->staging);
        $this->dispatcher->addListener(GenerationFinishedEvent::class, static function () use ($dumper): void {
            $dumper->dump('/late', new RenderedPage('too late', 'html'));
        });

        try {
            iterator_to_array($this->generator(['/a'])->generate());
            $this->fail('The listener write should fail.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('already published', $exception->getMessage());
        }

        $this->assertFalse($this->destination->fileExists('late.html'));
    }

    /**
     * @param list<string> $files
     */
    private function writePreviousManifest(array $files, string $runId = 'previous-run'): void
    {
        $this->destination->write(StagingFilesystem::DEFAULT_MANIFEST_PATH, json_encode([
            'version' => 1,
            'runId' => $runId,
            'generatedAt' => '2026-11-23T00:00:00+00:00',
            'files' => array_fill_keys($files, 'previous-hash'),
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string|UriGenerationFailure> $uris
     */
    private function generator(array $uris, ?PreparerInterface $preparer = null, ?StagingFilesystem $staging = null): StaticSiteGenerator
    {
        $staging ??= $this->staging;
        $provider = $this->createStub(UriProviderInterface::class);
        $provider->method('provide')->willReturn($uris);
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('render')->willReturnCallback(static function (string $uri): RenderedPage {
            if ('/broken' === $uri) {
                throw new RenderingException('failed');
            }

            return new RenderedPage('new', 'html');
        });

        return new StaticSiteGenerator($provider, $renderer, new FlysystemDumper($staging), $this->dispatcher, $this->createStub(ResetInterface::class), $staging, null === $preparer ? [] : [$preparer]);
    }
}
