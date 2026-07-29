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
        ->and($result->content)->toContain('Echo the `<audio>` element above verbatim')
        ->and($result->data['audio_url'])->toBe('https://cdn.example/speech.mp3')
        ->and($result->data['asset_mode'])->toBeNull();
});

it('decodes a hex payload and routes it through the AssetStore', function () {
    // The configured AssetStore is the fallback path — only reached
    // when LocalAssetStore is not wired (custom factory wiring). The
    // `embedHex` trait helper writes a `data:` URI when the configured
    // mode produces one (this test mocks the AssetStore to return
    // `data:` mode explicitly). The hard invariant: even in this
    // fallback path, the renderer surfaces a failure (success=false)
    // instead of silently shipping a `data:` URL to the LLM — see the
    // `fails loudly when a data: URL leaks through` test for the
    // primary contract.
    $fixture = MinimaxFixtures::speechHexPayload();

    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->allows('request')
        ->andReturn(minimaxMockResponse(200, json_encode($fixture['response'])));

    $log = new MiniMaxLogWriter();
    $assetStore = M::mock(AssetStore::class);
    $assetStore->allows('store')
        ->andReturn(new AssetReference('data:audio/mpeg;base64,AAA', 'data_url'));

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute($fixture['request'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('data: URI')
        ->and($result->content)->toContain('LocalAssetStore');
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
        ->and($result->content)->toContain('Echo the `<audio>` element above verbatim')
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
        ->and($result->content)->toContain('Echo the `<audio>` element above verbatim')
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

it('fails loudly (success=false) when a `data:` URL leaks through (LocalAssetStore wiring missing)', function () {
    // Hard invariant: the speech tool must never emit a `data:` URL.
    // The only path that produces one in code is `embedHex` against the
    // configured AssetStore, which fires when LocalAssetStore is not
    // wired. Production wires it via `MiniMaxPlugin::register()`; this
    // test simulates a misconfigured deployment and asserts the
    // tool surfaces a clear failure to the orchestrator (rather than
    // papering over with a stale instruction). The framework's
    // try/catch in MiniMaxToolSupport::run() converts the
    // LogicException into a failed ToolResult so a misconfigured
    // deployment surfaces a clear "data: URI" message to the LLM
    // instead of a silent broken `<audio>` tag.
    $fixture = MinimaxFixtures::speechHexPayload();

    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->allows('request')->andReturn(minimaxMockResponse(200, json_encode($fixture['response'])));

    $log = new MiniMaxLogWriter();
    $assetStore = M::mock(AssetStore::class);
    $assetStore->allows('store')
        ->andReturn(new AssetReference('data:audio/mpeg;base64,LEAK', 'data_url'));

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute($fixture['request'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('data: URI')
        ->and($result->content)->toContain('LocalAssetStore');
});

it('routes a CDN URL through the short-lived-URL instruction (no archive rewrite)', function () {
    // Lock in the other branch of the V14 fix: when the resolved URL
    // is the upstream MiniMax CDN URL (no MediaArchive swap, no
    // LocalAssetStore path), the instruction must say "short-lived
    // CDN URL" — not "/api/v1/assets/..." which would tell the next
    // turn to write a tag that 404s an hour later.
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')->andReturn(minimaxMockResponse(200, json_encode([
        'data'       => ['audio_url' => 'https://cdn.minimax.io/speech/abc.mp3', 'status' => 2, 'ced' => ''],
        'extra_info' => ['audio_length' => 1000, 'audio_size' => 12_345, 'usage_characters' => 50, 'audio_format' => 'mp3'],
        'base_resp'  => ['status_code' => 0, 'status_msg' => 'success'],
    ])));

    $log = new MiniMaxLogWriter();
    $assetStore = M::mock(AssetStore::class);
    $assetStore->shouldNotReceive('store');

    $tool = new MiniMaxSpeechTool($config, $http, $log, $assetStore);
    $result = $tool->execute(['text' => 'Hello world'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('https://cdn.minimax.io/speech/abc.mp3')
        ->and($result->content)->toContain('short-lived MiniMax CDN URL')
        ->and($result->content)->not->toContain('/api/v1/assets/<token>.mp3')
        ->and($result->content)->not->toContain('[data-omitted]');
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
 * The `voices` op hits `POST /v1/get_voice` (see
 * https://platform.minimax.io/docs/api-reference/voice-management-get)
 * with a body of `{"voice_type": "system"}` — the *only* field the
 * MiniMax upstream accepts. The response carries one or more of
 * `system_voice[]`, `voice_cloning[]`, and `voice_generation[]`. The
 * LLM's `voice_id` / `language` / `gender` filters are applied
 * client-side over `voice_name` + flattened `description[]` because
 * MiniMax does not expose server-side filters for those fields.
 */
it('voices operation POSTs to /v1/get_voice with the documented envelope', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with(
            'POST',
            'https://api.minimax.io/v1/get_voice',
            M::on(static function (array $opts): bool {
                // The MiniMax envelope accepts exactly one body field
                // — `voice_type` — and defaults it to "system" when the
                // LLM omits it. Asserting the body shape here proves
                // the worker builds the upstream request correctly.
                $body = $opts['json'] ?? null;
                return is_array($body)
                    && $body === ['voice_type' => 'system'];
            }),
        )
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'   => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice' => [
                [
                    'voice_id'    => 'English_PassionateWarrior',
                    'voice_name'  => 'Passionate Warrior',
                    'description' => ['A confident, energetic male voice in standard English.'],
                ],
                [
                    'voice_id'    => 'English_Graceful_Lady',
                    'voice_name'  => 'Graceful Lady',
                    'description' => ['A calm, mature female narrator in standard English.'],
                ],
                [
                    'voice_id'    => 'Italian_Narrator',
                    'voice_name'  => 'Italian Narrator',
                    'description' => ['A steady, mature male narrator in standard Italian.'],
                ],
            ],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['action' => 'voices'], 1);

    expect($result->success)->toBeTrue()
        // Markdown bullet list with backtick-quoted voice_ids so the
        // LLM can copy them verbatim into a follow-up synthesize call.
        ->and($result->content)->toContain('`English_PassionateWarrior`')
        ->and($result->content)->toContain('`English_Graceful_Lady`')
        ->and($result->content)->toContain('`Italian_Narrator`')
        // The description cue (language + gender + character) must
        // ride along so the LLM can pick the right voice from the
        // chat transcript alone.
        ->and($result->content)->toContain('Passionate Warrior')
        ->and($result->content)->toContain('Italian')
        ->and($result->content)->toContain('female narrator')
        // Hint steers the next call: pass `text` + the chosen voice_id
        // and omit `action` (default is synthesize).
        ->and($result->content)->toContain('minimax_speech(text:')
        // Structured payload is JSON-serialisable through ToolResult.data.
        ->and($result->data['count'])->toBe(3)
        ->and($result->data['voice_type'])->toBe('system')
        ->and($result->data['total'])->toBe(3);
});

it('voices operation forwards voice_type: "all" to upstream and merges buckets', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with(
            'POST',
            'https://api.minimax.io/v1/get_voice',
            M::on(static fn(array $opts): bool => ($opts['json']['voice_type'] ?? null) === 'all'),
        )
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'        => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice'     => [
                ['voice_id' => 'English_PassionateWarrior', 'voice_name' => 'Passionate Warrior', 'description' => ['male, English.']],
            ],
            'voice_cloning'    => [
                ['voice_id' => 'cloned-abc', 'voice_name' => 'Cloned', 'description' => ['My cloned voice.']],
            ],
            'voice_generation' => [
                ['voice_id' => 'ttv-2025', 'voice_name' => 'Generated', 'description' => ['Voice generated from text prompt.']],
            ],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['action' => 'voices', 'voice_type' => 'all'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('`English_PassionateWarrior`')
        ->and($result->content)->toContain('`cloned-abc`')
        ->and($result->content)->toContain('`ttv-2025`')
        ->and($result->data['count'])->toBe(3)
        ->and($result->data['voice_type'])->toBe('all');

    // _source tags so callers can disambiguate duplicates across
    // buckets when voice_type is "all".
    $sources = array_column($result->data['voices'], '_source');
    expect($sources)->toContain('system_voice')
        ->and($sources)->toContain('voice_cloning')
        ->and($sources)->toContain('voice_generation');
});

it('voices operation filters by language client-side (substring match over description)', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    // Upstream should NOT receive a `language` query param — MiniMax's
    // body accepts only `voice_type`. The worker POSTs the full
    // library; the client filters the result.
    $http->expects('request')
        ->with(
            'POST',
            'https://api.minimax.io/v1/get_voice',
            M::on(static function (array $opts): bool {
                $body = $opts['json'] ?? [];
                return ($body['voice_type'] ?? null) === 'system'
                    && !isset($body['language'])
                    && !isset($body['gender']);
            }),
        )
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice' => [
                ['voice_id' => 'English_PassionateWarrior', 'voice_name' => 'Passionate Warrior', 'description' => ['A confident male voice in standard English.']],
                ['voice_id' => 'German_FriendlyMan',        'voice_name' => 'Friendly Man',        'description' => ['A friendly middle-aged male voice in standard German.']],
                ['voice_id' => 'Italian_Narrator',          'voice_name' => 'Italian Narrator',    'description' => ['A steady male narrator in standard Italian.']],
                ['voice_id' => 'Japanese_Lively_Youth',     'voice_name' => 'Lively Youth',        'description' => ['A bright young male voice in standard Japanese.']],
            ],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['action' => 'voices', 'language' => 'German'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('`German_FriendlyMan`')
        ->and($result->content)->not->toContain('`English_PassionateWarrior`')
        ->and($result->content)->not->toContain('`Italian_Narrator`')
        ->and($result->content)->not->toContain('`Japanese_Lively_Youth`')
        ->and($result->data['count'])->toBe(1)
        ->and($result->data['total'])->toBe(4)
        ->and($result->data['after_filter'])->toBe(1);
});

it('voices operation filters by gender client-side (substring match over description)', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/get_voice', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice' => [
                ['voice_id' => 'English_PassionateWarrior', 'voice_name' => 'PW', 'description' => ['male, English.']],
                ['voice_id' => 'English_Graceful_Lady',      'voice_name' => 'GL', 'description' => ['female, English.']],
                ['voice_id' => 'English_Soft_Girl',          'voice_name' => 'SG', 'description' => ['young female, English.']],
            ],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['action' => 'voices', 'gender' => 'female'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('`English_Graceful_Lady`')
        ->and($result->content)->toContain('`English_Soft_Girl`')
        ->and($result->content)->not->toContain('`English_PassionateWarrior`')
        ->and($result->data['count'])->toBe(2);
});

it('voices operation applies limit as a client-side cap', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $upstream = [];
    for ($i = 1; $i <= 10; $i++) {
        $upstream[] = [
            'voice_id'    => sprintf('English_Voice_%02d', $i),
            'voice_name'  => sprintf('Voice %02d', $i),
            'description' => ['English.'],
        ];
    }

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/get_voice', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice' => $upstream,
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['action' => 'voices', 'limit' => 3], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['count'])->toBe(3)
        ->and($result->data['total'])->toBe(10)
        // Rendered bullet count matches the cap.
        ->and(substr_count($result->content, "\n- "))->toBe(3);
});

it('voices operation with no filters returns the full library', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/get_voice', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice' => [
                ['voice_id' => 'A', 'voice_name' => 'A', 'description' => ['English.']],
                ['voice_id' => 'B', 'voice_name' => 'B', 'description' => ['German.']],
            ],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['action' => 'voices'], 1);

    // The whole point of "no filters" being allowed: the LLM can call
    // voices() with no args to get the entire library, then iterate.
    expect($result->success)->toBeTrue()
        ->and($result->data['count'])->toBe(2);
});

it('voices operation with filters that match nothing returns a "narrow your filter" hint', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/get_voice', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice' => [
                ['voice_id' => 'A', 'voice_name' => 'A', 'description' => ['English.']],
            ],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute([
        'action'   => 'voices',
        'language' => 'Klingon',
    ], 1);

    expect($result->success)->toBeTrue()
        // Filter-excluded case: bucket has voices, the filter rejected them all.
        // Leading line is distinct from the empty-bucket case so the LLM
        // can tell the difference.
        ->and($result->content)->toContain('No voices matched your filter.')
        ->and($result->content)->toContain('Drop the filter')
        ->and($result->content)->toContain('language contains "Klingon"')
        ->and($result->data['count'])->toBe(0)
        ->and($result->data['total'])->toBe(1);
});

it('voices operation with an empty upstream bucket renders the "No voices available" message, not the filter hint', function () {
    // Distinguishes two cases the previous wording conflated:
    //   - filter excluded everything (covered above)
    //   - bucket is empty on this account (this test)
    // Different fix paths for each, so the leading line must differ.
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/get_voice', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'        => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice'     => [],
            'voice_cloning'    => [],   // user hasn't cloned anything yet
            'voice_generation' => [],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['action' => 'voices'], 1);

    expect($result->success)->toBeTrue()
        // "No voices available" — not the misleading "No voices matched" the
        // previous wording rendered for this case.
        ->and($result->content)->toContain('No voices available.')
        ->and($result->content)->not->toContain('No voices matched your filter')
        ->and($result->content)->toContain('voice_type="system"')
        ->and($result->content)->toContain('Confirm the `api_key` setting')
        ->and($result->data['count'])->toBe(0)
        ->and($result->data['total'])->toBe(0);
});

it('voices operation with an empty voice_cloning bucket explains the bucket semantics', function () {
    // voice_cloning and voice_generation are user-populated. An empty
    // response there is the default state, not a filter mismatch — the
    // message must point the operator at voice_type="system" instead.
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/get_voice', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'        => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice'     => [
                ['voice_id' => 'English_PassionateWarrior', 'voice_name' => 'PW', 'description' => ['English.']],
            ],
            'voice_cloning'    => [],   // empty
            'voice_generation' => [],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['action' => 'voices', 'voice_type' => 'voice_cloning'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('No voices available.')
        ->and($result->content)->toContain('voice_type="voice_cloning"')
        ->and($result->content)->toContain('user-populated bucket')
        ->and($result->content)->toContain('Switch `voice_type` to `system`')
        ->and($result->data['total'])->toBe(0);
});

it('voices operation accepts voice_id exact match against a single upstream entry', function () {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/get_voice', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice' => [
                ['voice_id' => 'English_PassionateWarrior', 'voice_name' => 'PW', 'description' => ['English, male.']],
                ['voice_id' => 'Italian_Narrator',          'voice_name' => 'IN', 'description' => ['Italian, male.']],
            ],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    $result = $tool->execute(['action' => 'voices', 'voice_id' => 'Italian_Narrator'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('`Italian_Narrator`')
        ->and($result->content)->not->toContain('`English_PassionateWarrior`')
        ->and($result->data['count'])->toBe(1);
});

it('voice_id short-circuits language / gender filters (other filters ignored when voice_id is set)', function () {
    // Locks in the SKILL.md contract: voice_id is an exact match and
    // takes precedence over language / gender. Without the short-circuit
    // a stale `language` filter would hide an otherwise available voice
    // (e.g. the Agent suspects a voice is renamed and passes a leftover
    // language needle from a prior call).
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/get_voice', M::any())
        ->andReturn(minimaxMockResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'system_voice' => [
                ['voice_id' => 'Italian_Narrator',         'voice_name' => 'IN', 'description' => ['Italian, male.']],
                ['voice_id' => 'English_PassionateWarrior', 'voice_name' => 'PW', 'description' => ['English, male.']],
            ],
        ])));

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxSpeechTool($config, $http, $log, M::mock(AssetStore::class));
    // voice_id matches Italian_Narrator, but language="English" would
    // (under AND semantics) exclude it. The contract is "other
    // filters ignored when voice_id is set" — short-circuit applies.
    $result = $tool->execute([
        'action'   => 'voices',
        'voice_id' => 'Italian_Narrator',
        'language' => 'English',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('`Italian_Narrator`')
        ->and($result->data['count'])->toBe(1)
        ->and($result->data['after_filter'])->toBe(1);
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

/**
 * Regression guard for the doc/schema mismatch bug class.
 *
 * The orchestrator enforces `#[ToolParameter(required: ...)]` on the
 * wire schema before the tool's `execute()` runs. The unit tests bypass
 * the orchestrator, so they pass even when a parameter is marked
 * `required: true` but the docs / implementation say it's optional. To
 * catch that class of regression we read the ToolParameter attributes
 * directly via Reflection and assert the schema accepts a minimal
 * synthesize call (`{text: ...}` only — no speed / filename / voice_id).
 *
 * If anyone flips `required: ['synthesize']` back on for `speed` or
 * `filename`, this test fails loudly.
 */
it('synthesize parameter schema accepts a bare `{text}` call (no speed, filename, or voice_id)', function () {
    $tool = new MiniMaxSpeechTool(
        M::mock(ToolConfigService::class),
        M::mock(HttpClientInterface::class),
        new MiniMaxLogWriter(),
        M::mock(AssetStore::class),
    );

    $parameters = (new ReflectionClass($tool))->getAttributes(Spora\Tools\Attributes\ToolParameter::class);

    $requiredSynthesize = [];
    foreach ($parameters as $attribute) {
        $param = $attribute->newInstance();
        if ($param->required === true || (is_array($param->required) && in_array('synthesize', $param->required, true))) {
            $requiredSynthesize[] = $param->name;
        }
    }

    expect($requiredSynthesize)->toBe(
        ['text'],
        'Only `text` should be required for synthesize. speed, filename, voice_id, '
        . 'voice_type, language, gender, and limit must all be optional so the LLM can '
        . 'follow the SKILL.md table that documents them as "never" required with defaults.',
    );
});
