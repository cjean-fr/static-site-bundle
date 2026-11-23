<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Generator;

use Bakslash\StaticSiteBundle\Dumper\DumperInterface;
use Bakslash\StaticSiteBundle\Dumper\PreparerInterface;
use Bakslash\StaticSiteBundle\Dumper\StagingFilesystem;
use Bakslash\StaticSiteBundle\Event\GenerationFinishedEvent;
use Bakslash\StaticSiteBundle\Event\GenerationStatus;
use Bakslash\StaticSiteBundle\Event\PageDumpedEvent;
use Bakslash\StaticSiteBundle\Event\PageFailedEvent;
use Bakslash\StaticSiteBundle\Event\PageRenderedEvent;
use Bakslash\StaticSiteBundle\Exception\DumpingException;
use Bakslash\StaticSiteBundle\Exception\RenderingException;
use Bakslash\StaticSiteBundle\Renderer\RendererInterface;
use Bakslash\StaticSiteBundle\UriProvider\GenerationFilter;
use Bakslash\StaticSiteBundle\UriProvider\UriGenerationFailure;
use Bakslash\StaticSiteBundle\UriProvider\UriProviderInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal
 */
final readonly class StaticSiteGenerator
{
    public function __construct(
        private UriProviderInterface $uriProvider,
        private RendererInterface $renderer,
        private DumperInterface $dumper,
        private EventDispatcherInterface $eventDispatcher,
        private ResetInterface $servicesResetter,
        private ?StagingFilesystem $staging = null,
        private ?PreparerInterface $preparer = null,
    ) {
    }

    /**
     * @return iterable<GenerationResult>
     */
    public function generate(GenerationFilter $filter = new GenerationFilter()): iterable
    {
        $failure = null;

        try {
            $this->preparer?->start();
            $seen = [];
            $dumped = [];
            $successful = true;

            // Resolved before the first page: rendering and the reset between pages both rewrite the
            // router context the URIs are generated against.
            $items = iterator_to_array($this->uriProvider->provide($filter), false);

            foreach ($items as $item) {
                if ($item instanceof UriGenerationFailure) {
                    $successful = false;
                    $this->eventDispatcher->dispatch(new PageFailedEvent($item->identifier, $item->error));

                    yield GenerationResult::failure($item->identifier, $item->error);

                    continue;
                }

                // The same URI can be produced twice (duplicate parameter sets, or a static and a
                // generated route colliding); generate it only once, first occurrence wins.
                if (isset($seen[$item])) {
                    continue;
                }
                $seen[$item] = true;

                try {
                    $event = new PageRenderedEvent($item, $this->renderer->render($item));
                    $this->eventDispatcher->dispatch($event);

                    $this->dumper->dump($item, $event->getPage());
                    $this->eventDispatcher->dispatch(new PageDumpedEvent($item));
                    $dumped[] = $item;

                    $result = GenerationResult::success($item);
                } catch (RenderingException|DumpingException $exception) {
                    $successful = false;
                    $this->eventDispatcher->dispatch(new PageFailedEvent($item, $exception));

                    $result = GenerationResult::failure($item, $exception);
                } finally {
                    $this->servicesResetter->reset();
                }

                yield $result;
            }

            $this->preparer?->prepare(partial: !$filter->isEmpty(), successful: $successful);

            if ($successful) {
                $this->staging?->publish();
            }

            $this->eventDispatcher->dispatch(new GenerationFinishedEvent($dumped, !$filter->isEmpty(), null === $this->staging || $successful ? GenerationStatus::Published : GenerationStatus::Discarded));
        } catch (\Throwable $exception) {
            $failure = $exception;

            throw $exception;
        } finally {
            $this->staging?->discard($failure);
        }
    }
}
