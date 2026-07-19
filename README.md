# papi-ai/effect

Effect/fiber async bridge for [PapiAI](https://papi-ai.org), built on
[`phunkie/effect`](https://packagist.org/packages/phunkie/effect) and
[`phunkie/streams`](https://packagist.org/packages/phunkie/streams).

Long-running capabilities such as video generation (Google Veo, OpenAI Sora) can take minutes.
This package lets you describe those jobs as pure `IO` effects, fork them into background fibers,
and await the result later — **without** Symfony Messenger or any external queue.

## Install

```bash
composer require papi-ai/effect
```

Requires `papi-ai/papi-core` `^0.10` and a video-capable provider (e.g. `papi-ai/google` or
`papi-ai/openai`).

## Usage

```php
use PapiAI\Google\GoogleProvider;
use PapiAI\Effect\EffectVideo;

$effect = new EffectVideo(new GoogleProvider($apiKey));

// Describe the work as a lazy IO (nothing runs yet)
$io = $effect->generateVideoIO('a cat surfing a giant wave', ['durationSeconds' => 8]);

// Fork into a background fiber and carry on
$handle = $effect->generateVideoAsync('a cat surfing a giant wave', ['durationSeconds' => 8]);
// ... do other work ...
$video = $handle->await();      // VideoResponse
$video->save('surf.mp4');
```

Compose the effects with `map()` / `flatMap()`, or run them synchronously with `unsafeRun()`.

### Progress as a stream

```php
$jobId = $effect->startVideoIO('a neon city at night')->unsafeRun();

foreach ($effect->progressStream($jobId)->toArray() as $status) {
    echo $status->status, "\n"; // running, running, ... completed
}
```

## API

`EffectVideo` wraps any `VideoProviderInterface`:

| Method | Returns | Notes |
|---|---|---|
| `generateVideoIO($prompt, $options)` | `IO<VideoResponse>` | blocking generation as a lazy effect |
| `startVideoIO($prompt, $options)` | `IO<string>` | submit, produce a job id |
| `videoStatusIO($jobId)` | `IO<JobStatus>` | poll status |
| `fetchVideoIO($jobId)` | `IO<VideoResponse>` | fetch a completed job |
| `generateVideoAsync($prompt, $options, $context?)` | `AsyncHandle<VideoResponse>` | fork into a fiber; `await()` later |
| `progressStream($jobId, $maxPolls = 60)` | `Stream<JobStatus>` | observed statuses until terminal |

## License

MIT © Marcello Duarte
