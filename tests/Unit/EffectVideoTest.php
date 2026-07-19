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

use PapiAI\Core\Contracts\VideoProviderInterface;
use PapiAI\Core\JobStatus;
use PapiAI\Core\VideoResponse;
use PapiAI\Effect\EffectVideo;
use Phunkie\Effect\IO\IO;

/**
 * In-memory video provider recording calls for effect-bridge tests.
 */
class FakeVideoProvider implements VideoProviderInterface
{
    public int $generateCalls = 0;
    public int $startCalls = 0;

    /** @var array<int, JobStatus> */
    public array $statusQueue = [];
    public VideoResponse $video;

    public function __construct()
    {
        $this->video = VideoResponse::fromBytes('video-bytes', 'fake-model');
    }

    public function generateVideo(string $prompt, array $options = []): VideoResponse
    {
        ++$this->generateCalls;

        return $this->video;
    }

    public function startVideo(string $prompt, array $options = []): string
    {
        ++$this->startCalls;

        return 'job-1';
    }

    public function videoStatus(string $jobId): JobStatus
    {
        return array_shift($this->statusQueue) ?? new JobStatus($jobId, JobStatus::COMPLETED);
    }

    public function fetchVideo(string $jobId): VideoResponse
    {
        return $this->video;
    }

    public function supportsVideoGeneration(): bool
    {
        return true;
    }
}

/**
 * EffectVideo with the sleep seam neutralised for fast tests.
 */
class TestableEffectVideo extends EffectVideo
{
    public int $pauseCalls = 0;

    protected function pause(int $seconds): void
    {
        ++$this->pauseCalls;
    }
}

describe('EffectVideo', function () {
    beforeEach(function () {
        $this->provider = new FakeVideoProvider();
        $this->effect = new TestableEffectVideo($this->provider);
    });

    describe('generateVideoIO', function () {
        it('returns a lazy IO that does not run until executed', function () {
            $io = $this->effect->generateVideoIO('a cat surfing');

            expect($io)->toBeInstanceOf(IO::class);
            expect($this->provider->generateCalls)->toBe(0);

            $video = $io->unsafeRun();

            expect($this->provider->generateCalls)->toBe(1);
            expect($video)->toBeInstanceOf(VideoResponse::class);
            expect($video->data)->toBe('video-bytes');
        });
    });

    describe('startVideoIO / videoStatusIO / fetchVideoIO', function () {
        it('wraps startVideo in an IO', function () {
            $jobId = $this->effect->startVideoIO('x')->unsafeRun();

            expect($jobId)->toBe('job-1');
            expect($this->provider->startCalls)->toBe(1);
        });

        it('wraps videoStatus in an IO', function () {
            $this->provider->statusQueue = [new JobStatus('job-1', JobStatus::RUNNING)];

            $status = $this->effect->videoStatusIO('job-1')->unsafeRun();

            expect($status)->toBeInstanceOf(JobStatus::class);
            expect($status->isRunning())->toBeTrue();
        });

        it('wraps fetchVideo in an IO', function () {
            $video = $this->effect->fetchVideoIO('job-1')->unsafeRun();

            expect($video->data)->toBe('video-bytes');
        });
    });

    describe('generateVideoAsync', function () {
        it('forks into a fiber and awaits the result', function () {
            $handle = $this->effect->generateVideoAsync('a cat surfing');

            $video = $handle->await();

            expect($video)->toBeInstanceOf(VideoResponse::class);
            expect($video->data)->toBe('video-bytes');
            expect($this->provider->generateCalls)->toBe(1);
        });
    });

    describe('progressStream', function () {
        it('records observed statuses as a Stream and stops on a terminal status', function () {
            $this->provider->statusQueue = [
                new JobStatus('job-1', JobStatus::RUNNING),
                new JobStatus('job-1', JobStatus::RUNNING),
                new JobStatus('job-1', JobStatus::COMPLETED),
            ];

            $stream = $this->effect->progressStream('job-1');
            $statuses = $stream->toArray();

            expect($statuses)->toHaveCount(3);
            expect($statuses[2]->isCompleted())->toBeTrue();
            expect($this->effect->pauseCalls)->toBe(2);
        });

        it('stops on a failed status', function () {
            $this->provider->statusQueue = [
                new JobStatus('job-1', JobStatus::RUNNING),
                new JobStatus('job-1', JobStatus::FAILED, null, 'boom'),
            ];

            $statuses = $this->effect->progressStream('job-1')->toArray();

            expect($statuses)->toHaveCount(2);
            expect($statuses[1]->isFailed())->toBeTrue();
        });

        it('honours the maxPolls cap', function () {
            $this->provider->statusQueue = [];
            // videoStatus() returns COMPLETED by default once the queue is empty,
            // so force running by pre-filling with running statuses beyond the cap.
            $this->provider->statusQueue = array_fill(0, 10, new JobStatus('job-1', JobStatus::RUNNING));

            $statuses = $this->effect->progressStream('job-1', 3)->toArray();

            expect($statuses)->toHaveCount(3);
        });
    });
});
