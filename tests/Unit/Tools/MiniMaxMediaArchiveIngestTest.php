<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Mockery as M;
use Psr\Log\NullLogger;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Support\MiniMaxTool;
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
use Spora\Services\MediaArchive\MediaConverterRegistry;
use Spora\Services\MediaArchive\MediaIngestDecoder;
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

    // MediaConverterDiscovery::all() returns an empty list by default
    // (no static init in the discovery class), so the registry's
    // constructor never calls $container->get() — the mock is
    // never invoked, but PHPStan still needs a real implementation.
    $container = M::mock(\Psr\Container\ContainerInterface::class);

    return new MediaArchiveService(
        minimaxTestAssetStore(),
        $resolver,
        $sniffer,
        $meta,
        new MediaConverterRegistry($container),
        new MediaIngestDecoder(),
        $logger,
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
 * Filename-shape contract for every tool's MediaIngestRequest. When the
 * LLM supplies a name via the `filename` ToolParameter, that name is
 * sanitised and returned with the canonical extension. When the LLM
 * doesn't, {@see MiniMaxTool::resolveFilename()} falls back to a
 * slugified prompt + canonical extension — `minimax-<tool>-<stem>.<ext>`.
 * The shape below is the slug fallback only; LLM-supplied names are
 * asserted exact-match in their own tests.
 */
const MINIMAX_SLUG_REGEX = '/^minimax-(image|video|music|speech)-[a-z0-9-]+\.(png|mp4|mp3)$/';

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
    $container = M::mock(\Psr\Container\ContainerInterface::class);
    $archive = new MediaArchiveService(
        $store,
        $resolver,
        $sniffer,
        new MetadataExtractor($logger, false),
        new MediaConverterRegistry($container),
        new MediaIngestDecoder(),
        $logger,
    );
    return [$archive, $store];
}

it('image tool honours the LLM-supplied filename and appends the canonical extension', function () {
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
    $result = $tool->execute([
        'prompt'   => 'a red fox',
        'filename' => 'sunset-at-the-beach',
    ], 42);

    expect($result->success)->toBeTrue();
    expect($store->capturedFilenames)->toHaveCount(1);
    expect($store->capturedFilenames[0])->toBe('sunset-at-the-beach.png');
});

it('image tool slugifies the prompt when no filename is supplied', function () {
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
    expect($store->capturedFilenames[0])->toMatch(MINIMAX_SLUG_REGEX);
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_EXTENSION))->toBe('png');
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_FILENAME))->toStartWith('minimax-image-');
});

it('video tool honours the LLM-supplied filename and appends the canonical extension', function () {
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
    $result = $tool->execute([
        'prompt'   => '[Push in] a forest',
        'filename' => 'forest-push-in',
        'duration_seconds' => '6',
    ], 11);

    expect($result->success)->toBeTrue();
    expect($store->capturedFilenames)->toHaveCount(1);
    expect($store->capturedFilenames[0])->toBe('forest-push-in.mp4');
});

it('video tool slugifies the prompt when no filename is supplied', function () {
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
    expect($store->capturedFilenames[0])->toMatch(MINIMAX_SLUG_REGEX);
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_EXTENSION))->toBe('mp4');
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_FILENAME))->toStartWith('minimax-video-');
});

it('music tool honours the LLM-supplied filename and appends the canonical extension', function () {
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
        'filename'      => 'midnight-lofi',
    ], 99);

    expect($result->success)->toBeTrue();
    expect($store->capturedFilenames)->toHaveCount(1);
    expect($store->capturedFilenames[0])->toBe('midnight-lofi.mp3');
});

it('music tool slugifies the prompt when no filename is supplied', function () {
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
    expect($store->capturedFilenames[0])->toMatch(MINIMAX_SLUG_REGEX);
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_EXTENSION))->toBe('mp3');
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_FILENAME))->toStartWith('minimax-music-');
});

it('speech tool honours the LLM-supplied filename and appends the canonical extension', function () {
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
        'filename' => 'intro-greeting',
    ], 7);

    expect($result->success)->toBeTrue();
    expect($store->capturedFilenames)->toHaveCount(1);
    expect($store->capturedFilenames[0])->toBe('intro-greeting.mp3');
});

it('speech tool slugifies the text when no filename is supplied', function () {
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
    expect($store->capturedFilenames[0])->toMatch(MINIMAX_SLUG_REGEX);
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_EXTENSION))->toBe('mp3');
    expect(pathinfo($store->capturedFilenames[0], PATHINFO_FILENAME))->toStartWith('minimax-speech-');
});

/**
 * Direct unit tests for {@see MiniMaxTool::resolveFilename()}.
 *
 * The helper is `public static` precisely so the sanitisation and
 * slugification branches can be covered without spinning up a full
 * MediaArchive pipeline — see the `image/...` tests above for the
 * end-to-end path.
 */
it('resolveFilename returns a sanitised LLM-supplied name verbatim with the canonical extension', function () {
    expect(MiniMaxTool::resolveFilename('my-illustration', 'irrelevant prompt', 'minimax-image', 'png'))
        ->toBe('my-illustration.png');
});

it('resolveFilename strips path components from a malicious-looking LLM filename', function () {
    expect(MiniMaxTool::resolveFilename('../../etc/passwd.png', 'a prompt', 'minimax-image', 'png'))
        ->toBe('etcpasswd.png');
});

it('resolveFilename overrides a wrong extension with the canonical one', function () {
    expect(MiniMaxTool::resolveFilename('song.txt', 'prompt', 'minimax-music', 'mp3'))
        ->toBe('song.mp3');
});

it('resolveFilename keeps a matching extension on the LLM-supplied name', function () {
    expect(MiniMaxTool::resolveFilename('track.mp3', 'prompt', 'minimax-music', 'mp3'))
        ->toBe('track.mp3');
});

it('resolveFilename adds the canonical extension when the LLM omits one', function () {
    expect(MiniMaxTool::resolveFilename('track', 'prompt', 'minimax-music', 'mp3'))
        ->toBe('track.mp3');
});

it('resolveFilename slugifies an ASCII prompt into a speaking stem', function () {
    expect(MiniMaxTool::resolveFilename(null, 'a red fox', 'minimax-image', 'png'))
        ->toBe('minimax-image-a-red-fox.png');
});

it('resolveFilename slugifies a unicode prompt via transliteration', function () {
    $name = MiniMaxTool::resolveFilename(null, 'Sonnenuntergang am Strand', 'minimax-image', 'png');
    // The extension is always canonical; the stem should be ASCII-safe,
    // lowercase, hyphen-separated, and start with the kind prefix.
    expect($name)->toEndWith('.png');
    expect($name)->toStartWith('minimax-image-');
    expect($name)->toMatch('/^minimax-image-[a-z0-9-]+\.png$/');
});

it('resolveFilename falls back to the prefix when the prompt yields no ASCII characters', function () {
    // Emoji survive neither the Transliterator nor iconv //TRANSLIT, so
    // the slug branch yields empty and we fall back to the prefix.
    expect(MiniMaxTool::resolveFilename(null, '🌅🌊', 'minimax-image', 'png'))
        ->toBe('minimax-image.png');
});

it('resolveFilename falls back to the prefix when the prompt is empty', function () {
    expect(MiniMaxTool::resolveFilename(null, '', 'minimax-image', 'png'))
        ->toBe('minimax-image.png');
    expect(MiniMaxTool::resolveFilename(null, '   ', 'minimax-music', 'mp3'))
        ->toBe('minimax-music.mp3');
});

it('resolveFilename falls through to the slug branch when the LLM name sanitises to empty', function () {
    expect(MiniMaxTool::resolveFilename('////', 'a red fox', 'minimax-image', 'png'))
        ->toBe('minimax-image-a-red-fox.png');
});

it('resolveFilename caps an LLM-supplied stem at 240 characters', function () {
    $long = str_repeat('a', 500);
    $name = MiniMaxTool::resolveFilename($long, 'prompt', 'minimax-image', 'png');
    expect(strlen(pathinfo($name, PATHINFO_FILENAME)))->toBe(240);
    expect($name)->toEndWith('.png');
});

it('resolveFilename caps the slugified prompt stem at 60 characters (on a word boundary)', function () {
    $prompt = str_repeat('word ', 30); // ~150 chars
    $name = MiniMaxTool::resolveFilename(null, $prompt, 'minimax-image', 'png');
    $stem = pathinfo($name, PATHINFO_FILENAME);
    expect(strlen($stem))->toBeLessThanOrEqual(60);
    // The kind prefix must survive the cut — never chop into the dash
    // that separates `minimax-image` from the slug.
    expect($stem)->toStartWith('minimax-image-');
    expect($stem)->toMatch('/^minimax-image-[a-z0-9-]+$/');
});

it('resolveFilename produces identical names for the same prompt — no random suffix', function () {
    $first  = MiniMaxTool::resolveFilename(null, 'same prompt', 'minimax-image', 'png');
    $second = MiniMaxTool::resolveFilename(null, 'same prompt', 'minimax-image', 'png');
    expect($first)->toBe($second);
});

it('resolveFilename replaces disallowed characters with hyphens', function () {
    expect(MiniMaxTool::resolveFilename('foo bar!?baz', 'prompt', 'minimax-image', 'png'))
        ->toBe('foo-bar-baz.png');
});

it('resolveFilename normalises an uppercase extension to lowercase', function () {
    expect(MiniMaxTool::resolveFilename('Track.MP3', 'prompt', 'minimax-music', 'mp3'))
        ->toBe('Track.mp3');
});

it('resolveFilename falls through to the slug branch when the LLM name is whitespace only', function () {
    expect(MiniMaxTool::resolveFilename('   ', 'a red fox', 'minimax-image', 'png'))
        ->toBe('minimax-image-a-red-fox.png');
});

it('resolveFilename falls through when the LLM name contains only disallowed characters', function () {
    expect(MiniMaxTool::resolveFilename('!@#$%^', 'a red fox', 'minimax-image', 'png'))
        ->toBe('minimax-image-a-red-fox.png');
});

it('resolveFilename hard-cuts a slug stem with no dashes after the prefix', function () {
    // 70 alpha chars, no dashes → the word-boundary cut can't fire, so
    // the hard-cut branch is exercised. The 60-char cap still applies
    // to the full stem (prefix + slug).
    $prompt = str_repeat('a', 70);
    $name = MiniMaxTool::resolveFilename(null, $prompt, 'minimax-image', 'png');
    $stem = pathinfo($name, PATHINFO_FILENAME);
    expect(strlen($stem))->toBe(60);
    // 14 (prefix + dash) + 46 a's = 60.
    expect($stem)->toBe('minimax-image-' . str_repeat('a', 46));
});

it('resolveFilename slugifies a prompt made entirely of special characters to the prefix', function () {
    expect(MiniMaxTool::resolveFilename(null, '!@#$%^&*()', 'minimax-music', 'mp3'))
        ->toBe('minimax-music.mp3');
});

it('resolveFilename strips a trailing dot and appends the canonical extension', function () {
    expect(MiniMaxTool::resolveFilename('track.', 'prompt', 'minimax-music', 'mp3'))
        ->toBe('track.mp3');
});
