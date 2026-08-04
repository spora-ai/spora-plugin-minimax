<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Spora\Tools\ValueObjects\ToolResult;

/**
 * Per-operation argument validation for the H3 video tool. Returns
 * `null` when arguments are valid, or a `ToolResult::fail(...)` carrying
 * the aggregated error message.
 *
 * Split off the main `MiniMaxVideoTool` to keep its method count under
 * Sonar's 20-method threshold (S1448). The class is stateless — every
 * method is static.
 */
final class MiniMaxVideoValidator
{
    /**
     * Validate `generate` and `enhance_prompt` inputs. Both operations
     * share the same `content[]`-building path, so they share the same
     * input validation too.
     *
     * `resume` doesn't need it (all fields are upstream-fixed at submit
     * time). `regenerate` runs `validateRegenerateArguments()` instead —
     * its argument set is narrower (`task_id` + `base_video_url` + a
     * locked `resolution: '2K'`).
     *
     * @param array<string, mixed> $arguments
     */
    public static function validateSubmitArguments(array $arguments): ?ToolResult
    {
        $errors = [];

        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt === '') {
            $errors[] = 'Prompt cannot be empty.';
        }
        if (mb_strlen($prompt) > 7000) {
            $errors[] = 'Prompt exceeds the 7000-character H3 limit.';
        }

        $durationRaw = $arguments['duration_seconds'] ?? 6;
        // H3 only accepts integers. Reject fractional / decimal inputs
        // (e.g. "4.5") explicitly instead of silently truncating — a
        // silent cast to (int) would round 4.5 → 4 and produce an
        // unexpected clip length.
        if (!MiniMaxVideoUrlPolicy::isIntegerLike($durationRaw)) {
            $errors[] = 'duration_seconds must be an integer between 4 and 15 (no decimals, no non-numeric strings).';
        } else {
            $duration = (int) $durationRaw;
            if ($duration < 4 || $duration > 15) {
                $errors[] = 'duration_seconds must be an integer between 4 and 15.';
            }
        }

        $resolution = trim((string) ($arguments['resolution'] ?? ''));
        if ($resolution !== '' && !in_array($resolution, MiniMaxVideoContentBuilder::RESOLUTIONS, true)) {
            $errors[] = 'resolution must be "768P" or "2K" (uppercase P on 768P).';
        }

        // Build the content[] to surface mode + limit errors early.
        $contentErrors = self::collectContentErrors($arguments);
        if ($contentErrors !== []) {
            $errors = array_merge($errors, $contentErrors);
        }

        return $errors === [] ? null : new ToolResult(false, implode(' ', $errors));
    }

    /**
     * Validate the `resume` operation's inputs — just the `task_id`
     * is required. Prompt / duration / resolution are ignored (the
     * task is already in flight).
     *
     * @param array<string, mixed> $arguments
     */
    public static function validateResumeArguments(array $arguments): ?ToolResult
    {
        $taskId = trim((string) ($arguments['task_id'] ?? ''));
        if ($taskId === '') {
            return new ToolResult(false, 'task_id is required for the resume operation.');
        }
        return null;
    }

    /**
     * Validate the `regenerate` operation's inputs. Needs `task_id`
     * and `base_video_url` (the URL of the previous 768P source).
     * Resolution is locked to `2K` (the only value the v2
     * regeneration endpoint accepts).
     *
     * @param array<string, mixed> $arguments
     */
    public static function validateRegenerateArguments(array $arguments): ?ToolResult
    {
        $errors = [];

        $taskId = trim((string) ($arguments['task_id'] ?? ''));
        if ($taskId === '') {
            $errors[] = 'task_id is required for the regenerate operation (the original `generate` call\'s task id).';
        }

        $baseVideoUrl = trim((string) ($arguments['base_video_url'] ?? ''));
        if ($baseVideoUrl === '') {
            $errors[] = 'base_video_url is required for the regenerate operation (the previous 768P output\'s download_url or asset_url).';
        } elseif (!MiniMaxVideoUrlPolicy::isAcceptableUrl($baseVideoUrl)) {
            $errors[] = 'base_video_url must be http(s):// or mm_file:// — data: URIs are rejected (the 64 MB request body cap can\'t carry inline base64).';
        }

        $resolution = trim((string) ($arguments['resolution'] ?? '2K'));
        if ($resolution !== '' && $resolution !== '2K') {
            $errors[] = 'regenerate currently only supports resolution "2K" (the v2 regeneration endpoint upsamples 768P sources to 2K).';
        }

        if ($errors !== []) {
            return new ToolResult(false, implode(' ', $errors));
        }
        return null;
    }

    /**
     * Inspect `arguments` for content[] violations. Returns a list of
     * human-readable errors (empty when input is valid).
     *
     * Four families of checks:
     *
     *   1. **Mode exclusivity** — H3 forbids mixing `first_frame` /
     *      `last_frame` with `reference_*` roles in the same `content[]`.
     *      The validator here only sees the LLM's flat input, so we
     *      derive the intended mode from the presence of any frame image
     *      vs. any reference and reject combinations.
     *
     *   2. **Per-mode counts** — frame images: ≤2 (one each). References:
     *      ≤9 images, ≤3 videos, ≤3 audio. Per the v2 spec tables.
     *
     *   3. **Audio-needs-image rule** — `reference_audio` must be
     *      accompanied by an image or video input. H3 rejects audio-only
     *      `content[]` ("cannot be input alone").
     *
     *   4. **URL hygiene** — every URL must be `http://`, `https://`,
     *      `mm_file://`, or a `data:` URI under the size cap. Spora
     *      Media Archive paths are rejected with an actionable message.
     *
     * @param  array<string, mixed> $arguments
     * @return list<string>
     */
    private static function collectContentErrors(array $arguments): array
    {
        $errors   = [];
        $first    = trim((string) ($arguments['first_frame_image'] ?? ''));
        $last     = trim((string) ($arguments['last_frame_image'] ?? ''));
        $refImgs  = MiniMaxVideoUrlPolicy::normaliseStringList($arguments['reference_images'] ?? null);
        $refVids  = MiniMaxVideoUrlPolicy::normaliseStringList($arguments['reference_videos'] ?? null);
        $refAud   = MiniMaxVideoUrlPolicy::normaliseStringList($arguments['reference_audio'] ?? null);

        $hasFrames      = $first !== '' || $last !== '';
        $hasReferences  = $refImgs !== [] || $refVids !== [] || $refAud !== [];

        // 1. Mode exclusivity.
        if ($hasFrames && $hasReferences) {
            $errors[] = 'image-to-video (first_frame_image / last_frame_image) and reference-to-video (reference_*) are mutually exclusive — pick one mode per call.';
        }

        // 2a. Frame counts.
        if ($first === '' && $last !== '') {
            $errors[] = 'last_frame_image requires first_frame_image to be set (H3 pairs them).';
        }

        // 2b. Reference counts.
        if (count($refImgs) > 9) {
            $errors[] = 'reference_images accepts at most 9 entries.';
        }
        if (count($refVids) > 3) {
            $errors[] = 'reference_videos accepts at most 3 entries.';
        }
        if (count($refAud) > 3) {
            $errors[] = 'reference_audio accepts at most 3 entries.';
        }

        // 3. Audio-needs-image.
        if ($refAud !== [] && $refImgs === [] && $refVids === [] && !$hasFrames) {
            $errors[] = 'reference_audio must be accompanied by an image or video input (H3 rejects audio-only content[]).';
        }

        // 4. URL hygiene across all asset lists.
        $allUrls = array_values(array_filter([$first, $last], static fn(string $u): bool => $u !== ''));
        $allUrls = array_merge($allUrls, $refImgs, $refVids, $refAud);
        $errors = array_merge($errors, self::collectUrlErrors($allUrls));

        return $errors;
    }

    /**
     * @param list<string> $allUrls
     * @return list<string>
     */
    private static function collectUrlErrors(array $allUrls): array
    {
        $errors = [];
        foreach ($allUrls as $url) {
            if (MiniMaxVideoUrlPolicy::isMediaArchivePath($url)) {
                $errors[] = MiniMaxVideoUrlPolicy::mediaArchiveRejectionMessage($url);
                continue;
            }
            if (!MiniMaxVideoUrlPolicy::isAcceptableUrl($url)) {
                $errors[] = "media URL must be http(s)://, mm_file://, or a data: URI (got: '{$url}').";
            }
        }

        return $errors;
    }
}
