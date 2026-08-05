<?php

declare(strict_types=1);

use Mockery as M;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoV1Tool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

if (!function_exists('v1Response')) {
    function v1Response(int $status, string $body): ResponseInterface
    {
        $response = M::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn($status);
        $response->allows('getContent')->andReturn($body);
        return $response;
    }
}

if (!function_exists('v1SuccessTask')) {
    /**
     * Build a v1 Success envelope. v1 returns `Success` (capital S) and
     * `base_resp.status_msg: 'success'` per the v1 OpenAPI spec, plus
     * `file_id` + `video_width` / `video_height` for the retrieve step.
     */
    function v1SuccessTask(string $taskId, string $fileId, string $downloadUrl = 'https://minimax.example/output.mp4', int $width = 1280, int $height = 720): array
    {
        return [
            'task_id'  => $taskId,
            'status'   => 'Success',
            'file_id'  => $fileId,
            'video_width'  => $width,
            'video_height' => $height,
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ];
    }
}

/**
 * Per-call work for the v1 tool. Validates the submit body, polls,
 * retrieves the download URL via /v1/files/retrieve, archives.
 */
it('submits the v1 video_generation body with model, prompt, duration and resolution', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', M::on(function ($opts) {
            return ($opts['json']['model'] ?? null) === 'MiniMax-Hailuo-2.3'
                && ($opts['json']['prompt'] ?? null) === 'a forest at dusk'
                && ($opts['json']['duration'] ?? null) === 6
                && ($opts['json']['resolution'] ?? null) === '768P'
                && ($opts['timeout'] ?? null) === 120;
        }))
        ->andReturn(v1Response(200, json_encode([
            'task_id'  => 'task-v1',
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', M::any())
        ->andReturn(v1Response(200, json_encode(v1SuccessTask('task-v1', 'fid-1'))));

    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', M::any())
        ->andReturn(v1Response(200, json_encode([
            'file' => ['download_url' => 'https://minimax.example/output.mp4'],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoV1Tool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute([
        'prompt'           => 'a forest at dusk',
        'duration_seconds' => '6',
        'resolution'       => '768P',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['task_id'])->toBe('task-v1')
        ->and($result->data['file_id'])->toBe('fid-1')
        ->and($result->data['download_url'])->toBe('https://minimax.example/output.mp4')
        ->and($result->content)->toContain('<video')
        ->and($result->content)->toContain('Echo the `<video>` element above verbatim');
});

it('rejects duration_seconds outside the v1 enum', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoV1Tool($config, M::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'hi', 'duration_seconds' => '30'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('duration_seconds must be one of');
});

it('rejects resolution outside the v1 enum', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoV1Tool($config, M::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'hi', 'resolution' => '144P'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('resolution must be one of');
});

it('rejects first_frame_image with a clear "i2v code path not yet shipped" message', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoV1Tool($config, M::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute([
        'prompt'            => 'animate this',
        'first_frame_image' => 'https://cdn.example.com/frame.png',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('i2v code path is not yet shipped');
});

it('rejects empty prompt', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoV1Tool($config, M::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => '   '], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('Prompt cannot be empty');
});

it('rejects oversized prompt', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoV1Tool($config, M::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => str_repeat('x', 2001)], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('2000-character MiniMax limit');
});

it('rejects the (1080P, 10s) combo with the actionable At-10s hint', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key' => 'k',
        'model'   => 'MiniMax-Hailuo-2.3',
    ]);

    $tool = new MiniMaxVideoV1Tool($config, M::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute([
        'prompt'           => 'orange sunset',
        'duration_seconds' => '10',
        'resolution'       => '1080P',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('At 10s, only 768P is supported');
});

it('rejects unknown model setting with the supported-models list', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key' => 'k',
        'model'   => 'MiniMax-Nonsense',
    ]);

    $tool = new MiniMaxVideoV1Tool($config, M::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a sunset'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('not a supported MiniMax v1 video model')
        ->and($result->content)->toContain('MiniMax-Hailuo-2.3');
});

it('rejects an i2v matrix-only model with a clear "not yet shipped" message', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key' => 'k',
        'model'   => 'I2V-01-live',
    ]);

    $tool = new MiniMaxVideoV1Tool($config, M::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a sunset'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('code path is not yet shipped');
});

it('resume polls only — does not re-submit', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = M::mock(HttpClientInterface::class);
    // Resume must NOT call POST /v1/video_generation.
    $http->shouldNotReceive('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', M::any());

    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', M::any())
        ->andReturn(v1Response(200, json_encode(v1SuccessTask('task-resume', 'fid-r'))));

    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', M::any())
        ->andReturn(v1Response(200, json_encode([
            'file' => ['download_url' => 'https://minimax.example/resumed.mp4'],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoV1Tool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['action' => 'resume', 'task_id' => 'task-resume'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['task_id'])->toBe('task-resume');
});

it('resume returns an error when task_id is missing', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoV1Tool($config, M::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['action' => 'resume'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('task_id is required');
});

it('returns a timed-out envelope with the task_id intact when poll_timeout elapses', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '3',
    ]);

    $http = M::mock(HttpClientInterface::class);
    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', M::any())
        ->andReturn(v1Response(200, json_encode([
            'task_id'  => 'task-slow',
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', M::any())
        ->andReturn(v1Response(200, json_encode([
            'status'  => 'Running',
            'task_id' => 'task-slow',
            'base_resp' => ['status_code' => 0, 'status_msg' => 'in progress'],
        ])));

    $tool = new MiniMaxVideoV1Tool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a slow forest'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->data['task_id'])->toBe('task-slow')
        ->and($result->data['timed_out'])->toBeTrue()
        ->and($result->content)->toContain('still running on MiniMax');
});

it('surfaces the upstream 400 error.message verbatim when MiniMax rejects the submit', function (): void {
    // Regression: the v1 endpoint returns MiniMax-style
    // `{"base_resp":{"status_code":2013,"status_msg":"..."}}` envelopes.
    // The v1 tool must surface "Invalid input parameters" (code 2013)
    // in the exception message so the LLM can pivot to a different
    // model instead of guessing why the call failed.
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = M::mock(HttpClientInterface::class);
    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', M::any())
        ->andReturn(v1Response(400, json_encode([
            'base_resp' => [
                'status_code' => 2013,
                'status_msg'  => 'Invalid input parameters, please check if the parameters are filled in as required',
            ],
        ])));

    $tool = new MiniMaxVideoV1Tool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a sunset'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('HTTP 400')
        ->and($result->content)->toContain('[2013]')
        ->and($result->content)->toContain('Invalid input parameters');
});

it('throws MiniMaxApiException with the upstream error.message when the submit body returns no task_id', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = M::mock(HttpClientInterface::class);
    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', M::any())
        ->andReturn(v1Response(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoV1Tool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a sunset'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('MiniMax returned no task_id');
});

it('uses MiniMax-Hailuo-2.3 as default when no model setting is configured', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = M::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', M::on(function ($opts) {
            return ($opts['json']['model'] ?? null) === 'MiniMax-Hailuo-2.3';
        }))
        ->andReturn(v1Response(200, json_encode([
            'task_id'  => 'task-default',
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', M::any())
        ->andReturn(v1Response(200, json_encode(v1SuccessTask('task-default', 'fid-d'))));

    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', M::any())
        ->andReturn(v1Response(200, json_encode([
            'file' => ['download_url' => 'https://minimax.example/d.mp4'],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoV1Tool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute(['prompt' => 'a sunset'], 1);

    expect($result->success)->toBeTrue();
});

it('routes malformed action through the dispatcher with a clear "Unknown video operation" error', function (): void {
    $config = M::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoV1Tool($config, M::mock(HttpClientInterface::class), new MiniMaxLogWriter());
    $result = $tool->execute(['action' => 'eat_pie', 'prompt' => 'x'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Unknown video operation: eat_pie");
});
