<?php

/*
 * This file is part of PapiAI,
 * A simple but powerful PHP library for building AI agents.
 *
 * (c) Marcello Duarte <marcello.duarte@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PapiAI\Effect;

use PapiAI\Core\Contracts\VideoProviderInterface;
use PapiAI\Core\JobStatus;
use Phunkie\Effect\Concurrent\AsyncHandle;
use Phunkie\Effect\Concurrent\ExecutionContext;

use function Phunkie\Effect\Functions\io\io;

use Phunkie\Effect\IO\IO;

use Phunkie\Streams\Type\Stream;

/**
 * Effect/fiber async bridge for video generation.
 *
 * Wraps any {@see VideoProviderInterface} (e.g. Google Veo, OpenAI Sora) in phunkie/effect
 * IO values so long-running video jobs can be described as pure effects, forked into
 * background fibers, and awaited later — no Symfony Messenger or external queue required.
 *
 * The effectful methods return lazy IO values (nothing runs until unsafeRun()/await()).
 * generateVideoAsync() is a convenience that forks generateVideo() into a fiber and hands
 * back an AsyncHandle. progressStream() records the observed JobStatus values as a
 * phunkie/streams Stream for callers that want to compose with stream combinators.
 */
class EffectVideo
{
    public function __construct(
        private readonly VideoProviderInterface $provider,
    ) {
    }

    /**
     * Describe a blocking video generation as a lazy IO effect.
     *
     * @param string $prompt  The video generation prompt
     * @param array  $options Provider-specific options (see VideoProviderInterface::generateVideo)
     *
     * @return IO IO<VideoResponse> — runs the generation when executed
     */
    public function generateVideoIO(string $prompt, array $options = []): IO
    {
        return io(fn () => $this->provider->generateVideo($prompt, $options));
    }

    /**
     * Describe submitting a video job as a lazy IO effect.
     *
     * @param string $prompt  The video generation prompt
     * @param array  $options Provider-specific options
     *
     * @return IO IO<string> — produces the job id when executed
     */
    public function startVideoIO(string $prompt, array $options = []): IO
    {
        return io(fn () => $this->provider->startVideo($prompt, $options));
    }

    /**
     * Describe polling a job's status as a lazy IO effect.
     *
     * @param string $jobId The job identifier from startVideo()
     *
     * @return IO IO<JobStatus>
     */
    public function videoStatusIO(string $jobId): IO
    {
        return io(fn () => $this->provider->videoStatus($jobId));
    }

    /**
     * Describe fetching a completed job's video as a lazy IO effect.
     *
     * @param string $jobId The job identifier from startVideo()
     *
     * @return IO IO<VideoResponse>
     */
    public function fetchVideoIO(string $jobId): IO
    {
        return io(fn () => $this->provider->fetchVideo($jobId));
    }

    /**
     * Fork a blocking video generation into a background fiber.
     *
     * Returns immediately with an AsyncHandle; call await() on it to retrieve the
     * VideoResponse once the fiber completes. Equivalent to
     * generateVideoIO()->start($context)->unsafeRun().
     *
     * @param string                $prompt  The video generation prompt
     * @param array                 $options Provider-specific options
     * @param ExecutionContext|null $context Execution context (defaults to a fiber context)
     *
     * @return AsyncHandle AsyncHandle<VideoResponse>
     */
    public function generateVideoAsync(string $prompt, array $options = [], ?ExecutionContext $context = null): AsyncHandle
    {
        /** @var AsyncHandle $handle */
        $handle = $this->generateVideoIO($prompt, $options)->start($context)->unsafeRun();

        return $handle;
    }

    /**
     * Poll a job to completion, recording each observed status as a phunkie/streams Stream.
     *
     * Blocks while polling (compose with generateVideoAsync() or an IO fork for a
     * non-blocking version). Stops on the first terminal status or after $maxPolls.
     *
     * @param string $jobId    The job identifier from startVideo()
     * @param int    $maxPolls Safety cap on the number of polls
     *
     * @return Stream Stream<JobStatus> of the statuses observed, in order
     */
    public function progressStream(string $jobId, int $maxPolls = 60): Stream
    {
        $statuses = [];

        for ($poll = 0; $poll < $maxPolls; ++$poll) {
            $status = $this->provider->videoStatus($jobId);
            $statuses[] = $status;

            if ($status->isCompleted() || $status->isFailed()) {
                break;
            }

            $this->pause(1);
        }

        return \Stream(...$statuses);
    }

    /**
     * Pause between poll attempts. Isolated so tests can override it to no-op.
     *
     * @param int $seconds Seconds to sleep
     */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }
}
