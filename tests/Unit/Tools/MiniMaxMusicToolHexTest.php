<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool;
use Spora\Services\AssetStore;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

it('routes the hex payload through the injected LocalAssetStore (never a data: URI)', function () {
    // Production contract: the plugin's `register()` wires a real
    // LocalAssetStore so the chat UI never sees a `data:` URI (the
    // chat UI sanitizer truncates long base64 to `[data-omitted]`).
    // Mirror the speech tool's LocalAssetStore test for music.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $hex = bin2hex(random_bytes(32));

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/music_generation', Mockery::any())
        ->andReturn((function () use ($hex) {
            $response = Mockery::mock(Symfony\Contracts\HttpClient\ResponseInterface::class);
            $response->allows('getStatusCode')->andReturn(200);
            $response->allows('getContent')->andReturn(json_encode([
                'data'       => ['audio' => $hex, 'status' => 2],
                'extra_info' => ['music_duration' => 5000, 'music_sample_rate' => 44100, 'music_channel' => 2, 'bitrate' => 256000, 'music_size' => 32000],
                'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
            ]));
            $response->allows('toArray')->andReturn(json_decode(json_encode([
                'data'       => ['audio' => $hex, 'status' => 2],
                'extra_info' => ['music_duration' => 5000, 'music_sample_rate' => 44100, 'music_channel' => 2, 'bitrate' => 256000, 'music_size' => 32000],
                'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
            ]), true));
            return $response;
        })());

    $log = new MiniMaxLogWriter();

    $tmp = sys_get_temp_dir() . '/minimax-music-local-asset-' . bin2hex(random_bytes(4));
    $local = new Spora\Services\LocalAssetStore(
        new Spora\Core\Paths($tmp),
        new Spora\Core\SecurityManager(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
        50 * 1024 * 1024,
    );

    // The configured AssetStore must NOT be touched — the LocalAssetStore
    // bypass sidesteps `asset_store.mode` (which would otherwise inline
    // the small payload as `data:audio/mpeg;base64,…`).
    $assetStore = Mockery::mock(AssetStore::class);
    $assetStore->shouldNotReceive('store');

    $tool = new MiniMaxMusicTool($config, $http, $log, $assetStore);
    $tool->setLocalAssetStore($local);
    $result = $tool->execute(['action' => 'compose', 'lyrics' => '[Verse]\ntest', 'output_format' => 'hex'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->not->toContain('data:audio/mpeg;base64,')
        ->and($result->content)->not->toContain('[data-omitted]')
        ->and($result->data['asset_url'])->toStartWith('/api/v1/assets/');

    $entries = glob($tmp . '/storage/assets/*') ?: [];
    expect($entries)->toHaveCount(1)
        ->and(pathinfo($entries[0], PATHINFO_EXTENSION))->toBe('mp3');
});

it('uses a longer timeout setting for the compose operation than lyrics', function () {
    // Just verifies the per-op timeout setting keys are wired up.
    expect(Spora\Plugins\MiniMax\Support\MiniMaxSettings::timeoutSeconds('music', 'http_timeout_seconds', []))
        ->toBe(180)
        ->and(Spora\Plugins\MiniMax\Support\MiniMaxSettings::timeoutSeconds('music', 'http_timeout_seconds_lyrics', []))
        ->toBe(30);
});
