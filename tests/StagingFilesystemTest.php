<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Tests;

use Bakslash\StaticSiteBundle\Dumper\FlysystemDumper;
use Bakslash\StaticSiteBundle\Dumper\PreparerInterface;
use Bakslash\StaticSiteBundle\Dumper\StagingFilesystem;
use Bakslash\StaticSiteBundle\Event\GenerationFinishedEvent;
use Bakslash\StaticSiteBundle\Event\GenerationStatus;
use Bakslash\StaticSiteBundle\Exception\DumpingException;
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

    public function testPublishesStreamsWithoutReadingOrListingTheDestination(): void
    {
        $destination = $this->createMock(FilesystemOperator::class);
        $destination->expects($this->never())->method('read');
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
        $generator = new StaticSiteGenerator($provider, $renderer, $dumper, $this->dispatcher, $this->createStub(ResetInterface::class), $this->staging, $dumper);

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

    public function testPublishesAManifestDescribingTheRunAfterItsFiles(): void
    {
        iterator_to_array($this->generator(['/a', '/nested/b'])->generate());

        /** @var array{generatedAt: string, files: array<string, string>} $manifest */
        $manifest = json_decode($this->destination->read(StagingFilesystem::MANIFEST_FILE), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('generatedAt', $manifest);
        $this->assertSame(
            ['a.html' => hash('sha256', 'new'), 'nested/b.html' => hash('sha256', 'new')],
            $manifest['files'],
        );
    }

    public function testFilteredOrFailedRunsLeaveNoManifest(): void
    {
        iterator_to_array($this->generator(['/a'])->generate(new GenerationFilter(paths: ['/a'])));
        $this->assertFalse($this->destination->fileExists(StagingFilesystem::MANIFEST_FILE));

        iterator_to_array($this->generator(['/a', '/broken'])->generate());
        $this->assertFalse($this->destination->fileExists(StagingFilesystem::MANIFEST_FILE));
    }

    /**
     * @param list<string|UriGenerationFailure> $uris
     */
    private function generator(array $uris, ?PreparerInterface $preparer = null): StaticSiteGenerator
    {
        $provider = $this->createStub(UriProviderInterface::class);
        $provider->method('provide')->willReturn($uris);
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('render')->willReturnCallback(static function (string $uri): RenderedPage {
            if ('/broken' === $uri) {
                throw new RenderingException('failed');
            }

            return new RenderedPage('new', 'html');
        });

        return new StaticSiteGenerator($provider, $renderer, new FlysystemDumper($this->staging), $this->dispatcher, $this->createStub(ResetInterface::class), $this->staging, $preparer);
    }
}
