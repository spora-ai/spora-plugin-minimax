<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Closure;
use Psr\Log\LoggerInterface;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Resolve Spora Media Archive UUIDs and opaque `/api/v1/assets/<uuid>.<ext>`
 * URLs in the `$arguments` of a video tool call into a forwardable form
 * before the URL policy runs.
 *
 * Pattern: the LLM calls `minimax:video(first_frame_image: "<uuid>")` after
 * discovering a Media Archive asset via `media:search`. The UUID is then
 * the only thing the LLM has to feed forward — the actual bytes never
 * enter the chat context. The resolver reads the bytes server-side,
 * base64-encodes them, and replaces the argument with a `data:` URI in
 * the same slot.
 *
 * Three resolved forms:
 *   - `data_url` (DB BLOB) → `data:<mime>;base64,<payload>` (inlined)
 *   - `local` (disk)       → `data:<mime>;base64,<payload>` (loaded + encoded)
 *   - `external` (CDN)     → the original `source_url` (forwarded as-is;
 *                            MiniMax fetches it server-side)
 *
 * For UUIDs that don't exist or aren't accessible to the caller, the
 * resolver returns a failed `ToolResult` with an LLM-actionable message
 * — surfacing the failure is the whole point: the LLM can self-correct
 * (generate a fresh image, paste a public URL, etc.) without a retry.
 *
 * Size cap: 50 MB on the resulting `data:` URI (mirrors
 * {@see MiniMaxVideoUrlPolicy::MAX_DATA_URI_BYTES}). Above that, the
 * resolver returns a failure with a downscaling hint.
 *
 * The reader is taken as a closure rather than a concrete service class
 * so the plugin can ship without depending on the `MediaAssetReader` type
 * directly (the host application's `MediaAssetReader` is `final` and
 * therefore not Mockery-friendly from a plugin test). The plugin's
 * {@see \Spora\Plugins\MiniMax\MiniMaxPlugin::register()} wraps the
 * core service in a one-line closure.
 */
final class MiniMaxMediaArchiveResolver
{
    private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * Asset URL slots that undergo UUID resolution. Field names match
     * the public ToolParameter names on {@see MiniMaxVideoTool} and
     * {@see MiniMaxVideoV1Tool}.
     *
     * @var list<string>
     */
    private const URL_FIELDS = [
        'first_frame_image',
        'last_frame_image',
        'reference_images',
        'reference_videos',
        'reference_audio',
    ];

    /**
     * @param Closure(string $id, ?int $userId): ?array $reader
     */
    public function __construct(
        private readonly Closure $reader,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Scan the asset URL slots in `$arguments` and replace any
     * Media Archive UUIDs with inline data URIs.
     *
     * @param  array<string, mixed> $arguments
     * @return array{resolved: array<string, mixed>}|array{failed: ToolResult}
     */
    public function resolve(array $arguments, ?int $userId): array
    {
        $mutated = $arguments;
        foreach (self::URL_FIELDS as $field) {
            if (!array_key_exists($field, $mutated)) {
                continue;
            }
            $raw = $mutated[$field];
            $isList = is_array($raw);
            $values = $isList ? array_values($raw) : [$raw];

            $replaced = [];
            foreach ($values as $value) {
                if (!is_string($value)) {
                    $replaced[] = $value;
                    continue;
                }
                $outcome = $this->resolveOne($value, $userId);
                if (isset($outcome['failed'])) {
                    return ['failed' => $outcome['failed']];
                }
                $replaced[] = $outcome['resolved'];
            }

            $mutated[$field] = $isList ? $replaced : ($replaced[0] ?? '');
        }

        return ['resolved' => $mutated];
    }

    /**
     * @return array{resolved: string}|array{failed: ToolResult}
     */
    private function resolveOne(string $url, ?int $userId): array
    {
        $uuid = $this->extractUuid($url);
        if ($uuid === null) {
            // Not a Media Archive UUID or opaque URL — pass through. The
            // URL policy will reject anything that isn't http/https/mm_file/
            // data: with a clear error, so we don't need to validate here.
            return ['resolved' => $url];
        }

        $result = ($this->reader)($uuid, $userId);
        if ($result === null) {
            return ['failed' => $this->notFoundFailure($uuid, $url)];
        }

        $this->logger?->debug('minimax.media-archive-resolved', [
            'uuid'   => $uuid,
            'status' => $result['status'],
            'size'   => isset($result['bytes']) ? strlen($result['bytes']) : null,
        ]);

        return match ($result['status']) {
            'data_url' => $this->wrapAsDataUri($uuid, $result['bytes'], $result['mime'], $url),
            'local'    => $this->wrapAsDataUri($uuid, $result['bytes'], $result['mime'], $url),
            'external' => $this->forwardExternal($uuid, $result['sourceUrl'], $url),
            default    => ['failed' => $this->notFoundFailure($uuid, $url)],
        };
    }

    /**
     * Match:
     *   - bare 36-char UUID (with optional `.ext`)
     *   - `/api/v1/assets/<uuid>` (with optional `.ext`)
     */
    private function extractUuid(string $url): ?string
    {
        if (preg_match('/^' . self::UUID_PATTERN . '(?:\.[A-Za-z0-9]+)?$/i', $url) === 1) {
            return substr($url, 0, 36);
        }
        if (preg_match('#^/api/v1/assets/(' . self::UUID_PATTERN . ')(?:\.[A-Za-z0-9]+)?$#i', $url, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * @return array{resolved: string}|array{failed: ToolResult}
     */
    private function wrapAsDataUri(string $uuid, string $bytes, string $mime, string $originalUrl): array
    {
        // Short-circuit on the raw byte size before encoding. base64 of
        // 50 MB of bytes is ~67 MB, so a raw payload over 37.5 MB always
        // exceeds the 50 MB URI cap; skipping the `base64_encode()` call
        // is the difference between a 128 MB peak (memory-blast for
        // tests) and a constant working set.
        $rawBytes = strlen($bytes);
        if ($rawBytes > self::maxBytesUnderCap()) {
            return ['failed' => new ToolResult(false, sprintf(
                "Media asset %s is %s MB, exceeds the 50 MB data URI cap. "
                    . 'Use a downscaled image or paste a public URL.',
                $uuid,
                number_format($rawBytes / 1024 / 1024, 1),
            ))];
        }

        $encoded = base64_encode($bytes);
        $dataUri = 'data:' . $mime . ';base64,' . $encoded;
        if (strlen($dataUri) > MiniMaxVideoUrlPolicy::MAX_DATA_URI_BYTES) {
            return ['failed' => new ToolResult(false, sprintf(
                "Media asset %s is %s MB after base64, exceeds the 50 MB data URI cap. "
                    . 'Use a downscaled image or paste a public URL.',
                $uuid,
                number_format($rawBytes / 1024 / 1024, 1),
            ))];
        }
        return ['resolved' => $dataUri];
    }

    /**
     * Maximum raw byte size that always fits under the 50 MB data URI
     * cap regardless of base64 expansion. Computed once at class load
     * time: roughly `cap * 3 / 4` minus the `data:<mime>;base64,`
     * prefix overhead (the smallest mime is `image/webp` = 10 chars).
     */
    private static function maxBytesUnderCap(): int
    {
        static $cap = null;
        if ($cap === null) {
            $prefixOverhead = strlen('data:image/webp;base64,');
            $cap = (int) ((MiniMaxVideoUrlPolicy::MAX_DATA_URI_BYTES - $prefixOverhead) * 3 / 4);
        }
        return $cap;
    }

    /**
     * @return array{resolved: string}
     */
    private function forwardExternal(string $uuid, string $sourceUrl, string $originalUrl): array
    {
        $this->logger?->debug('minimax.media-archive-source-forwarded', [
            'uuid'       => $uuid,
            'source_url' => $sourceUrl,
        ]);
        return ['resolved' => $sourceUrl];
    }

    private function notFoundFailure(string $uuid, string $originalUrl): ToolResult
    {
        return new ToolResult(false, sprintf(
            "Media asset %s not found in the Spora Media Archive. "
                . 'Verify the UUID, or paste a public URL for an externally-hosted image.',
            $uuid,
        ));
    }
}
