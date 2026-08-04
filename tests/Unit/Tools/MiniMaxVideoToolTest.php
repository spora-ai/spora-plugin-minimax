<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Helpers for building mocked Symfony HttpClient responses against the
 * H3 v2 endpoints (`/v2/video_generation`, `/v2/h3_context_ir`,
 * `/v2/video_regeneration`, `/v2/query/video_generation/{task_id}`).
 *
 * `function_exists` guards keep the helpers reusable from sibling test
 * files (e.g. `MiniMaxVideoToolContentLimitsTest`) without colliding.
 */
if (!function_exists('h3Response')) {
    function h3Response(int $status, string $body): ResponseInterface
    {
        $response = Mockery::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn($status);
        $response->allows('getContent')->andReturn($body);
        return $response;
    }
}

if (!function_exists('h3BodyMatches')) {
    /**
     * @param array<string, mixed> $opts
     * @param array<string, mixed> $expected
     */
    function h3BodyMatches(array $opts, array $expected): bool
    {
        if (!is_array($opts['json'] ?? null)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (($opts['json'][$key] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('h3ContentHas')) {
    /**
     * @param array<string, mixed> $opts
     */
    function h3ContentHas(array $opts, string $type, ?string $role = null): bool
    {
        $items = $opts['json']['content'] ?? null;
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? null) !== $type) {
                continue;
            }
            if ($role === null || ($item['role'] ?? null) === $role) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('h3SuccessTask')) {
    /**
     * @param array<string, mixed> $overrides
     */
    function h3SuccessTask(string $taskId, string $downloadUrl, array $overrides = []): array
    {
        return array_merge([
            'task' => [
                'id'        => $taskId,
                'model'     => 'MiniMax-H3',
                'status'    => 'succeeded',
                'content'   => ['url' => $downloadUrl],
                'task_type' => 'generation',
                'modality'  => 'video',
            ],
        ], $overrides);
    }
}

// ───────────────────────────────────────────────────────────────────────────
// 1. Dispatch / validation — no HTTP
// ───────────────────────────────────────────────────────────────────────────

it('returns an error when the API key is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http  = Mockery::mock(HttpClientInterface::class);
    $log   = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['prompt' => 'a forest'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('API key is not configured');
});

it('returns an error for an unknown action', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http  = Mockery::mock(HttpClientInterface::class);
    $log   = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['action' => 'party', 'prompt' => 'a forest'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Unknown video operation: party');
});

it('falls back to generate when action is absent (backward compat)', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '2',
    ]);

    $submitCalls = 0;
    $http = Mockery::mock(HttpClientInterface::class);
    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::any())
        ->andReturnUsing(function () use (&$submitCalls): ResponseInterface {
            $submitCalls++;
            return h3Response(200, json_encode(['task_id' => 'task-xyz']));
        });
    // Every poll returns "running" — the deadline hits before we ever reach succeeded.
    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(['task' => ['id' => 'task-xyz', 'status' => 'running']])));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a forest'], 1);

    expect($result->success)->toBeFalse()             // poll timed out
        ->and($result->data['task_id'])->toBe('task-xyz')
        ->and($result->data['timed_out'])->toBeTrue()
        ->and($submitCalls)->toBe(1);
});

it('rejects duration_seconds below 4', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoTool($config, Mockery::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a forest', 'duration_seconds' => 3], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('duration_seconds');
});

it('rejects duration_seconds above 15', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoTool($config, Mockery::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a forest', 'duration_seconds' => 16], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('duration_seconds');
});

it('rejects an unknown resolution value', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoTool($config, Mockery::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a forest', 'resolution' => '4K'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('resolution')
        ->and($result->content)->toContain('768P');
});

it('rejects resume without task_id', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoTool($config, Mockery::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['action' => 'resume'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('task_id');
});

it('rejects regenerate without task_id', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoTool($config, Mockery::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['action' => 'regenerate'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('task_id');
});

it('rejects regenerate without base_video_url', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoTool($config, Mockery::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute([
        'action'  => 'regenerate',
        'task_id' => 'task-abc',
        'prompt'  => 'a forest',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('base_video_url');
});

// ───────────────────────────────────────────────────────────────────────────
// 2. Submit body shape — content[] construction + ratio rules
// ───────────────────────────────────────────────────────────────────────────

it('generate submits content[] with a single text item and ratio=16:9 for text-only', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::on(
            fn($opts) => h3BodyMatches($opts, [
                'model'      => 'MiniMax-H3',
                'duration'   => 6,
                'resolution' => '768P',
                'ratio'      => '16:9',
            ])
            && is_array($opts['json']['content'] ?? null)
            && count($opts['json']['content']) === 1
            && ($opts['json']['content'][0]['type'] ?? null) === 'text'
            && ($opts['json']['content'][0]['text'] ?? null) === 'a forest at dawn',
        ))
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-1'])));

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(h3SuccessTask('task-1', 'https://minimax.example/output.mp4'))));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a forest at dawn'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['task_id'])->toBe('task-1')
        ->and($result->data['download_url'])->toBe('https://minimax.example/output.mp4');
});

it('generate with first_frame_image submits content[]=[text, image_url: first_frame] and ratio=adaptive', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::on(
            fn($opts) => ($opts['json']['ratio'] ?? null) === 'adaptive'
                && h3ContentHas($opts, 'image_url', 'first_frame'),
        ))
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-2'])));

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(h3SuccessTask('task-2', 'https://minimax.example/i2v.mp4'))));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute([
        'prompt'            => '[Push in] the fox looks up',
        'first_frame_image' => 'https://cdn.example.com/fox.png',
        'aspect_ratio'      => '16:9',  // LLM supplied a concrete ratio — tool must force adaptive
    ], 1);

    expect($result->success)->toBeTrue();
});

it('generate with reference_images submits content[]=[text, image_url: reference_image]', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::on(
            fn($opts) => ($opts['json']['ratio'] ?? null) === 'adaptive'
                && h3ContentHas($opts, 'image_url', 'reference_image'),
        ))
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-3'])));

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(h3SuccessTask('task-3', 'https://minimax.example/r2v.mp4'))));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute([
        'prompt'           => 'cinematic alley scene',
        'reference_images' => ['https://cdn.example.com/char-a.png', 'https://cdn.example.com/char-b.png'],
        'aspect_ratio'     => 'adaptive',  // explicit — r2v honours concrete ratios by default
    ], 1);

    expect($result->success)->toBeTrue();
});

it('text-only generate falls back to 16:9 when LLM supplies aspect_ratio=adaptive', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::on(
            fn($opts) => ($opts['json']['ratio'] ?? null) === '16:9',
        ))
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-adaptive'])));

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(h3SuccessTask('task-adaptive', 'https://minimax.example/output.mp4'))));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a forest', 'aspect_ratio' => 'adaptive'], 1);

    expect($result->success)->toBeTrue();
});

it('generate defaults resolution to 768P when the LLM omits resolution', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '2',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::on(
            fn($opts) => ($opts['json']['resolution'] ?? null) === '768P',
        ))
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-4'])));

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(['task' => ['id' => 'task-4', 'status' => 'running']])));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $tool->execute(['prompt' => 'a forest'], 1);

    expect(true)->toBeTrue();
});

// ───────────────────────────────────────────────────────────────────────────
// 3. Poll-loop outcomes — succeeded / failed / cancelled / timed_out
// ───────────────────────────────────────────────────────────────────────────

it('returns success with download_url on poll=succeeded', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::any())
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-ok'])));

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(h3SuccessTask('task-ok', 'https://minimax.example/clip.mp4', [
            'task' => [
                'id'         => 'task-ok',
                'model'      => 'MiniMax-H3',
                'status'     => 'succeeded',
                'content'    => ['url' => 'https://minimax.example/clip.mp4'],
                'task_type'  => 'generation',
                'modality'   => 'video',
                'resolution' => '2K',
                'duration'   => 5,
            ],
        ]))));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a forest', 'resolution' => '2K'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['task_id'])->toBe('task-ok')
        ->and($result->data['download_url'])->toBe('https://minimax.example/clip.mp4')
        ->and($result->data['resolution'])->toBe('2K');
});

it('returns a failed ToolResult when the upstream reports task=failed', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::any())
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-bad'])));

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode([
            'task' => [
                'id'     => 'task-bad',
                'status' => 'failed',
                'error'  => ['code' => '1026', 'message' => 'video description contains sensitive content'],
            ],
        ])));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'unsafe prompt'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('1026')
        ->and($result->content)->toContain('sensitive content');
});

it('returns success=false with task_id and timed_out=true when poll_timeout elapses', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '3',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::any())
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-slow'])));

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(['task' => ['id' => 'task-slow', 'status' => 'running']])));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a slow forest'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->data['task_id'])->toBe('task-slow')
        ->and($result->data['timed_out'])->toBeTrue()
        ->and($result->data['status'])->toBe('still_running')
        ->and($result->content)->toContain('task_id=task-slow')
        ->and($result->content)->toContain('still running on MiniMax');
});

it('resume polls only — does not re-submit', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    // Resume must NOT call POST /v2/video_generation.
    $http->shouldNotReceive('request')
        ->with('POST', Mockery::any(), Mockery::any());

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(h3SuccessTask('task-resume', 'https://minimax.example/resumed.mp4'))));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['action' => 'resume', 'task_id' => 'task-resume'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['task_id'])->toBe('task-resume');
});

// ───────────────────────────────────────────────────────────────────────────
// 4. enhance_prompt (H3-Context-IR)
// ───────────────────────────────────────────────────────────────────────────

it('enhance_prompt submits to /v2/h3_context_ir and returns enhanced_prompt in data', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v2/h3_context_ir', Mockery::on(
            fn($opts) => is_array($opts['json']['content'] ?? null)
                && ($opts['json']['content'][0]['type'] ?? null) === 'text',
        ))
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-ir'])));

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode([
            'task' => [
                'id'         => 'task-ir',
                'status'     => 'succeeded',
                'task_type'  => 'h3_context_ir',
                'modality'   => 'text',
                'content'    => ['prompt' => '[Shot 1] Cinematic close-up of a red fox in a snowy forest, breath fogging in cold air.'],
                'usage'      => ['total_tokens' => 100, 'prompt_tokens' => 50, 'completion_tokens' => 50],
            ],
        ])));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute([
        'action' => 'enhance_prompt',
        'prompt' => 'a red fox in snow',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['task_id'])->toBe('task-ir')
        ->and($result->data['task_type'])->toBe('h3_context_ir')
        ->and($result->data['enhanced_prompt'])->toContain('Cinematic');
});

// ───────────────────────────────────────────────────────────────────────────
// 5. regenerate (no DB lookup — replays args + base_video_url)
// ───────────────────────────────────────────────────────────────────────────

it('regenerate rebuilds content[] from arguments and appends base_video at resolution=2K', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v2/video_regeneration', Mockery::on(
            fn($opts) => h3BodyMatches($opts, [
                'model'      => 'MiniMax-H3',
                'resolution' => '2K',
            ])
            && h3ContentHas($opts, 'text', null)
            && h3ContentHas($opts, 'image_url', 'first_frame')
            && h3ContentHas($opts, 'video_url', 'base_video'),
        ))
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-regen'])));

    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(h3SuccessTask('task-regen', 'https://minimax.example/regen-2k.mp4', [
            'task' => [
                'id'         => 'task-regen',
                'model'      => 'MiniMax-H3',
                'status'     => 'succeeded',
                'task_type'  => 'regeneration',
                'modality'   => 'video',
                'content'    => ['url' => 'https://minimax.example/regen-2k.mp4'],
                'resolution' => '2K',
            ],
        ]))));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute([
        'action'           => 'regenerate',
        'task_id'          => 'task-original',
        'base_video_url'   => 'https://minimax.example/source-768p.mp4',
        'prompt'           => '[Push in] the fox looks up',
        'first_frame_image' => 'https://cdn.example.com/fox.png',
        'aspect_ratio'     => 'adaptive',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['task_id'])->toBe('task-regen')
        ->and($result->data['download_url'])->toBe('https://minimax.example/regen-2k.mp4')
        ->and($result->data['task_type'])->toBe('regeneration');
});

it('regenerate rejects data: URI for base_video_url', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoTool($config, Mockery::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute([
        'action'         => 'regenerate',
        'task_id'        => 'task-original',
        'base_video_url' => 'data:video/mp4;base64,AAA=',
        'prompt'         => 'a forest',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('base_video_url');
});
