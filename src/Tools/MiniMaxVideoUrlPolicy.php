<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

/**
 * URL and scalar hygiene rules shared by the H3 video tool's validators
 * and content builders. Static helpers — these rules are stateless and
 * shared across {@see MiniMaxVideoValidator}, {@see MiniMaxVideoContentBuilder},
 * and the main tool class.
 *
 * Split off the main `MiniMaxVideoTool` to keep its method count under
 * Sonar's 20-method threshold (S1448) and to surface the URL contract as
 * a single named policy object.
 */
final class MiniMaxVideoUrlPolicy
{
    /**
     * Maximum size (bytes) of an inline `data:` URI we'll forward to
     * MiniMax. The v2 endpoint caps the request body at 64 MB and
     * JSON wrapping + the rest of `content[]` adds ~10 KB of overhead,
     * so 50 MB leaves ~14 MB of headroom for a single image without
     * tripping the 64 MB ceiling. Larger payloads must use a public
     * URL.
     */
    public const MAX_DATA_URI_BYTES = 50 * 1024 * 1024;

    /**
     * Accept `http://`, `https://`, `mm_file://`, or `data:` URIs (data:
     * URIs are accepted for image-to-video workflows where the LLM has
     * the bytes inline — the 50 MB cap on the URI string itself keeps
     * the request body well under MiniMax's 64 MB ceiling once JSON
     * wrapping is added).
     */
    public static function isAcceptableUrl(string $url): bool
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || str_starts_with($url, 'mm_file://')) {
            return true;
        }
        if (str_starts_with($url, 'data:')) {
            return strlen($url) <= self::MAX_DATA_URI_BYTES;
        }
        return false;
    }

    /**
     * Match Spora Media Archive relative paths (`/api/v1/assets/<uuid>.<ext>`).
     * These can't be passed to MiniMax: they're served by the Spora HTTP
     * controller, not externally reachable, and H3 doesn't recognise the
     * opaque URL form. Callers must either generate a fresh image via
     * `minimax_image` (Path B in `minimax-image-to-video`) or supply a
     * public URL.
     */
    public static function isMediaArchivePath(string $url): bool
    {
        return str_starts_with($url, '/api/v1/assets/');
    }

    /**
     * Produce an operator- and LLM-friendly rejection for a Media Archive
     * path. Surfaces both the validation error and the recovery recipe so
     * the LLM can self-correct without a retry round-trip.
     */
    public static function mediaArchiveRejectionMessage(string $url): string
    {
        return "Media Archive URL '{$url}' is not reachable from MiniMax's servers. "
            . 'For an uploaded image, generate a fresh image with `minimax_image` (Path B of the minimax-image-to-video skill) and pass the generated `image_urls[0]` as `first_frame_image`. '
            . 'For an externally-hosted image, paste the public URL directly.';
    }

    /**
     * Validate that a scalar represents an integer (int or numeric string
     * with no decimal/exponent component). Used to reject `"4.5"` and
     * `"4e0"` inputs that `(int)` cast would silently truncate.
     *
     * @param mixed $value
     */
    public static function isIntegerLike(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' && preg_match('/^-?\d+$/', $trimmed) === 1;
        }
        return false;
    }

    /**
     * Coerce a possibly-mixed user input into a clean list of non-empty strings.
     * Accepts:
     *   - null / missing → []
     *   - list<string>   → filtered to non-empty entries, trimmed
     *   - string         → wrapped as a single-element list (some callers
     *                       pass "url1,url2" — we don't try to split on commas
     *                       since URL parsing inside commas is brittle)
     *
     * @param  mixed       $raw
     * @return list<string>
     */
    public static function normaliseStringList(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (is_string($raw)) {
            $trimmed = trim($raw);
            return $trimmed === '' ? [] : [$trimmed];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = trim($v);
            if ($t !== '') {
                $out[] = $t;
            }
        }
        return $out;
    }
}
