<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tests\Support\MinimaxFixtures;
use Spora\Plugins\MiniMax\Tools\MiniMaxSpeechTool;
use Spora\Services\AssetReference;
use Spora\Services\AssetStore;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

function minimaxMockResponse(int $status, string $body): ResponseInterface
{
    $response = M::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn($status);
    $response->allows('getContent')->andReturn($body);
    if ($status >= 200 && $status < 300) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $response->allows('toArray')->andReturn($decoded);
        }
    }
    return $response;
}

it('embeds a CDN URL directly when audio_url is present', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/t2a_v2', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'data'       => ['audio_url' => 'https://cdn.example/speech.mp3', 'status' => 2, 'ced' => ''],
            'extra_info' => ['audio_length' => 1000, 'audio_size' => 12345, 'usage_characters' => 50, 'audio_format' => 'mp3'],
            'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $log = new MiniMaxLogWriter();
    $assetStore = M::mock(AssetStore::class);
    $assetStore->shouldNotReceive('store');

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute(['text' => 'Hello world'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<audio')
        ->and($result->content)->toContain('https://cdn.example/speech.mp3')
        ->and($result->content)->toContain('Use the same audio embed above')
        ->and($result->data['audio_url'])->toBe('https://cdn.example/speech.mp3')
        ->and($result->data['asset_mode'])->toBeNull();
});

it('decodes a hex payload and routes it through the AssetStore', function () {
    $fixture = MinimaxFixtures::speechHexPayload();

    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/t2a_v2', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode($fixture['response'])));

    $log = new MiniMaxLogWriter();
    $assetStore = M::mock(AssetStore::class);
    $assetStore->expects('store')
        ->once()
        ->with(
            M::on(static fn(string $bytes): bool => strlen($bytes) === 115350),
            'audio/mpeg',
            'speech.mp3',
        )
        ->andReturn(new AssetReference('data:audio/mpeg;base64,AAA', 'data_url'));

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute($fixture['request'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<audio')
        ->and($result->content)->toContain('data:audio/mpeg;base64,AAA')
        ->and($result->content)->toContain('Use the same audio embed above')
        ->and($result->data['asset_mode'])->toBe('data_url')
        ->and($result->data['audio_size'])->toBe(115350);
});

it('routes the hex payload to the local store when over the auto threshold', function () {
    $fixture = MinimaxFixtures::speechHexPayload();

    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')->andReturn(minimaxMockResponse(200, json_encode($fixture['response'])));

    $log = new MiniMaxLogWriter();
    $assetStore = M::mock(AssetStore::class);
    $assetStore->expects('store')
        ->once()
        ->andReturn(new AssetReference('/api/v1/assets/abc123def456.mp3', 'local'));

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute($fixture['request'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<audio')
        ->and($result->content)->toContain('/api/v1/assets/abc123def456.mp3')
        ->and($result->content)->toContain('Use the same audio embed above')
        ->and($result->data['asset_mode'])->toBe('local');
});

it('routes the hex payload through the injected LocalAssetStore regardless of payload size', function () {
    // Real LocalAssetStore backed by a per-test tmp directory. The plugin
    // wires `setLocalAssetStore` from PHP-DI in production; this exercises
    // the wire to prove the speech tool bypasses the configured
    // `asset_store.mode` (which would otherwise inline the small 115 KB
    // payload as `data:audio/mpeg;base64,…` and break the chat bubble).
    $fixture = MinimaxFixtures::speechHexPayload();

    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/t2a_v2', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode($fixture['response'])));

    $log = new MiniMaxLogWriter();

    $tmp = sys_get_temp_dir() . '/minimax-speech-local-asset-' . bin2hex(random_bytes(4));
    $local = new Spora\Services\LocalAssetStore(
        new Spora\Core\Paths($tmp),
        new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        50 * 1024 * 1024,
    );

    // AssetStore must NOT be touched — the whole point of the LocalAssetStore
    // bypass is that the configured AssetStore (which on a default install
    // is an AutoAssetStore with a 1 MiB threshold) is irrelevant.
    $assetStore = M::mock(AssetStore::class);
    $assetStore->shouldNotReceive('store');

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $tool->setLocalAssetStore($local);
    $result = $tool->execute($fixture['request'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<audio')
        ->and($result->content)->not->toContain('data:audio/mpeg;base64,')
        ->and($result->content)->toContain('Use the same audio embed above')
        ->and($result->data['asset_mode'])->toBe('local')
        ->and($result->data['asset_url'])->toStartWith('/api/v1/assets/');

    // The on-disk file should match what we fed in: same byte length,
    // written under <tmp>/storage/assets/. `Paths::storage('assets')`
    // appends `/storage/assets` to the basePath. LocalAssetStore derives
    // the filename extension from the `mime` hint we passed
    // (`audio/mpeg` → `.mp3`).
    $entries = glob($tmp . '/storage/assets/*') ?: [];
    expect($entries)->toHaveCount(1)
        ->and(pathinfo($entries[0], PATHINFO_EXTENSION))->toBe('mp3')
        ->and(filesize($entries[0]))->toBe(115350);
});

it('returns a clear failure on odd-length hex payload', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')->andReturn(minimaxMockResponse(200, json_encode([
        'data'       => ['audio' => 'abc', 'status' => 2, 'ced' => ''], // 3 chars, odd
        'extra_info' => ['audio_length' => 100, 'audio_size' => 1, 'usage_characters' => 1, 'audio_format' => 'mp3'],
        'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
    ])));

    $log = new MiniMaxLogWriter();
    $assetStore = M::mock(AssetStore::class);
    $assetStore->shouldNotReceive('store');

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute(['text' => 'Hello world'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('unsupported');
});

/**
 * `voices` operation
 * ------------------
 * The `voices` op hits `GET /v1/get_voice` (see
 * https://platform.minimax.io/docs/api-reference/voice-management-get)
 * with the optional filters (`voice_id`, `language`, `gender`, `limit`)
 * the LLM passed, and returns a Markdown bullet list of voice ids
 * the LLM can pick from before issuing the next `synthesize` call.
 */
it('voices operation returns a Markdown bullet list of MiniMax voice ids', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('GET', 'https://api.minimax.io/v1/get_voice', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'voice_list' => [
                ['voice_id' => 'English_PassionateWarrior', 'language' => 'en', 'gender' => 'male'],
                ['voice_id' => 'English_Graceful_Lady',      'language' => 'en', 'gender' => 'female'],
                ['voice_id' => 'Chinese_Mandarin_Warm_Girl', 'language' => 'zh', 'gender' => 'female'],
            ],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['action' => 'voices'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('English_PassionateWarrior')
        ->and($result->content)->toContain('English_Graceful_Lady')
        ->and($result->content)->toContain('Chinese_Mandarin_Warm_Girl')
        // Output is a Markdown bullet list — backtick-quoted voice_id
        // so the LLM can grep + copy verbatim.
        ->and($result->content)->toContain('`English_PassionateWarrior`')
        // The "use one" hint steers the LLM to the next call shape.
        ->and($result->content)->toContain('minimax_speech(')
        // Structured payload is JSON-serialisable through ToolResult.data.
        ->and($result->data['count'])->toBe(3);
});

it('voices operation forwards voice_id filter as a query param', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    // Symfony HttpClient's request() signature is (method, url, options).
    // We can't easily assert against `options` with a query-string hash
    // (Symfony builds the URL inline), so we assert the URL got the
    // query string appended. The grep on the URL keeps the test robust
    // against future URL encoding tweaks.
    // Symfony HTTP client receives query params under options['query'].
    // The MiniMax client passes them through verbatim from
    // MiniMaxHttpClient::getJson(), so asserting here proves the
    // doFetchVoices worker built the filter correctly.
    $http->expects('request')
        ->with(
            'GET',
            'https://api.minimax.io/v1/get_voice',
            M::on(static fn(array $opts): bool => ($opts['query']['voice_id'] ?? null) === 'English_Graceful_Lady'),
        )
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'voice_list' => [
                ['voice_id' => 'English_Graceful_Lady', 'language' => 'en', 'gender' => 'female'],
            ],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute([
        'action'   => 'voices',
        'voice_id' => 'English_Graceful_Lady',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('English_Graceful_Lady');
});

it('voices operation forwards language + gender as upstream filters', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with(
            'GET',
            'https://api.minimax.io/v1/get_voice',
            M::on(
                static fn(array $opts): bool =>
                ($opts['query']['language'] ?? null) === 'Japanese'
                && ($opts['query']['gender'] ?? null) === 'female',
            ),
        )
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'voice_list' => [],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute([
        'action'   => 'voices',
        'language' => 'Japanese',
        'gender'   => 'female',
    ], 1);

    // Empty list is a success — the LLM should narrow the filter,
    // not see an error.
    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('No voices matched');
});

it('omitting action falls back to synthesize (backward compat)', function () {
    // Pre-multi-op callers never passed `action`; the dispatcher must
    // default to `synthesize` so existing agent definitions keep
    // working. Lock the behaviour in.
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/t2a_v2', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
            'data'       => ['audio_url' => 'https://cdn.example.com/speech.mp3'],
            'extra_info' => ['audio_size' => 12_345],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['text' => 'Hello world'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('https://cdn.example.com/speech.mp3');
});
