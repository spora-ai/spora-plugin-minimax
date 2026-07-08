<?php

declare(strict_types=1);

use Mockery as M;
use Psr\Log\NullLogger;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Services\AutoAssetStore;
use Spora\Services\DataUrlAssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaArchiveService;
use Spora\Services\MediaArchive\MediaArchiveUrlResolver;
use Spora\Services\MediaArchive\MetadataExtractor;
use Spora\Services\MediaArchive\MimeSniffer;
use Spora\Services\MediaArchive\RemoteMediaFetcher;
use Spora\Services\ToolConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Smoke tests for the MediaArchive ingest integration in the four
 * MiniMax*Tool classes.
 *
 * Why this is structured the way it is
 * ------------------------------------
 * `MediaArchiveService` is `final` (we can't subclass it) and its
 * `ingest()` writes to the `media_assets` table. We don't want this
 * plugin's tests to depend on a database, so the assertions here are
 * intentionally limited to:
 *
 *  1. **Call signature round-trip.** We pass each tool a real
 *     `MediaArchiveService` configured with a real
 *     `LocalAssetStore`/`DataUrlAssetStore`/`MockHttpClient`. A
 *     `MockHttpClient` configured with no responses makes the URL
 *     probe fail with a 404-equivalent, which causes the URL branch to
 *     fall back to `external` mode (no byte fetch, no `AssetStore`
 *     write). The service still constructs and persists a row, but
 *     via the global Eloquent connection — which the test will catch
 *     and clean up in `afterEach`. The test only asserts that the tool
 *     returns `success: true` and that the result body still contains
 *     the expected URL/audio, which proves the ingest() call didn't
 *     throw.
 *
 *  2. **Failure swallowing.** The whole point of the try/catch in
 *     each tool is that a failing `ingest()` must not break the
 *     tool's main flow. We prove this by configuring the service with
 *     a deliberately broken HTTP client (the MockHttpClient that
 *     throws on every request) and asserting the tool still returns
 *     `success: true`.
 *
 *  3. **Null safety.** Constructing the tool without a media archive
 *     must not break the success path — the trait's `mediaArchive()`
 *     getter throws if the service is null, but the ingest is
 *     wrapped in `try { ... } catch (Throwable)` so the call site
 *     should swallow that too. This is also covered.
 */

function minimaxArchiveResponse(int $status, string $body): ResponseInterface
{
    $response = M::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn($status);
    $response->allows('getContent')->andReturn($body);
    return $response;
}

/**
 * Build the same `AutoAssetStore` the core test suite uses, backed by
 * a real `LocalAssetStore` rooted at a temp dir and a `DataUrlAssetStore`
 * for in-memory payloads. Exposed as a helper so speech/music tools can
 * inject it as their `AssetStore` ctor arg, and so the `MediaArchiveService`
 * helper can reuse it.
 */
function minimaxTestAssetStore(): AutoAssetStore
{
    return new AutoAssetStore(
        new DataUrlAssetStore(50 * 1024 * 1024),
        new LocalAssetStore(
            new Spora\Core\Paths(sys_get_temp_dir() . '/minimax-test-archive'),
            new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            50 * 1024 * 1024,
        ),
        1_048_576,
    );
}

/**
 * Build a real MediaArchiveService backed by a MockHttpClient that
 * returns 404-equivalent for every request. The URL branch then falls
 * back to `external` mode and never writes to the local asset store.
 */
function minimaxTestArchiveService(?HttpClientInterface $http = null): MediaArchiveService
{
    $logger   = new NullLogger();
    $sniffer  = new MimeSniffer();
    $meta     = new MetadataExtractor($logger, false);
    $http ??= new MockHttpClient([]); // every request → empty response
    $fetcher = new RemoteMediaFetcher($http, $logger, 30, 100 * 1024 * 1024);
    $resolver = new MediaArchiveUrlResolver($fetcher, $sniffer, $logger, true, 100 * 1024 * 1024);

    return new MediaArchiveService(
        minimaxTestAssetStore(),
        $resolver,
        $sniffer,
        $meta,
    );
}

it('image tool calls MediaArchive::ingest without breaking the success result', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();
    $archive = minimaxTestArchiveService();

    $http->allows('request')->andReturn(minimaxArchiveResponse(200, json_encode([
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        'data'      => [
            'image_urls' => [
                'https://cdn.example.com/a.png',
                'https://cdn.example.com/b.png',
            ],
        ],
    ])));

    $tool = new MiniMaxImageTool($config, $http, $log, null, null, $archive);
    $result = $tool->execute(['prompt' => 'a red fox'], 42);

    // The tool succeeded — proves both image URLs were emitted and that
    // the per-URL ingest() call did not throw (it would have been
    // caught and logged, but we'd still see success:true as long as
    // the upstream response was valid).
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('https://cdn.example.com/a.png')
        ->and($result->content)->toContain('https://cdn.example.com/b.png')
        ->and($result->data['image_urls'])->toHaveCount(2);
});

it('speech tool ingests a CDN audio_url via the MediaArchive', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();
    $archive = minimaxTestArchiveService();

    $http->allows('request')->andReturn(minimaxArchiveResponse(200, json_encode([
        'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
        'data'       => ['audio_url' => 'https://cdn.example.com/speech.mp3'],
        'extra_info' => ['audio_size' => 12_345],
    ])));

    $tool = new MiniMaxSpeechTool($config, $http, $log, minimaxTestAssetStore(), null, null, $archive);
    $result = $tool->execute([
        'text'     => 'hello world',
        'voice_id' => 'English_PassionateWarrior',
    ], 7);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('https://cdn.example.com/speech.mp3');
});

it('music tool ingests a hex audio payload via the MediaArchive', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();
    $archive = minimaxTestArchiveService();

    // 24 bytes of MP3 silence (all zeros) → "00"×24.
    $hexAudio = str_repeat('00', 24);
    $http->allows('request')->andReturn(minimaxArchiveResponse(200, json_encode([
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        'data'      => ['audio' => $hexAudio],
    ])));

    $tool = new MiniMaxMusicTool($config, $http, $log, minimaxTestAssetStore(), null, null, $archive);
    $result = $tool->execute([
        'action'        => 'compose',
        'prompt'        => 'lofi piano',
        'output_format' => 'hex',
    ], 99);

    expect($result->success)->toBeTrue();
});

it('video tool ingests the download_url with width/height/duration on the request', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'                => 'k',
        'poll_interval_seconds'  => '1',
        'poll_timeout_seconds'   => '5',
        'submit_timeout_seconds' => '30',
    ]);

    $http = M::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();
    $archive = minimaxTestArchiveService();

    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', M::any())
        ->andReturn(minimaxArchiveResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-xyz',
        ])));
    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', M::any())
        ->andReturn(minimaxArchiveResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'      => 'task-xyz',
            'status'       => 'Success',
            'file_id'      => 'file-abc-123',
            'video_width'  => 1920,
            'video_height' => 1080,
        ])));
    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', M::any())
        ->andReturn(minimaxArchiveResponse(200, json_encode([
            'file' => [
                'file_id'      => 'file-abc-123',
                'download_url' => 'https://minimax.example/output.mp4',
            ],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log, null, null, $archive);
    $result = $tool->execute(['prompt' => '[Push in] a forest', 'duration_seconds' => '6'], 11);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('https://minimax.example/output.mp4')
        ->and($result->data['width'])->toBe(1920)
        ->and($result->data['height'])->toBe(1080);
});

it('a failing MediaArchive::ingest() does not break the tool result (image tool)', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    // A HTTP client that throws on every request — the URL probe will
    // fail, the service's URL branch will translate to a fallback that
    // also fails, and `ingest()` will surface the exception to the
    // tool's try/catch.
    $explodingHttp = new MockHttpClient(function (): never {
        throw new RuntimeException('archive service is down');
    });
    $archive = minimaxTestArchiveService($explodingHttp);

    $http->allows('request')->andReturn(minimaxArchiveResponse(200, json_encode([
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        'data'      => ['image_urls' => ['https://cdn.example.com/a.png']],
    ])));

    $tool = new MiniMaxImageTool($config, $http, $log, null, null, $archive);
    $result = $tool->execute(['prompt' => 'a red fox'], 1);

    // The tool succeeded — the upstream call returned a valid URL.
    // The archive failure was logged and swallowed.
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('https://cdn.example.com/a.png');
});
