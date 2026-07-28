<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

function minimaxVideoResponse(int $status, string $body): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->allows('getStatusCode')->andReturn($status);
    $response->allows('getContent')->andReturn($body);
    return $response;
}

/**
 * Assert that the Symfony HttpClient options array carries a `json`
 * body with the keys the MiniMax video endpoint requires.
 * Returns true on match so it can be wrapped in `Mockery::on()`.
 */
function minimaxVideoBodyShape(array $opts, array $expected): bool
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

it('returns an error when the API key is missing', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    $result = $tool->execute(['prompt' => 'a forest'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('API key is not configured');
});

it('returns an error when duration_seconds is invalid', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    $result = $tool->execute(['prompt' => 'a forest', 'duration_seconds' => '30'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('duration_seconds');
});

it('returns an error for an unknown action', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    $result = $tool->execute(['action' => 'party', 'prompt' => 'a forest'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain("Unknown video operation: party");
});

it('falls back to generate when action is absent (backward compat)', function () {
    // Mirrors MiniMaxSpeechTool's pre-multi-op behavior. The legacy
    // `minimax_video(...)` calls that never passed `action` must
    // continue to land on generate, not fail with "unknown op".
    // We don't need to exercise the full poll/retrieve flow here —
    // just confirm `execute(['prompt' => ...])` reaches the submit
    // endpoint. The submit's task_id in the response is the signal.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '2',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $submitCalls = 0;
    $pollCalls   = 0;
    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::any())
        ->andReturnUsing(function () use (&$submitCalls): ResponseInterface {
            $submitCalls++;
            return minimaxVideoResponse(200, json_encode([
                'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
                'task_id'   => 'task-xyz',
            ]));
        });
    // Every poll returns Processing — the test only needs to prove
    // the dispatch reached generate (i.e. a POST happened); the
    // poll timeout fires fast and is swallowed.
    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::any())
        ->andReturnUsing(function () use (&$pollCalls): ResponseInterface {
            $pollCalls++;
            return minimaxVideoResponse(200, json_encode([
                'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
                'task_id'   => 'task-xyz',
                'status'    => 'Processing',
            ]));
        });

    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['prompt' => 'a forest'], 1);

    expect($result->success)->toBeFalse()             // poll timed out
        ->and($result->data['task_id'])->toBe('task-xyz')
        ->and($result->data['timed_out'])->toBeTrue()
        ->and($submitCalls)->toBe(1)                    // generate branch submitted
        ->and($pollCalls)->toBeGreaterThan(0);          // and polled at least once
});

it('rejects an unknown resolution enum value', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    // Lowercase 'p' — MiniMax wants uppercase P, exact match.
    $result = $tool->execute(['prompt' => 'a forest', 'resolution' => '1080p'], 1);
    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('resolution')
        ->and($result->content)->toContain('uppercase P');
});

it('rejects resolution 1080P with duration_seconds 10 for MiniMax-Hailuo-2.3', function () {
    // Cross-product matrix guard: this is the most expensive trap.
    // 1080P + 10s is silently rejected by upstream with 2013 after
    // the task is queued — burning quota. Validate client-side.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    $result = $tool->execute([
        'prompt'           => 'a forest',
        'duration_seconds' => '10',
        'resolution'       => '1080P',
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('1080P')
        ->and($result->content)->toContain('10')
        ->and($result->content)->toContain('MiniMax-Hailuo-2.3')
        ->and($result->content)->toContain('At 10s, only 768P is supported');
});

it('rejects duration_seconds 10 with model T2V-01-Director', function () {
    // T2V-01-Director doesn't support 10s at any resolution.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key' => 'k',
        'model'   => 'T2V-01-Director',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    $result = $tool->execute(['prompt' => 'a forest', 'duration_seconds' => '10'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('T2V-01-Director')
        ->and($result->content)->toContain('"10"');
});

it('accepts duration_seconds 10 with resolution 768P for MiniMax-Hailuo-2.3', function () {
    // Positive matrix: Hailuo-2.3 + 768P + 10s is the only valid 10s
    // combination. Verify the happy path is reachable end-to-end.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    // Submit with 768P + 10s.
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::on(
            fn($opts) => minimaxVideoBodyShape($opts, [
                'model'      => 'MiniMax-Hailuo-2.3',
                'prompt'     => '[Push in] a forest',
                'duration'   => 10,
                'resolution' => '768P',
            ]),
        ))
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-xyz',
        ])));

    $http->expects('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'      => 'task-xyz',
            'status'       => 'Success',
            'file_id'      => 'file-abc-123',
            'video_width'  => 1280,
            'video_height' => 720,
        ])));

    $http->expects('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'file' => [
                'file_id'      => 'file-abc-123',
                'bytes'        => 5_896_337,
                'filename'     => 'output_aigc.mp4',
                'purpose'      => 'video_generation',
                'download_url' => 'https://minimax.example/output.mp4',
            ],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute([
        'prompt'           => '[Push in] a forest',
        'duration_seconds' => '10',
        'resolution'       => '768P',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->data['duration'])->toBe(10)
        ->and($result->data['resolution'])->toBe('768P');
});

it('defaults resolution to 768P for MiniMax-Hailuo-2.3 when the LLM omits resolution', function () {
    // Hailuo-2.3 + 6s → 768P. Verifies that the effective resolution
    // lands in the submit body, not the user's empty string. Polls
    // return Processing so the test doesn't hang — we only need the
    // submit body assertion.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '2',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::on(
            fn($opts) => ($opts['json']['resolution'] ?? null) === '768P',
        ))
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-xyz',
        ])));

    // Without this the poll loop would hit an unmocked method and
    // Mockery's behaviour is to throw — fast, but noisier than
    // returning a clean Processing response that lets the deadline
    // hit on its own.
    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'   => 'task-xyz',
            'status'    => 'Processing',
        ])));

    $log = new MiniMaxLogWriter();
    $tool = new MiniMaxVideoTool($config, $http, $log);
    $tool->execute(['prompt' => 'a forest'], 1);

    expect(true)->toBeTrue();
});

it('defaults resolution to 720P for T2V-01-Director when the LLM omits resolution', function () {
    // T2V-01 family doesn't support 768P — falls back to 720P.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'model'                 => 'T2V-01-Director',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '2',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::on(
            fn($opts) => ($opts['json']['resolution'] ?? null) === '720P'
                && ($opts['json']['model'] ?? null) === 'T2V-01-Director',
        ))
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-xyz',
        ])));

    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'   => 'task-xyz',
            'status'    => 'Processing',
        ])));

    $log = new MiniMaxLogWriter();
    $tool = new MiniMaxVideoTool($config, $http, $log);
    $tool->execute(['prompt' => 'a forest'], 1);

    expect(true)->toBeTrue();
});

it('rejects an unknown model setting value', function () {
    // Operator-configured setting is wrong — MiniMax would silently
    // reject the request after the submit. Catch it client-side.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key' => 'k',
        'model'   => 'MiniMax-Hailuo-99',  // not in SUPPORTED_MODELS
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    $result = $tool->execute(['prompt' => 'a forest'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('MiniMax-Hailuo-99')
        ->and($result->content)->toContain('Allowed:');
});

it('rejects resume without task_id', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $tool = new MiniMaxVideoTool($config, $http, $log);

    $result = $tool->execute(['action' => 'resume'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('task_id');
});

it('returns success=false with task_id and timed_out=true when poll_timeout elapses', function () {
    // The V6 fix: a timed-out poll must surface the task_id so the
    // LLM can call resume on the next turn — the task is still
    // billable on MiniMax's side.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-slow',
        ])));

    // Every poll returns Processing — eventually the deadline hits.
    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'   => 'task-slow',
            'status'    => 'Processing',
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['prompt' => 'a forest'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('task_id=task-slow')
        ->and($result->content)->toContain('still running on MiniMax')
        ->and($result->data['task_id'])->toBe('task-slow')
        ->and($result->data['status'])->toBe('still_running')
        ->and($result->data['timed_out'])->toBeTrue();
});

it('surfaces base_resp.status_msg when the upstream returns Fail', function () {
    // Fail is a separate terminal state (not a timeout). The
    // standard MiniMaxToolSupport::run() try/catch converts the
    // thrown MiniMaxApiException into a ToolResult.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-bad',
        ])));

    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            // Per the upstream contract, the Fail-state response carries
            // the reason inside `base_resp.status_msg`. Spora's HTTP
            // wrapper strips `base_resp.status_code != 0` errors
            // *before* this code runs, so reaching `status: Fail` means
            // `base_resp.status_code == 0` and the message lives here.
            'base_resp' => ['status_code' => 0, 'status_msg' => 'sensitive content detected'],
            'task_id'   => 'task-bad',
            'status'    => 'Fail',
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['prompt' => 'a forest'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('MiniMax video generation failed')
        ->and($result->content)->toContain('sensitive content detected');
});

it('resume operation polls an existing task_id without re-submitting', function () {
    // The V8 fix: a timed-out generate can be resumed on the next
    // turn by passing the task_id. The resume operation must NOT
    // call /v1/video_generation again — it just polls.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    // Critical assertion: POST to /v1/video_generation MUST NOT
    // happen during resume. The submit endpoint is never called.
    $http->shouldNotReceive('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::any());

    // First poll returns Processing, second returns Success.
    $pollCalls = 0;
    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::any())
        ->andReturnUsing(function () use (&$pollCalls) {
            $pollCalls++;
            $isSecond = $pollCalls >= 2;
            return minimaxVideoResponse(200, json_encode([
                'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
                'task_id'   => 'task-resume',
                'status'    => $isSecond ? 'Success' : 'Processing',
                'file_id'   => $isSecond ? 'file-resume-xyz' : null,
                'video_width'  => $isSecond ? 1920 : null,
                'video_height' => $isSecond ? 1080 : null,
            ]));
        });

    $http->expects('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', Mockery::on(function ($opts) {
            return ($opts['query']['file_id'] ?? null) === 'file-resume-xyz';
        }))
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'file' => [
                'file_id'      => 'file-resume-xyz',
                'bytes'        => 5_896_337,
                'filename'     => 'output_aigc.mp4',
                'purpose'      => 'video_generation',
                'download_url' => 'https://minimax.example/resumed.mp4',
            ],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute([
        'action'  => 'resume',
        'task_id' => 'task-resume',
    ], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('https://minimax.example/resumed.mp4')
        ->and($result->data['task_id'])->toBe('task-resume')
        ->and($result->data['file_id'])->toBe('file-resume-xyz')
        ->and($pollCalls)->toBeGreaterThanOrEqual(2);
});

it('honours per-call poll_timeout_seconds override', function () {
    // The V7 fix: the LLM can dial the timeout up or down per
    // call. With a 1-second override, the deadline fires well
    // before the operator-configured 900 s default.
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        // Operator setting is high — must NOT win.
        'poll_timeout_seconds'  => '900',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-override',
        ])));

    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'   => 'task-override',
            'status'    => 'Processing',
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute([
        'prompt'                => 'a forest',
        'poll_timeout_seconds'  => 10,  // per-call override; operator default is 900
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->data['task_id'])->toBe('task-override')
        ->and($result->content)->toContain('within 10s');
});

it('polls the task status, calls file-retrieve, and embeds the download URL', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'                  => 'k',
        // Poll quickly: interval=1s, timeout=5s — the happy path doesn't wait
        // long, but the loop still has a real deadline.
        'poll_interval_seconds'    => '1',
        'poll_timeout_seconds'     => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    // 1. Start the task — returns a task_id with the canonical body
    // shape (model + prompt + duration + resolution).
    $http->expects('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::on(
            fn($opts) => minimaxVideoBodyShape($opts, [
                'model'      => 'MiniMax-Hailuo-2.3',
                'prompt'     => '[Push in] a forest',
                'duration'   => 6,
                'resolution' => '768P',
            ]),
        ))
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-xyz',
        ])));

    // 2. Poll — returns "Success" with file_id + dimensions.
    $http->expects('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::on(function ($opts) {
            return ($opts['query']['task_id'] ?? null) === 'task-xyz';
        }))
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'      => 'task-xyz',
            'status'       => 'Success',
            'file_id'      => 'file-abc-123',
            'video_width'  => 1920,
            'video_height' => 1080,
        ])));

    // 3. File retrieve — returns the download URL valid for 1 hour.
    $http->expects('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', Mockery::on(function ($opts) {
            return ($opts['query']['file_id'] ?? null) === 'file-abc-123';
        }))
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'file' => [
                'file_id'      => 'file-abc-123',
                'bytes'        => 5_896_337,
                'filename'     => 'output_aigc.mp4',
                'purpose'      => 'video_generation',
                'download_url' => 'https://minimax.example/output.mp4',
            ],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['prompt' => '[Push in] a forest'], 1);

    expect($result->success)->toBeTrue()
        ->and($result->content)->toContain('<video')
        ->and($result->content)->toContain('https://minimax.example/output.mp4')
        ->and($result->content)->toContain('width="1920"')
        ->and($result->content)->toContain('height="1080"')
        ->and($result->content)->toContain('file_id: file-abc-123')
        ->and($result->content)->toContain('1 hour')
        ->and($result->content)->toContain('Use the same video embed above')
        ->and($result->data['file_id'])->toBe('file-abc-123')
        ->and($result->data['task_id'])->toBe('task-xyz')
        ->and($result->data['download_url'])->toBe('https://minimax.example/output.mp4')
        ->and($result->data['width'])->toBe(1920)
        ->and($result->data['height'])->toBe(1080);
});

it('returns a failure when file-retrieve omits the download URL', function () {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $log = new MiniMaxLogWriter();

    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v1/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp' => ['status_code' => 0, 'status_msg' => 'ok'],
            'task_id'   => 'task-xyz',
        ])));
    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/query/video_generation', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'base_resp'    => ['status_code' => 0, 'status_msg' => 'success'],
            'task_id'      => 'task-xyz',
            'status'       => 'Success',
            'file_id'      => 'file-abc-123',
            'video_width'  => 1920,
            'video_height' => 1080,
        ])));
    $http->allows('request')
        ->with('GET', 'https://api.minimax.io/v1/files/retrieve', Mockery::any())
        ->andReturn(minimaxVideoResponse(200, json_encode([
            'file'      => ['file_id' => 'file-abc-123'],
            'base_resp' => ['status_code' => 0, 'status_msg' => 'success'],
        ])));

    $tool = new MiniMaxVideoTool($config, $http, $log);
    $result = $tool->execute(['prompt' => '[Push in] a forest'], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('did not return a download_url');
});
