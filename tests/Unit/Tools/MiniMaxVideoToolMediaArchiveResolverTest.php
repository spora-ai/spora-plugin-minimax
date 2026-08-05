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
 * {@see Spora\Plugins\MiniMax\Tools\MiniMaxTool::runWithValidation()}.
 * Confirms the resolver runs before the URL policy and that its
 * mutation / failure paths land in the tool's `ToolResult` exactly
 * the way the design documents.
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
