<?php

declare(strict_types=1);

namespace Bakslash\StaticSiteBundle\Exception;

/**
 * The pages of the run reached the storage, but the run failed afterwards, e.g. because a
 * listener of GenerationFinishedEvent threw. The publication itself succeeded.
 */
final class PostPublicationException extends \RuntimeException implements ExceptionInterface
{
}
