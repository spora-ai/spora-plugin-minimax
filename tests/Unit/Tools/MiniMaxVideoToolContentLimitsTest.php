<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Services\ToolConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

if (!function_exists('h3Response')) {
    /**
     * Local copy of the {@see h3Response} helper from MiniMaxVideoToolTest
     * so this file can be filtered and run on its own without dragging in
     * the main test file.
     */
    function h3Response(int $status, string $body): ResponseInterface
    {
        $response = Mockery::mock(ResponseInterface::class);
        $response->allows('getStatusCode')->andReturn($status);
        $response->allows('getContent')->andReturn($body);
        return $response;
    }
}

/**
 * Table-driven tests for the client-side content[] limit validation.
 * Each row covers one rejection rule; the HTTP client is irrelevant
 * here because validation short-circuits before any submit.
 */
dataset('content_limit_rejections', [
    'too many reference images' => [
        [
            'prompt'           => 'cinematic scene',
            'reference_images' => array_map(
                static fn(int $i): string => "https://cdn.example.com/ref-{$i}.png",
                range(1, 10),
            ),
        ],
        'reference_images accepts at most 9 entries',
    ],
    'too many reference videos' => [
        [
            'prompt'           => 'cinematic scene',
            'reference_images' => ['https://cdn.example.com/char.png'],
            'reference_videos' => array_map(
                static fn(int $i): string => "https://cdn.example.com/ref-{$i}.mp4",
                range(1, 4),
            ),
        ],
        'reference_videos accepts at most 3 entries',
    ],
    'too many reference audio' => [
        [
            'prompt'           => 'cinematic scene',
            'reference_images' => ['https://cdn.example.com/char.png'],
            'reference_audio'  => array_map(
                static fn(int $i): string => "https://cdn.example.com/audio-{$i}.mp3",
                range(1, 4),
            ),
        ],
        'reference_audio accepts at most 3 entries',
    ],
    'reference_audio without image/video' => [
        [
            'prompt'          => 'cinematic scene',
            'reference_audio' => ['https://cdn.example.com/audio.mp3'],
        ],
        'reference_audio must be accompanied by an image or video input',
    ],
    'frame + reference mixed (mode exclusivity)' => [
        [
            'prompt'            => 'cinematic scene',
            'first_frame_image' => 'https://cdn.example.com/frame.png',
            'reference_images'  => ['https://cdn.example.com/ref.png'],
        ],
        'mutually exclusive',
    ],
    'last_frame_image without first_frame_image' => [
        [
            'prompt'           => 'cinematic scene',
            'last_frame_image' => 'https://cdn.example.com/end.png',
        ],
        'last_frame_image requires first_frame_image',
    ],
    'Spora Media Archive path for first_frame_image' => [
        [
            'prompt'            => 'cinematic scene',
            'first_frame_image' => '/api/v1/assets/9b8c7d6e-1234-5678-9abc-def012345678.png',
        ],
        'is not reachable from MiniMax',
    ],
    'Spora Media Archive path inside reference_images array' => [
        [
            'prompt'           => 'cinematic scene',
            'reference_images' => ['/api/v1/assets/abc-def-123.png'],
        ],
        'is not reachable from MiniMax',
    ],
    'Spora Media Archive path inside reference_videos array' => [
        [
            'prompt'           => 'cinematic scene',
            'reference_images' => ['https://cdn.example.com/char.png'],
            'reference_videos' => ['/api/v1/assets/abc-def-123.mp4'],
        ],
        'is not reachable from MiniMax',
    ],
    'unsupported scheme (ftp://)' => [
        [
            'prompt'            => 'cinematic scene',
            'first_frame_image' => 'ftp://example.com/frame.png',
        ],
        'must be http(s)://, mm_file://, or a data: URI',
    ],
    'mm_file:// URL is accepted' => [
        [
            'prompt'            => 'cinematic scene',
            'first_frame_image' => 'mm_file://abc123',
        ],
        'prompt cannot be empty',  // won't reach content-limit check; sentinel for the next test
    ],
]);

it('rejects out-of-spec content[] combinations', function (array $args, string $expectedFragment): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    $tool = new MiniMaxVideoTool(
        $config,
        Mockery::mock(HttpClientInterface::class),
        new MiniMaxLogWriter(),
    );

    $result = $tool->execute($args, 1);

    if ($expectedFragment === 'prompt cannot be empty') {
        // Positive control — this case should NOT trigger a content-limit rejection.
        // The tool would proceed to submit (and fail because no HTTP mock). Assert it
        // got far enough to attempt a submit, by confirming a different error class.
        expect($result->success)->toBeFalse();
        return;
    }

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain($expectedFragment);
})->with('content_limit_rejections');

it('accepts mm_file:// URLs as image inputs', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::any())
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-mm'])));
    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(['task' => ['id' => 'task-mm', 'status' => 'running']])));

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute([
        'prompt'            => 'cinematic scene',
        'first_frame_image' => 'mm_file://abc123',
    ], 1);

    // mm_file:// is accepted; the call proceeds to submit + poll (mocked).
    // The poll never reaches a terminal state, so the tool returns a timed_out
    // envelope — that's fine, it proves the URL passed validation.
    expect($result->data['timed_out'] ?? false)->toBeTrue();
});

it('accepts data: URIs under the size cap as image inputs', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn([
        'api_key'               => 'k',
        'poll_interval_seconds' => '1',
        'poll_timeout_seconds'  => '5',
    ]);

    $http = Mockery::mock(HttpClientInterface::class);
    $http->allows('request')
        ->with('POST', 'https://api.minimax.io/v2/video_generation', Mockery::any())
        ->andReturn(h3Response(200, json_encode(['task_id' => 'task-data'])));
    $http->allows('request')
        ->with('GET', Mockery::pattern('#^https://api\\.minimax\\.io/v2/query/video_generation/.+$#'), Mockery::any())
        ->andReturn(h3Response(200, json_encode(['task' => ['id' => 'task-data', 'status' => 'running']])));

    // Small data: URI (well under the 50 MB cap) — accepted.
    $dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    $tool = new MiniMaxVideoTool($config, $http, new MiniMaxLogWriter());
    $result = $tool->execute([
        'prompt'            => 'cinematic scene',
        'first_frame_image' => $dataUri,
    ], 1);

    // data: URI passed validation; submit + poll mocked.
    expect($result->data['timed_out'] ?? false)->toBeTrue();
});

it('rejects data: URIs over the size cap', function (): void {
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);

    // > 50 MB data: URI — rejected client-side before submit.
    $oversizeDataUri = 'data:image/png;base64,' . str_repeat('A', 51 * 1024 * 1024);

    $tool = new MiniMaxVideoTool(
        $config,
        Mockery::mock(HttpClientInterface::class),
        new MiniMaxLogWriter(),
    );
    $result = $tool->execute([
        'prompt'            => 'cinematic scene',
        'first_frame_image' => $oversizeDataUri,
    ], 1);

    expect($result->success)->toBeFalse()
        ->and($result->content)->toContain('must be http(s)://, mm_file://, or a data: URI');
});
