<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Command;

use Bakslash\StaticSiteBundle\Dumper\StagingFilesystem;
use Bakslash\StaticSiteBundle\Generator\StaticSiteGenerator;
use Bakslash\StaticSiteBundle\UriProvider\GenerationFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(name: 'static-site:generate', description: 'Generates static pages')]
final readonly class GenerateStaticSiteCommand
{
    private const string PROGRESS_FORMAT = ' <info>%current%</info> pages generated (%elapsed%)';

    public function __construct(
        private StaticSiteGenerator $generator,
        private bool $debug,
        private ?StagingFilesystem $staging = null,
        private bool $publishesFailures = false,
    ) {
    }

    /**
     * @param list<string> $route
     * @param list<string> $path
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only generate routes whose name, or canonical name for a localized route, matches this glob (repeatable).')]
        array $route = [],
        #[Option(description: 'Only generate URIs matching this glob (repeatable).')]
        array $path = [],
        #[Option(description: 'Generate even though the kernel is in debug mode.')]
        bool $allowDebug = false,
        #[Option(description: 'Prepare every page without publishing anything (requires the "staging" option).')]
        bool $dryRun = false,
    ): int {
        if ($dryRun && null === $this->staging) {
            $io->error('A dry run requires the "staging" option: without it, pages are written to the storage as they are generated.');

            return Command::FAILURE;
        }

        if ($this->debug && !$allowDebug) {
            $io->error('Refusing to generate in debug mode: pages would carry the web debug toolbar. Run with --env=prod or --allow-debug.');

            return Command::FAILURE;
        }

        $generated = 0;
        $failed = 0;

        $progress = !$io->isVerbose() && $io->isDecorated() ? $io->createProgressBar() : null;
        // No elapsed time: nothing redraws the bar while the URIs are resolved.
        $progress?->setFormat(' Resolving the pages to generate...');
        $progress?->start();

        $run = $this->generator->generate(new GenerationFilter($route, $path), $dryRun);

        try {
            foreach ($run as $result) {
                if (0 === $generated + $failed) {
                    $progress?->setFormat(self::PROGRESS_FORMAT);
                }

                if (null === $result->error) {
                    ++$generated;
                    $io->writeln(\sprintf(' <info>✓</info> %s', $result->uri), OutputInterface::VERBOSITY_VERBOSE);
                } else {
                    ++$failed;
                    $progress?->clear();
                    $io->writeln(\sprintf(' <error>✗</error> %s: %s', $result->uri, $result->error->getMessage()));
                    $progress?->display();
                }

                $progress?->advance();
            }
            $prunedFiles = $run->getReturn();
        } finally {
            if (null !== $progress) {
                $progress->setFormat(self::PROGRESS_FORMAT);
                $progress->finish();
                $io->newLine(2);
            }
        }

        if ($failed > 0) {
            // A staged run is discarded as a whole: no page reached the storage.
            $io->error(null !== $this->staging && !$this->publishesFailures
                ? \sprintf('Prepared %d page(s), %d failed: the run was discarded, nothing was published.', $generated, $failed)
                : \sprintf('Generated %d page(s), %d failed.', $generated, $failed));

            return Command::FAILURE;
        }

        if (0 === $generated) {
            $io->warning('No page to generate: check the #[Prerender] attributes and any --route/--path filter.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $message = \sprintf('Prepared %d page(s); dry run, nothing was published.', $generated);
            if (null !== $prunedFiles) {
                $message .= \sprintf(' %d file(s) would be pruned.', \count($prunedFiles));
            }
            $io->success($message);

            return Command::SUCCESS;
        }

        if (null !== $this->staging) {
            $message = \sprintf('Prepared %d page(s), then published them to the storage.', $generated);
            if (!empty($prunedFiles)) {
                $message .= \sprintf(' Removed %d obsolete file(s).', \count($prunedFiles));
            }
            $io->success($message);

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Generated %d page(s).', $generated));

        return Command::SUCCESS;
    }
}
