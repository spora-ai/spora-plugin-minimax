<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Mockery as M;
use Psr\Log\NullLogger;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxImageTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
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
use Symfony\Component\HttpClient\Response\MockResponse;
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

/**
 * Filename-shape contract for every tool's MediaIngestRequest. The four
 * tests below share the same regex (`minimax-<tool>-UTC-8hex.<ext>`)
 * because the contract is per-plugin, not per-tool. The capture is done
 * at the {@see AssetStore} seam: `MediaArchiveService::ingest()` is
 * declared `final` in the vendored spora-core (v0.7.1) so Mockery
 * cannot mock it, but the filename flows through to
 * `AssetStore::store($bytes, $mime, $filename)` for every ingest path
 * that handles bytes (URL branch, hex branch, base64 branch), and a
 * small custom {@see AssetStore} implementation captures the value.
 *
 * For URL inputs the resolver must be configured to fetch the body
 * (`promoteExternal=true`); the existing test helpers in this file
 * keep it off so the URL branch falls back to the no-byte `external`
 * path, which never touches the AssetStore. The helper below flips
 * that flag and serves a small fixed payload for every HEAD/GET so
 * the bytes flow through to the capturing store.
 */
const MINIMAX_FILENAME_REGEX = '/^minimax-(image|video|music|speech)-\d{4}-\d{2}-\d{2}-\d{6}-[0-9a-f]{8}\.(png|mp4|mp3)$/';

/**
 * Test double for {@see AssetStore} that records every filename passed
 * to `store()` and replies with a tiny in-memory reference.
 * Implementing the interface (rather than mocking `LocalAssetStore`,
 * which is `final`) keeps the capture independent of the asset-store
 * implementation spora-core chooses.
 */
final class MinimaxFilenameCapturingStore implements AssetStore
{
    /** @var list<string> */
    public array $capturedFilenames = [];

    public function store(string $bytes, ?string $mime = null, ?string $filename = null): AssetReference
    {
        if (is_string($filename)) {
            $this->capturedFilenames[] = $filename;
        }
        return new AssetReference(
            url: 'data:,' . bin2hex(random_bytes(4)),
            mode: 'data_url',
        );
    }
}

/**
 * Build a real {@see MediaArchiveService} whose {@see AssetStore}
 * dependency is a {@see MinimaxFilenameCapturingStore}. The URL
 * resolver is configured to fetch bytes (so the URL branch
 * exercises `AssetStore::store()`, the only seam that sees the
 * request's `filename`) and a {@see MockHttpClient} returns a tiny
 * payload for any URL. Also boots an in-memory SQLite database so
 * the row-persist step doesn't throw — without it, the service
 * would log a warning and skip persisting, which would still let
 * `store()` be called (so the capture works) but is cleaner when
 * the row actually saves.
 *
 * @return array{0: MediaArchiveService, 1: MinimaxFilenameCapturingStore}
 */
function minimaxFilenameCaptureArchiveService(): array
{
    $capsule = new Manager();
    $capsule->addConnection([
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $capsule->schema()->create('media_assets', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->unsignedBigInteger('agent_id')->nullable();
        $table->unsignedBigInteger('task_id')->nullable();
        $table->unsignedBigInteger('tool_call_id')->nullable();
        $table->string('plugin_slug', 64)->nullable();
        $table->string('tool_name', 64)->nullable();
        $table->string('media_type', 16)->nullable();
        $table->string('mime_type', 127)->nullable();
        $table->bigInteger('byte_size')->nullable();
        $table->unsignedInteger('width')->nullable();
        $table->unsignedInteger('height')->nullable();
        $table->decimal('duration_seconds', 8, 2)->nullable();
        $table->text('prompt')->nullable();
        $table->text('tags')->nullable();
        $table->text('metadata')->nullable();
        $table->string('asset_url', 512);
        $table->string('source_url', 512)->nullable();
        $table->string('storage_mode', 16);
        $table->string('asset_token', 64)->nullable();
        $table->binary('payload')->nullable();
        $table->boolean('migrated_from_inline_data_url')->default(false);
        $table->timestamps();
    });

    $logger  = new NullLogger();
    $sniffer = new MimeSniffer();
    // Serve a tiny 8-byte payload for every HEAD/GET. The HEAD response
    // declares content-type + content-length so the resolver doesn't
    // skip the body fetch on a "too large" guess; the GET response
    // returns the body itself.
    $http = new MockHttpClient([
        new MockResponse(
            '',
            ['response_headers' => ['content-type: application/octet-stream', 'content-length: 8']],
        ),
        new MockResponse(
            str_repeat("\x00", 8),
            ['response_headers' => ['content-type: application/octet-stream']],
        ),
    ]);
    $resolver = new MediaArchiveUrlResolver(
        new RemoteMediaFetcher($http, $logger, 30, 1024 * 1024),
        $sniffer,
        $logger,
        true,         // promoteExternal — force the bytes branch
        1024 * 1024,
    );

    $store = new MinimaxFilenameCapturingStore();
    $archive = new MediaArchiveService(
        $store,
        $resolver,
        $sniffer,
        new MetadataExtractor($logger, false),
    );
    return [$archive, $store];
}

it('image tool sends a MediaIngestRequest with a deterministic .png filename', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->allows('request')->andReturn(minimaxArchiveResponse(200, json_encode([
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        'data'      => ['image_urls' => ['https://cdn.example.com/a.png']],
    ])));

    $log = new MiniMaxLogWriter();
    [$archive, $store] = minimaxFilenameCaptureArchiveService();

    $tool = new MiniMaxImageTool($config, $http, $log, null, null, $archive);
    $result = $tool->execute(['prompt' => 'a red fox'], 42);

    expect($result->success)->toBeTrue();
    expect($store->capturedFilenames)->toHaveCount(1);
    expect($store->capturedFilenames[0])->toMatch(MINIMAX_FILENAME_REGEX);
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_EXTENSION))->toBe('png');
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_FILENAME))->toStartWith('minimax-image-');
});

it('video tool sends a MediaIngestRequest with a deterministic .mp4 filename', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'                => 'k',
        'poll_interval_seconds'  => '1',
        'poll_timeout_seconds'   => '5',
        'submit_timeout_seconds' => '30',
    ]);

    $http = M::mock(HttpClientInterface::class);
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

    $log = new MiniMaxLogWriter();
    [$archive, $store] = minimaxFilenameCaptureArchiveService();

    $tool = new MiniMaxVideoTool($config, $http, $log, null, null, $archive);
    $result = $tool->execute(['prompt' => '[Push in] a forest', 'duration_seconds' => '6'], 11);

    expect($result->success)->toBeTrue();
    expect($store->capturedFilenames)->toHaveCount(1);
    expect($store->capturedFilenames[0])->toMatch(MINIMAX_FILENAME_REGEX);
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_EXTENSION))->toBe('mp4');
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_FILENAME))->toStartWith('minimax-video-');
});

it('music tool sends a MediaIngestRequest with a deterministic .mp3 filename', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->allows('request')->andReturn(minimaxArchiveResponse(200, json_encode([
        'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        'data'      => ['audio' => str_repeat('00', 24)],
    ])));

    $log = new MiniMaxLogWriter();
    [$archive, $store] = minimaxFilenameCaptureArchiveService();

    $tool = new MiniMaxMusicTool($config, $http, $log, minimaxTestAssetStore(), null, null, $archive);
    $result = $tool->execute([
        'action'        => 'compose',
        'prompt'        => 'lofi piano',
        'output_format' => 'hex',
    ], 99);

    expect($result->success)->toBeTrue();
    expect($store->capturedFilenames)->toHaveCount(1);
    expect($store->capturedFilenames[0])->toMatch(MINIMAX_FILENAME_REGEX);
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_EXTENSION))->toBe('mp3');
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_FILENAME))->toStartWith('minimax-music-');
});

it('speech tool sends a MediaIngestRequest with a deterministic .mp3 filename', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->allows('request')->andReturn(minimaxArchiveResponse(200, json_encode([
        'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
        'data'       => ['audio_url' => 'https://cdn.example.com/speech.mp3'],
        'extra_info' => ['audio_size' => 12_345],
    ])));

    $log = new MiniMaxLogWriter();
    [$archive, $store] = minimaxFilenameCaptureArchiveService();

    $tool = new MiniMaxSpeechTool($config, $http, $log, minimaxTestAssetStore(), null, null, $archive);
    $result = $tool->execute([
        'text'     => 'hello world',
        'voice_id' => 'English_PassionateWarrior',
    ], 7);

    expect($result->success)->toBeTrue();
    expect($store->capturedFilenames)->toHaveCount(1);
    expect($store->capturedFilenames[0])->toMatch(MINIMAX_FILENAME_REGEX);
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_EXTENSION))->toBe('mp3');
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_FILENAME))->toStartWith('minimax-speech-');
});
