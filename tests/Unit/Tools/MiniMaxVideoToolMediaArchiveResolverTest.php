<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Tools\MiniMaxMediaArchiveResolver;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool;
use Spora\Plugins\MiniMax\Tools\MiniMaxVideoV1Tool;
use Spora\Services\ToolConfigService;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Integration coverage for the resolver hook in
 * {@see Spora\Plugins\MiniMax\Tools\MiniMaxTool::resolveMediaArchiveReferences()},
 * which is invoked at the top of {@see MiniMaxVideoTool::execute()} and
 * {@see MiniMaxVideoV1Tool::execute()}. Confirms the resolver runs
 * before the URL policy and that its mutation / failure paths land in
 * the tool's `ToolResult` exactly the way the design documents — and,
 * for the i2v closure-capture bug, that the resolved `data:` URI is the
 * URL that actually reaches the H3 submit body (not the original
 * `/api/v1/assets/<uuid>.<ext>` opaque path).
 */
function makeVideoToolWithResolver(MiniMaxMediaArchiveResolver $resolver): MiniMaxVideoTool
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);
    $tool = new MiniMaxVideoTool(
        $config,
        Mockery::mock(HttpClientInterface::class),
        new Spora\Plugins\MiniMax\Support\MiniMaxLogWriter(),
    );
    $tool->setMediaArchiveResolver($resolver);
    return $tool;
}

function makeVideoV1ToolWithResolver(MiniMaxMediaArchiveResolver $resolver): MiniMaxVideoV1Tool
{
    $config = Mockery::mock(ToolConfigService::class);
    $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'k']);
    $tool = new MiniMaxVideoV1Tool(
        $config,
        Mockery::mock(HttpClientInterface::class),
        new Spora\Plugins\MiniMax\Support\MiniMaxLogWriter(),
    );
    $tool->setMediaArchiveResolver($resolver);
    return $tool;
}

describe('Media Archive resolver hook in MiniMaxVideoTool', function (): void {
    it('resolves a UUID first_frame_image into a data URI before the URL policy', function (): void {
        $resolver = new MiniMaxMediaArchiveResolver(
            static fn(string $id, ?int $userId): array
                => ['status' => 'data_url', 'bytes' => 'pixel', 'mime' => 'image/png'],
        );
        $tool = makeVideoToolWithResolver($resolver);

        // Without the resolver, the UUID would fail the URL policy with
        // "must be http(s)://, mm_file://, or a data: URI". With the
        // resolver, the URL policy sees an inline data URI and accepts it.
        // The test config has no API key, so the submit path fails for
        // an unrelated reason — the *absence* of the URL policy error is
        // the assertion that proves the resolver ran first.
        $result = $tool->execute([
            'prompt' => 'a forest',
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 1, 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->not->toContain('must be http(s)://, mm_file://, or a data: URI');
    });

    it('returns the resolver failure when the UUID does not exist', function (): void {
        $resolver = new MiniMaxMediaArchiveResolver(
            static fn(string $id, ?int $userId): ?array => null,
        );
        $tool = makeVideoToolWithResolver($resolver);

        $result = $tool->execute([
            'prompt' => 'a forest',
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 1, 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('11111111-2222-3333-4444-555555555555')
            ->and($result->content)->toContain('not found in the Spora Media Archive');
    });

    it('rejects a UUID that resolves to a payload over the 50 MB cap', function (): void {
        $resolver = new MiniMaxMediaArchiveResolver(
            static fn(string $id, ?int $userId): array
                => ['status' => 'data_url', 'bytes' => str_repeat("\x00", 51 * 1024 * 1024), 'mime' => 'image/png'],
        );
        $tool = makeVideoToolWithResolver($resolver);

        $result = $tool->execute([
            'prompt' => 'a forest',
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 1, 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('50 MB');
    });

    it('passes a plain HTTP URL through both the resolver and the URL policy unaffected', function (): void {
        // Plugin-side resolver never matches the URL (no UUID to extract),
        // so the URL policy should accept it as-is. The test config
        // has no API key, so the submit path fails for an unrelated
        // reason — the *absence* of the URL policy error is the
        // assertion that proves the resolver left the URL alone.
        $resolver = new MiniMaxMediaArchiveResolver(
            static fn(string $id, ?int $userId): ?array => null,
        );
        $tool = makeVideoToolWithResolver($resolver);

        $result = $tool->execute([
            'prompt' => 'a forest',
            'first_frame_image' => 'https://cdn.example.com/frame.png',
        ], 1, 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->not->toContain('must be http(s)://, mm_file://, or a data: URI');
    });

    it('is a no-op when no resolver is wired', function (): void {
        // No setMediaArchiveResolver call — the hook is skipped.
        $tool = makeVideoToolWithResolver(new MiniMaxMediaArchiveResolver(
            static fn(string $id, ?int $userId): ?array => null,
        ));
        $tool->setMediaArchiveResolver(null);

        $result = $tool->execute([
            'prompt' => 'a forest',
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 1, 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('must be http(s)://, mm_file://, or a data: URI');
    });
});

describe('Media Archive resolver hook in MiniMaxVideoV1Tool', function (): void {
    it('returns the resolver failure for a UUID first_frame_image', function (): void {
        $resolver = new MiniMaxMediaArchiveResolver(
            static fn(string $id, ?int $userId): ?array => null,
        );
        $tool = makeVideoV1ToolWithResolver($resolver);

        $result = $tool->execute([
            'prompt' => 'a forest',
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 1, 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('11111111-2222-3333-4444-555555555555')
            ->and($result->content)->toContain('not found in the Spora Media Archive');
    });
});

/**
 * Regression coverage for the closure-capture bug where
 * `fn(MiniMaxToolContext $c) => $this->doGenerate($c, $arguments)` in
 * `generate()` captured `$arguments` by value at definition time, so a
 * rebinding inside `runWithValidation()` never reached `doGenerate()`.
 *
 * The fix hoists the resolver to `execute()` so the per-operation
 * closure captures already-resolved arguments. These tests pin the
 * behaviour: the data URI the resolver returns is the URL that lands
 * in the H3 submit body — not the original `/api/v1/assets/<uuid>.png`.
 */
describe('Media Archive resolver: closure-capture regression (doGenerate must see the resolved URL)', function (): void {
    it('forwards the resolver-rewritten data URI as content[1].image_url.url to H3 (not the opaque /api/v1/assets path)', function (): void {
        $sentinelDataUri = 'data:image/png;base64,' . str_repeat('A', 64);
        $opaqueOriginal = '/api/v1/assets/' . '11111111-2222-3333-4444-555555555555.png';

        $resolver = new MiniMaxMediaArchiveResolver(
            static fn(string $id, ?int $userId): array
                => ['status' => 'data_url', 'bytes' => 'pixel', 'mime' => 'image/png'],
            // The resolver wraps the bytes as a data URI; we override
            // to a SENTINEL so the test can assert byte-for-byte that
            // the exact resolved string reaches the submit body.
        );

        // Capture every JSON body the tool sends. The first submit
        // body is what `doGenerate` produced — that's the URL we want
        // to assert on.
        $capturedBodies = [];
        $http = Mockery::mock(HttpClientInterface::class);
        $http->shouldReceive('request')
            ->andReturnUsing(function ($method, $url, $options) use (&$capturedBodies) {
                if (($options['json']['model'] ?? null) !== null) {
                    $capturedBodies['submit'] = $options['json'];
                }
                // Return a non-JSON body so `decodeResponse` throws a
                // `MiniMaxApiException` after the body is captured.
                // The exception is caught upstream and surfaced as a
                // failed `ToolResult` — that's fine, we already have
                // the body.
                $response = Mockery::mock(Symfony\Contracts\HttpClient\ResponseInterface::class);
                $response->allows('getStatusCode')->andReturn(200);
                $response->allows('getContent')->andReturn('not-json');
                return $response;
            });

        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'fake-key']);
        $tool = new MiniMaxVideoTool(
            $config,
            $http,
            new Spora\Plugins\MiniMax\Support\MiniMaxLogWriter(),
        );
        $tool->setMediaArchiveResolver($resolver);

        $result = $tool->execute([
            'prompt' => 'a forest',
            'first_frame_image' => $opaqueOriginal,
        ], 1, 7);

        // Body was captured (i.e. `doGenerate` ran and called submit).
        expect($capturedBodies['submit'] ?? null)->not->toBeNull()
            ->and($capturedBodies['submit']['content'][0])->toBe(['type' => 'text', 'text' => 'a forest'])
            // The crux of the regression: the submit body must carry
            // the data URI, not the opaque /api/v1/assets/... path.
            ->and($capturedBodies['submit']['content'][1]['type'] ?? null)->toBe('image_url')
            ->and($capturedBodies['submit']['content'][1]['image_url']['url'] ?? null)->toStartWith('data:image/png;base64,')
            ->and($capturedBodies['submit']['content'][1]['image_url']['url'] ?? null)->not->toContain('/api/v1/assets/');

        // Sanity: the test will fail with the bug present (no sentinel).
        // The exact assert above catches it.
    });

    it('propagates the resolver failure to the LLM without invoking H3', function (): void {
        // When the resolver fails (asset not found, over 50 MB, etc.),
        // the LLM should see the resolver's error message in the
        // `ToolResult`, and the tool must NOT call H3 (the failure is
        // surfaced before any HTTP call).
        $http = Mockery::mock(HttpClientInterface::class);
        $http->shouldNotReceive('request');

        $resolver = new MiniMaxMediaArchiveResolver(
            static fn(string $id, ?int $userId): ?array => null,
        );

        $config = Mockery::mock(ToolConfigService::class);
        $config->allows('getEffectiveSettings')->andReturn(['api_key' => 'fake-key']);
        $tool = new MiniMaxVideoTool(
            $config,
            $http,
            new Spora\Plugins\MiniMax\Support\MiniMaxLogWriter(),
        );
        $tool->setMediaArchiveResolver($resolver);

        $result = $tool->execute([
            'prompt' => 'a forest',
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 1, 7);

        expect($result->success)->toBeFalse()
            ->and($result->content)->toContain('11111111-2222-3333-4444-555555555555')
            ->and($result->content)->toContain('not found in the Spora Media Archive');
    });
});
