<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Tests;

use Bakslash\StaticSiteBundle\Dumper\FlysystemDumper;
use Bakslash\StaticSiteBundle\Dumper\StagingFilesystem;
use Bakslash\StaticSiteBundle\Event\GenerationFinishedEvent;
use Bakslash\StaticSiteBundle\Event\GenerationPreparedEvent;
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
        $this->dispatcher->addListener(GenerationPreparedEvent::class, function (): void {
            $this->assertSame('previous', $this->destination->read('a.html'));
            $this->staging->write('sitemap.xml', '<urlset/>');
        });
        $this->dispatcher->addListener(GenerationFinishedEvent::class, function (GenerationFinishedEvent $event) use (&$published): void {
            $published = $event;
            $this->assertSame('new', $this->destination->read('a.html'));
            $this->assertSame('<urlset/>', $this->destination->read('sitemap.xml'));
        });

        $run = $this->generator(['/a', '/nested/b'])->generate();
        $this->assertInstanceOf(\Generator::class, $run);
        $run->rewind();
        $this->assertSame('previous', $this->destination->read('a.html'));
        iterator_to_array($run);

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

        $this->assertEquals(new GenerationFinishedEvent([]), $finished);

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

    public function testFailedPreparationListenerDoesNotTouchTheDestination(): void
    {
        $this->dispatcher->addListener(GenerationPreparedEvent::class, static function (): void {
            throw new DumpingException('cannot generate sitemap');
        });
        try {
            iterator_to_array($this->generator(['/a'])->generate());
            $this->fail('The listener should abort the run.');
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
        $this->dispatcher->addListener(\Bakslash\StaticSiteBundle\Event\GenerationStartedEvent::class, $dumper->onGenerationStarted(...));
        $this->dispatcher->addListener(GenerationPreparedEvent::class, $dumper->onGenerationPrepared(...));
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
        $generator = new StaticSiteGenerator($provider, $renderer, $dumper, $this->dispatcher, $this->createStub(ResetInterface::class), $this->staging);

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
        $staging = new StagingFilesystem($this->destination);
        $adapter = $this->createStub(\League\Flysystem\FilesystemAdapter::class);
        $adapter->method('deleteDirectory')->willThrowException(\League\Flysystem\UnableToDeleteDirectory::atLocation('temporary'));
        (new \ReflectionProperty(Filesystem::class, 'adapter'))->setValue($staging, $adapter);
        $failure = new DumpingException('Publication failed; destination may be partially updated.');

        try {
            $staging->discard($failure);
            $this->fail('Cleanup should fail.');
        } catch (DumpingException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
            $this->assertStringStartsWith('Publication failed; destination may be partially updated.', $exception->getMessage());
            $this->assertStringContainsString('cleanup also failed', $exception->getMessage());
        }
    }

    /**
     * @param list<string|UriGenerationFailure> $uris
     */
    private function generator(array $uris): StaticSiteGenerator
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

        return new StaticSiteGenerator($provider, $renderer, new FlysystemDumper($this->staging), $this->dispatcher, $this->createStub(ResetInterface::class), $this->staging);
    }
}
