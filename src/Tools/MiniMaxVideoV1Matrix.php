<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

/**
 * Resolution × duration × model matrix for the v1 MiniMax video
 * endpoint at `POST /v1/video_generation`. Source of truth for what
 * the v1 family accepts; the {@see MiniMaxVideoV1Tool} validator
 * delegates every (model, resolution, duration) triple to
 * {@see explain()} before any upstream call.
 *
 * Sourced from the OpenAPI specs at
 *   https://platform.minimax.io/docs/api-reference/video-generation-t2v
 *   https://platform.minimax.io/docs/api-reference/video-generation-i2v
 *
 * The matrix also lists the i2v-capable models (`MiniMax-Hailuo-2.3-Fast`,
 * `I2V-01-Director`, `I2V-01-live`, `I2V-01`) so the validator can
 * reject operator-typed i2v calls with a clear "i2v code path not yet
 * shipped" message instead of letting the upstream silently 400. The
 * i2v code path itself will land in a follow-up PR — at that point
 * the matrix stops rejecting `first_frame_image` and the t2v code
 * path branches into the i2v submit body.
 *
 * Models recognised by the v1 endpoint, in declaration order:
 *   - t2v: `MiniMax-Hailuo-2.3`, `MiniMax-Hailuo-02`, `T2V-01-Director`, `T2V-01`
 *   - i2v: `MiniMax-Hailuo-2.3`, `MiniMax-Hailuo-2.3-Fast`, `MiniMax-Hailuo-02`,
 *          `I2V-01-Director`, `I2V-01-live`, `I2V-01`
 */
final class MiniMaxVideoV1Matrix
{
    /**
     * All resolution values that appear anywhere in the v1 matrix.
     * Exposed as a public class constant so the `#[ToolParameter]`
     * `enum` attribute on `MiniMaxVideoV1Tool` can reference it
     * directly without duplicating the literal list.
     *
     * @var list<string>
     */
    public const RESOLUTIONS = ['512P', '720P', '768P', '1080P'];

    /**
     * Allowed duration values under v1. The OpenAPI exposes both
     * `6` and `10` as integers, but the v1 tool's `duration_seconds`
     * attribute accepts strings (the operator-facing field has been
     * template-typed as `string` since 1.0.0 and changing the type
     * would break existing agent definitions).
     *
     * @var list<string>
     */
    public const DURATIONS = ['6', '10'];

    /**
     * Per-upstream-matrix allow-list of (resolution, duration) pairs
     * for each supported model. Empty list entries denote resolutions
     * the model accepts but the duration list is empty (rejected with
     * a clear "10s not supported on this resolution" message).
     *
     * Lookup: `$rules[$model][$resolution]` returns the list of legal
     * durations. Empty list = forbidden combination.
     *
     * @var array<string, array<string, list<int>>>
     */
    private const DURATION_RULES = [
        // t2v family
        'MiniMax-Hailuo-2.3'  => ['720P' => [6], '768P' => [6, 10], '1080P' => [6]],
        'MiniMax-Hailuo-02'   => ['720P' => [6], '768P' => [6, 10], '1080P' => [6]],
        'T2V-01-Director'     => ['720P' => [6], '768P' => [],     '1080P' => [6]],
        'T2V-01'              => ['720P' => [6], '768P' => [],     '1080P' => [6]],
        // i2v family (matrix only — code path ships in a follow-up)
        'MiniMax-Hailuo-2.3-Fast' => ['720P' => [6], '768P' => [6, 10], '1080P' => [6]],
        'I2V-01-Director'      => ['720P' => [6], '768P' => [],    '1080P' => [6]],
        'I2V-01-live'          => ['720P' => [6], '768P' => [],    '1080P' => [6]],
        'I2V-01'               => ['720P' => [6], '768P' => [],    '1080P' => [6]],
    ];

    /**
     * Models recognised by the v1 endpoint. Eight total — four t2v
     * and four i2v. Subset of {@see DURATION_RULES} keys.
     *
     * @var list<string>
     */
    public const SUPPORTED_MODELS = [
        'MiniMax-Hailuo-2.3',
        'MiniMax-Hailuo-02',
        'T2V-01-Director',
        'T2V-01',
        'MiniMax-Hailuo-2.3-Fast',
        'I2V-01-Director',
        'I2V-01-live',
        'I2V-01',
    ];

    /**
     * Models whose submit code path is implemented in this build.
     * The i2v siblings appear in the matrix (so the validator catches
     * operator typos) but a call against one is rejected with
     * `not yet shipped` until the i2v code path lands.
     *
     * @var list<string>
     */
    public const IMPLEMENTED_MODELS = [
        'MiniMax-Hailuo-2.3',
        'MiniMax-Hailuo-02',
        'T2V-01-Director',
        'T2V-01',
    ];

    /**
     * Verify the (model, resolution, duration) triple against the
     * matrix. Returns `null` when the combination is valid; a
     * human-readable error message otherwise.
     *
     * The error message carries an actionable hint (`At 10s, only 768P
     * is supported`) for the most common trap (1080P + 10s) so the
     * LLM can self-correct without a second round-trip.
     */
    public static function explain(string $model, string $resolution, int $duration): ?string
    {
        if (!in_array($model, self::SUPPORTED_MODELS, true)) {
            return sprintf(
                'model "%s" is not a supported MiniMax v1 video model. Allowed: %s.',
                $model,
                implode(', ', self::SUPPORTED_MODELS),
            );
        }

        if (!in_array($model, self::IMPLEMENTED_MODELS, true)) {
            return sprintf(
                'model "%s" is recognised by the v1 matrix but the submit code path is not yet shipped in minimax:video_v1. '
                . 'Implemented models in this build: %s.',
                $model,
                implode(', ', self::IMPLEMENTED_MODELS),
            );
        }

        $rules = self::DURATION_RULES[$model];
        if (!array_key_exists($resolution, $rules)) {
            return sprintf(
                'resolution "%s" is not supported by model "%s". Allowed: %s.',
                $resolution,
                $model,
                implode(', ', array_keys($rules)),
            );
        }

        if (!in_array($duration, $rules[$resolution], true)) {
            $allowedDurations = $rules[$resolution];
            sort($allowedDurations);
            $allowedList = implode('/', $allowedDurations) . 's';

            $hint = ($resolution === '1080P' && $duration === 10)
                ? ' At 10s, only 768P is supported.'
                : '';

            return sprintf(
                'resolution "%s" + duration_seconds "%d" is not a valid combination for model "%s". '
                . 'Allowed durations at this resolution: %s.%s',
                $resolution,
                $duration,
                $model,
                $allowedList,
                $hint,
            );
        }

        return null;
    }
}
