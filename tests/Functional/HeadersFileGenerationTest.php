<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Tests\Functional;

use Bakslash\StaticSiteBundle\Tests\Fixtures\HeadersFileKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class HeadersFileGenerationTest extends TestCase
{
    private ?HeadersFileKernel $kernel = null;

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            (new Filesystem())->remove($this->kernel->getOutputDir());
            $this->kernel->shutdown();
        }
    }

    public function testWritesTheHeadersOfThePagesToTheHeadersFile(): void
    {
        $kernel = $this->kernel = new HeadersFileKernel('test', false);
        $this->generate($kernel);

        $this->assertSame(implode("\n", [
            '# Written by static-site:generate. Edit outside these lines.',
            '/*',
            "  Content-Security-Policy: default-src 'self'",
            '/secured',
            '  Strict-Transport-Security: max-age=31536000; includeSubDomains',
            '# End of static-site:generate.',
            '',
        ]), (string) file_get_contents($kernel->getOutputDir().'/_headers'));
    }

    public function testWritesNoHeadersFileByDefault(): void
    {
        $kernel = $this->kernel = new HeadersFileKernel('test', false, null);
        $this->generate($kernel);

        $this->assertFileExists($kernel->getOutputDir().'/secured.html');
        $this->assertFileDoesNotExist($kernel->getOutputDir().'/_headers');
    }

    public function testPreservesHandWrittenHeadersFromThePreviousExport(): void
    {
        $kernel = $this->kernel = new HeadersFileKernel('test', false);
        $filesystem = new Filesystem();
        $filesystem->dumpFile($kernel->getOutputDir().'/_headers', "/manual\n  X-Frame-Options: DENY\n");
        $this->generate($kernel);

        $this->assertStringStartsWith("/manual\n  X-Frame-Options: DENY\n", (string) file_get_contents($kernel->getOutputDir().'/_headers'));
        $this->assertStringContainsString('# Written by static-site:generate.', (string) file_get_contents($kernel->getOutputDir().'/_headers'));
    }

    public function testMalformedHeadersAbortBeforePublishingAnyPage(): void
    {
        $kernel = $this->kernel = new HeadersFileKernel('test', false);
        $filesystem = new Filesystem();
        $filesystem->dumpFile($kernel->getOutputDir().'/secured.html', 'previous page');
        $filesystem->dumpFile($kernel->getOutputDir().'/_headers', '# Written by static-site:generate. Edit outside these lines.');
        $filesystem->remove($kernel->getCacheDir());
        $kernel->boot();
        $tester = new CommandTester((new Application($kernel))->find('static-site:generate'));

        try {
            $tester->execute([]);
            $this->fail('Malformed headers should abort generation.');
        } catch (\Bakslash\StaticSiteBundle\Exception\DumpingException) {
        }

        $this->assertSame('previous page', file_get_contents($kernel->getOutputDir().'/secured.html'));
        $this->assertSame('# Written by static-site:generate. Edit outside these lines.', file_get_contents($kernel->getOutputDir().'/_headers'));
    }

    public function testFilteredRunKeepsThePreviousHeadersFile(): void
    {
        $kernel = $this->kernel = new HeadersFileKernel('test', false);
        $filesystem = new Filesystem();
        $filesystem->dumpFile($kernel->getOutputDir().'/_headers', 'previous headers');
        $filesystem->remove($kernel->getCacheDir());
        $kernel->boot();
        $tester = new CommandTester((new Application($kernel))->find('static-site:generate'));

        $this->assertSame(0, $tester->execute(['--path' => ['/secured']]), $tester->getDisplay());
        $this->assertSame('previous headers', file_get_contents($kernel->getOutputDir().'/_headers'));
        $this->assertFileExists($kernel->getOutputDir().'/secured.html');
    }

    private function generate(HeadersFileKernel $kernel): void
    {
        // A non-debug kernel never checks its cache for freshness.
        (new Filesystem())->remove($kernel->getCacheDir());
        $kernel->boot();
        $tester = new CommandTester((new Application($kernel))->find('static-site:generate'));

        $this->assertSame(0, $tester->execute([]), $tester->getDisplay());
    }
}
