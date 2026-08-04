<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

/**
 * Builds the H3 multimodal `content[]` payload from the LLM's flat
 * arguments. Pure transforms — no I/O, no state. Split off the main
 * `MiniMaxVideoTool` to keep its method count under Sonar's 20-method
 * threshold (S1448).
 */
final class MiniMaxVideoContentBuilder
{
    /**
     * Allowed aspect-ratio values under H3. `adaptive` is valid only for
     * image-to-video and reference-to-video modes (image-driven). For
     * text-to-video `adaptive` is rejected upstream — the resolver in
     * {@see resolveAspectRatio()} handles that mode-aware enforcement.
     *
     * @var list<string>
     */
    public const ASPECT_RATIOS = ['adaptive', '21:9', '16:9', '4:3', '1:1', '3:4', '9:16'];

    /**
     * Aspect ratios valid for text-to-video mode. Excludes `adaptive`,
     * which H3 rejects for t2v (the spec: "Text-to-video (t2va): ratio is
     * required and cannot be `adaptive`"). Used by the resolver to fall
     * back to a safe concrete ratio when the LLM supplies `adaptive`
     * with text-only content.
     *
     * @var list<string>
     */
    private const TEXT_ONLY_ASPECT_RATIOS = ['21:9', '16:9', '4:3', '1:1', '3:4', '9:16'];

    /**
     * Allowed resolution values under H3. MiniMax's v2 endpoint accepts
     * only these two; uppercase `P` literal on `768P`.
     *
     * @var list<string>
     */
    public const RESOLUTIONS = ['768P', '2K'];

    /**
     * Build the H3 `content[]` array from the LLM's flat arguments.
     * Always emits exactly one `text` item (H3 requires a non-empty
     * prompt) and optionally appends frame / reference items with
     * the right `role`.
     *
     * Frame images are added in order (first then last) to keep the
     * upstream's expected order stable; references are appended in
     * the LLM-supplied order.
     *
     * @param  array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    public static function buildContentArray(array $arguments): array
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $first  = trim((string) ($arguments['first_frame_image'] ?? ''));
        $last   = trim((string) ($arguments['last_frame_image'] ?? ''));

        $content = [['type' => 'text', 'text' => $prompt]];

        if ($first !== '') {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => $first],
                'role'      => 'first_frame',
            ];
        }
        if ($last !== '') {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => $last],
                'role'      => 'last_frame',
            ];
        }

        foreach (MiniMaxVideoUrlPolicy::normaliseStringList($arguments['reference_images'] ?? null) as $url) {
            $content[] = [
                'type'      => 'image_url',
                'image_url' => ['url' => $url],
                'role'      => 'reference_image',
            ];
        }
        foreach (MiniMaxVideoUrlPolicy::normaliseStringList($arguments['reference_videos'] ?? null) as $url) {
            $content[] = [
                'type'      => 'video_url',
                'video_url' => ['url' => $url],
                'role'      => 'reference_video',
            ];
        }
        foreach (MiniMaxVideoUrlPolicy::normaliseStringList($arguments['reference_audio'] ?? null) as $url) {
            $content[] = [
                'type'      => 'audio_url',
                'audio_url' => ['url' => $url],
                'role'      => 'reference_audio',
            ];
        }

        return $content;
    }

    /**
     * Classify a built `content[]` into a short mode label for
     * debug logging. One of `text_only`, `i2v_first_frame`,
     * `i2v_first_last_frame`, `r2v`. Anything else falls through to
     * `mixed` so the log line still surfaces the shape.
     *
     * @param  array<int, array<string, mixed>> $content
     */
    public static function detectContentMode(array $content): string
    {
        $roles = [];
        foreach ($content as $item) {
            if (isset($item['role']) && is_string($item['role'])) {
                $roles[] = $item['role'];
            }
        }
        $hasFirst = in_array('first_frame', $roles, true);
        $hasLast  = in_array('last_frame', $roles, true);
        $hasRef   = (bool) array_intersect($roles, ['reference_image', 'reference_video', 'reference_audio']);

        if ($hasFirst && $hasLast) {
            return 'i2v_first_last_frame';
        }
        if ($hasFirst) {
            return 'i2v_first_frame';
        }
        if ($hasRef) {
            return 'r2v';
        }
        return 'text_only';
    }

    /**
     * Resolve the effective aspect ratio to send upstream.
     *
     * H3 mode-aware rules (per the v2 spec):
     *
     *   - **Text-to-video** (`content[]` contains only `text`): ratio is
     *     required and cannot be `adaptive`. If the LLM supplied
     *     `adaptive`, fall back to `16:9`. If the LLM supplied nothing
     *     valid, also fall back to `16:9`.
     *   - **Image-to-video** (`content[]` has first_frame / last_frame):
     *     ratio is always `adaptive`. Any concrete ratio supplied by
     *     the LLM is silently ignored by upstream, so we just force it
     *     and save the round-trip interpretation.
     *   - **Reference-to-video** (`content[]` has reference_*): ratio is
     *     optional and defaults to `adaptive`. LLM may also pass a
     *     concrete ratio.
     *
     * `resume` doesn't need this (it polls only — the original submit
     * already happened). `regenerate` reuses it (it rebuilds content[]
     * from the same arguments).
     *
     * @param  array<int, array<string, mixed>> $content
     */
    public static function resolveAspectRatio(array $content, string $llmSupplied): string
    {
        $hasNonText     = false;
        $hasFrameImages = false;
        foreach ($content as $item) {
            $type = is_string($item['type'] ?? null) ? $item['type'] : '';
            if ($type !== 'text') {
                $hasNonText = true;
            }
            $role = is_string($item['role'] ?? null) ? $item['role'] : '';
            if ($role === 'first_frame' || $role === 'last_frame') {
                $hasFrameImages = true;
            }
        }

        if (!$hasNonText) {
            // Text-to-video: ratio is required, must be a concrete value.
            // If LLM passed `adaptive` (or anything else invalid for t2v)
            // fall back to `16:9`.
            if ($llmSupplied !== '' && in_array($llmSupplied, self::TEXT_ONLY_ASPECT_RATIOS, true)) {
                return $llmSupplied;
            }
            return '16:9';
        }

        if ($hasFrameImages) {
            // Image-to-video: H3 forces `adaptive` server-side. Force it
            // here so the request body matches what upstream will see.
            return 'adaptive';
        }

        // Reference-to-video: `adaptive` is the spec default but a
        // concrete ratio from the LLM is honoured.
        if ($llmSupplied !== '' && in_array($llmSupplied, self::ASPECT_RATIOS, true)) {
            return $llmSupplied;
        }
        return 'adaptive';
    }

    /**
     * Resolve the effective resolution. Only `768P` and `2K` are valid
     * under H3; no per-model matrix.
     *
     * @param array<string, mixed> $arguments
     */
    public static function resolveResolution(array $arguments): string
    {
        $supplied = trim((string) ($arguments['resolution'] ?? ''));
        if ($supplied !== '' && in_array($supplied, self::RESOLUTIONS, true)) {
            return $supplied;
        }
        // Default to 768P — cheaper than 2K, and the only resolution the
        // regeneration endpoint accepts as source. Operators can pick 2K
        // for higher-quality first-pass.
        return '768P';
    }
}
