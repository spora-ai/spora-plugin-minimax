<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Tools\MiniMaxMediaArchiveResolver;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Coverage for {@see MiniMaxMediaArchiveResolver} — the pre-validation
 * hook that turns Media Archive UUIDs / opaque URLs into forwardable
 * data URIs before {@see Spora\Plugins\MiniMax\Tools\MiniMaxVideoValidator}
 * rejects them.
 *
 * The reader is a closure in production (see the resolver's docblock),
 * so the tests use direct closures too — no Mockery, no spora-core
 * service instantiation. Returns are scripted per call site.
 */
function makeResolver(?Closure $reader = null): MiniMaxMediaArchiveResolver
{
    return new MiniMaxMediaArchiveResolver(
        $reader ?? static fn(string $id, ?int $userId): ?array => null,
    );
}

/**
 * Build a closure that scripts a single (id, userId) → payload mapping.
 * Unscripted calls return null (the not-found failure).
 */
function fakeReader(array $script): Closure
{
    return static function (string $id, ?int $userId) use ($script): ?array {
        foreach ($script as $entry) {
            if ($entry['id'] === $id
                && ($entry['userId'] === $userId || !array_key_exists('userId', $entry))
                && $entry['userId'] === $userId
            ) {
                return $entry['payload'];
            }
        }
        return null;
    };
}

// ----- Detection ---------------------------------------------------------

describe('MiniMaxMediaArchiveResolver::resolve', function (): void {
    it('passes through a plain HTTP URL untouched', function (): void {
        $reader = static fn(string $id, ?int $userId): ?array => null;
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => 'https://cdn.example.com/frame.png',
        ], 1);

        expect($out)->toBe(['resolved' => ['first_frame_image' => 'https://cdn.example.com/frame.png']]);
    });

    it('passes through a data: URI untouched', function (): void {
        $reader = static fn(string $id, ?int $userId): ?array => null;
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => 'data:image/png;base64,iVBORw0K',
        ], 1);

        expect($out)->toBe(['resolved' => ['first_frame_image' => 'data:image/png;base64,iVBORw0K']]);
    });

    it('passes through an mm_file:// URL untouched', function (): void {
        $reader = static fn(string $id, ?int $userId): ?array => null;
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => 'mm_file://store-abc123/frame.png',
        ], 1);

        expect($out)->toBe(['resolved' => ['first_frame_image' => 'mm_file://store-abc123/frame.png']]);
    });

    // ----- UUID resolution ----------------------------------------------

    it('resolves a bare 36-char UUID into a data URI', function (): void {
        $reader = fakeReader([
            ['id' => '11111111-2222-3333-4444-555555555555', 'userId' => 7,
                'payload' => ['status' => 'data_url', 'bytes' => 'png-bytes', 'mime' => 'image/png']],
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 7);

        expect($out['resolved']['first_frame_image'])->toBe('data:image/png;base64,' . base64_encode('png-bytes'));
    });

    it('resolves a bare UUID with a stray extension into a data URI', function (): void {
        $reader = fakeReader([
            ['id' => '11111111-2222-3333-4444-555555555555', 'userId' => 7,
                'payload' => ['status' => 'local', 'bytes' => 'jpeg-bytes', 'mime' => 'image/jpeg']],
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => '11111111-2222-3333-4444-555555555555.png',
        ], 7);

        expect($out['resolved']['first_frame_image'])->toBe('data:image/jpeg;base64,' . base64_encode('jpeg-bytes'));
    });

    it('resolves a /api/v1/assets/<uuid>.<ext> URL into a data URI', function (): void {
        $reader = fakeReader([
            ['id' => '11111111-2222-3333-4444-555555555555', 'userId' => 7,
                'payload' => ['status' => 'data_url', 'bytes' => 'webp-bytes', 'mime' => 'image/webp']],
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => '/api/v1/assets/11111111-2222-3333-4444-555555555555.webp',
        ], 7);

        expect($out['resolved']['first_frame_image'])->toBe('data:image/webp;base64,' . base64_encode('webp-bytes'));
    });

    // ----- External mode ------------------------------------------------

    it('passes through the source URL for external-mode assets', function (): void {
        $reader = fakeReader([
            ['id' => '11111111-2222-3333-4444-555555555555', 'userId' => 7,
                'payload' => ['status' => 'external', 'sourceUrl' => 'https://cdn.example.com/asset.jpg']],
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 7);

        expect($out['resolved']['first_frame_image'])->toBe('https://cdn.example.com/asset.jpg');
    });

    // ----- Failure paths ------------------------------------------------

    it('returns a failed ToolResult when the UUID does not exist', function (): void {
        $reader = static fn(string $id, ?int $userId): ?array => null;
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 7);

        expect($out['failed'])->toBeInstanceOf(ToolResult::class);
        expect($out['failed']->success)->toBeFalse();
        expect($out['failed']->content)->toContain('11111111-2222-3333-4444-555555555555')
            ->and($out['failed']->content)->toContain('not found in the Spora Media Archive');
    });

    it('returns a failed ToolResult when the bytes exceed the 50 MB data URI cap', function (): void {
        // 51 MB of zeros — base64 encodes to ~68 MB, over the 50 MB cap.
        $hugeBytes = str_repeat("\x00", 51 * 1024 * 1024);
        $reader = fakeReader([
            ['id' => '11111111-2222-3333-4444-555555555555', 'userId' => 7,
                'payload' => ['status' => 'data_url', 'bytes' => $hugeBytes, 'mime' => 'image/png']],
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 7);

        expect($out['failed'])->toBeInstanceOf(ToolResult::class);
        expect($out['failed']->content)->toContain('50 MB')
            ->and($out['failed']->content)->toContain('51.0');
    });

    // ----- List fields --------------------------------------------------

    it('scans every element in a reference_images list', function (): void {
        $reader = fakeReader([
            ['id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'userId' => 7,
                'payload' => ['status' => 'data_url', 'bytes' => 'bytes-1', 'mime' => 'image/png']],
            ['id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'userId' => 7,
                'payload' => ['status' => 'data_url', 'bytes' => 'bytes-2', 'mime' => 'image/jpeg']],
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'reference_images' => [
                'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'https://cdn.example.com/ref.png',
                'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            ],
        ], 7);

        expect($out['resolved']['reference_images'])->toBe([
            'data:image/png;base64,' . base64_encode('bytes-1'),
            'https://cdn.example.com/ref.png',
            'data:image/jpeg;base64,' . base64_encode('bytes-2'),
        ]);
    });

    it('short-circuits on a failed resolution mid-list', function (): void {
        $reader = fakeReader([
            ['id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'userId' => 7,
                'payload' => ['status' => 'data_url', 'bytes' => 'ok', 'mime' => 'image/png']],
            // cccccccc is intentionally absent — the resolver must stop
            // before reaching bbbbbbbb.
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'reference_images' => [
                'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'cccccccc-cccc-cccc-cccc-cccccccccccc',
                'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            ],
        ], 7);

        expect($out['failed'])->toBeInstanceOf(ToolResult::class);
    });

    it('preserves the rest of the arguments', function (): void {
        $reader = fakeReader([
            ['id' => '11111111-2222-3333-4444-555555555555', 'userId' => 1,
                'payload' => ['status' => 'data_url', 'bytes' => 'png', 'mime' => 'image/png']],
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
            'prompt' => 'a cinematic shot of the ocean at dawn',
            'duration_seconds' => 6,
            'aspect_ratio' => '16:9',
        ], 1);

        expect($out['resolved']['prompt'])->toBe('a cinematic shot of the ocean at dawn')
            ->and($out['resolved']['duration_seconds'])->toBe(6)
            ->and($out['resolved']['aspect_ratio'])->toBe('16:9');
    });

    // ----- userId handling ---------------------------------------------

    it('passes null userId through to the reader as the system-context bypass', function (): void {
        $reader = fakeReader([
            ['id' => '11111111-2222-3333-4444-555555555555', 'userId' => null,
                'payload' => ['status' => 'data_url', 'bytes' => 'png', 'mime' => 'image/png']],
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], null);

        expect($out['resolved']['first_frame_image'])->toBe('data:image/png;base64,' . base64_encode('png'));
    });

    // ----- Other URL fields --------------------------------------------

    it('scans last_frame_image', function (): void {
        $reader = fakeReader([
            ['id' => '11111111-2222-3333-4444-555555555555', 'userId' => 1,
                'payload' => ['status' => 'data_url', 'bytes' => 'last', 'mime' => 'image/png']],
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'last_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 1);

        expect($out['resolved']['last_frame_image'])->toBe('data:image/png;base64,' . base64_encode('last'));
    });

    it('leaves unknown fields alone', function (): void {
        $reader = fakeReader([
            ['id' => '11111111-2222-3333-4444-555555555555', 'userId' => 1,
                'payload' => ['status' => 'data_url', 'bytes' => 'png', 'mime' => 'image/png']],
        ]);
        $resolver = makeResolver($reader);

        $out = $resolver->resolve([
            'prompt' => 'a cat on a table',
            'first_frame_image' => '11111111-2222-3333-4444-555555555555',
        ], 1);

        expect($out['resolved']['prompt'])->toBe('a cat on a table')
            ->and($out['resolved']['first_frame_image'])->toBe('data:image/png;base64,' . base64_encode('png'));
    });
});
