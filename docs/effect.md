# Effect bridge

`papi-ai/effect` adapts PapiAI's long-running capabilities to a functional effect system,
using [`phunkie/effect`](https://packagist.org/packages/phunkie/effect) (an `IO` monad with
fiber-based concurrency) and [`phunkie/streams`](https://packagist.org/packages/phunkie/streams)
(lazy streams). It is the async story for applications that do not use Symfony Messenger.

## Why

`VideoProviderInterface` exposes both a blocking `generateVideo()` and an async trio
(`startVideo()` / `videoStatus()` / `fetchVideo()`) that reuses the core `JobStatus` value object.
The async trio already works with any queue that consumes `JobStatus` (e.g. the Symfony Messenger
bridge). When you do not have such a queue, `EffectVideo` lets you fork the work into a PHP fiber
and await it later, with no infrastructure.

## Effects are lazy

Every `*IO()` method returns an `IO` that does nothing until executed:

```php
$io = $effect->generateVideoIO('a cat surfing');   // no HTTP yet
$video = $io->unsafeRun();                          // now it runs, blocking
```

Compose before running:

```php
$sizeIO = $effect->generateVideoIO('a cat surfing')
    ->map(fn ($video) => $video->size());           // IO<int>
```

## Fibers

`generateVideoAsync()` forks `generateVideo()` into a background fiber and returns an
`AsyncHandle`. This is `generateVideoIO()->start($context)->unsafeRun()` in one call:

```php
$handle = $effect->generateVideoAsync('a cat surfing');
// ... unrelated work runs while the video generates ...
$video = $handle->await();
```

Pass a `Phunkie\Effect\Concurrent\ParallelExecutionContext` (requires `ext-parallel` on a ZTS
build) to run on OS threads instead of fibers.

## Progress stream

`progressStream()` polls a job and records each observed `JobStatus` as a `phunkie/streams`
`Stream`, stopping on the first terminal status or after `maxPolls`:

```php
$statuses = $effect->progressStream($jobId)->toArray();
$last = end($statuses);        // JobStatus (completed or failed)
```

It blocks while polling; wrap it in an `IO` and `start()` it, or drive `videoStatusIO()` yourself,
for a non-blocking variant.
