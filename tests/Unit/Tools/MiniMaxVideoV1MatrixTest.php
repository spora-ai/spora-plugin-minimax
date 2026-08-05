<?php

declare(strict_types=1);

use Spora\Plugins\MiniMax\Tools\MiniMaxVideoV1Matrix;

/**
 * Table-driven coverage of the v1 (model, resolution, duration) matrix.
 * Each row exercises one rejection rule; the matrix is the single source
 * of truth per the v1 OpenAPI specs at
 *   https://platform.minimax.io/docs/api-reference/video-generation-t2v
 *   https://platform.minimax.io/docs/api-reference/video-generation-i2v
 */

dataset('v1_matrix_valid_triples', [
    'Hailuo-2.3 + 768P + 6s'  => ['MiniMax-Hailuo-2.3', '768P', 6],
    'Hailuo-2.3 + 768P + 10s' => ['MiniMax-Hailuo-2.3', '768P', 10],
    'Hailuo-2.3 + 1080P + 6s' => ['MiniMax-Hailuo-2.3', '1080P', 6],
    'Hailuo-2.3 + 720P + 6s'  => ['MiniMax-Hailuo-2.3', '720P', 6],
    'Hailuo-02 + 768P + 10s'  => ['MiniMax-Hailuo-02', '768P', 10],
    'Hailuo-02 + 1080P + 6s'  => ['MiniMax-Hailuo-02', '1080P', 6],
    'T2V-01-Director + 720P + 6s' => ['T2V-01-Director', '720P', 6],
    'T2V-01 + 720P + 6s'      => ['T2V-01', '720P', 6],
    // Note: i2v models (Hailuo-2.3-Fast, I2V-01-Director, I2V-01-live, I2V-01)
    // appear in the matrix (matrix-only validation) but the i2v code path
    // is rejected by `explain()` with "code path is not yet shipped".
    // Tests for that branch live in `v1_matrix_invalid_triples`.
]);

dataset('v1_matrix_invalid_triples', [
    'unknown model' => [
        'MiniMax-H9-Nonsense', '768P', 6,
        'not a supported MiniMax v1 video model',
    ],
    'unsupported i2v model (matrix-only, not implemented)' => [
        'I2V-01-live', '720P', 6,
        'code path is not yet shipped',
    ],
    'resolution outside any matrix' => [
        'MiniMax-Hailuo-2.3', '4K', 6,
        '4K',
    ],
    'Hailuo-2.3 + 1080P + 10s (1080P only supports 6s)' => [
        'MiniMax-Hailuo-2.3', '1080P', 10,
        'At 10s, only 768P is supported',
    ],
    'T2V-01 + 768P + 6s (T2V-01 has no 768P)' => [
        'T2V-01', '768P', 6,
        'not a valid combination',
    ],
    'Hailuo-02 + 512P + 6s — 512P is not in MiniMax-Hailuo-02\'s matrix at all' => [
        'MiniMax-Hailuo-02', '512P', 6,
        'resolution "512P" is not supported by model "MiniMax-Hailuo-02"',
    ],
    'I2V-01 + 1080P + 6s (I2V-01 only supports 720P) — but i2v models short-circuit on "not yet shipped" first' => [
        'I2V-01', '1080P', 6,
        'code path is not yet shipped',
    ],
    'I2V-01-Director + 768P + 10s (768P 10s not supported on I2V-01-Director) — but i2v models short-circuit on "not yet shipped" first' => [
        'I2V-01-Director', '768P', 10,
        'code path is not yet shipped',
    ],
]);

it('accepts every (model, resolution, duration) triple that the v1 matrix permits', function (
    string $model,
    string $resolution,
    int $duration,
): void {
    expect(MiniMaxVideoV1Matrix::explain($model, $resolution, $duration))->toBeNull();
})->with('v1_matrix_valid_triples');

it('rejects every (model, resolution, duration) triple the v1 matrix forbids', function (
    string $model,
    string $resolution,
    int $duration,
    string $expectedFragment,
): void {
    $message = MiniMaxVideoV1Matrix::explain($model, $resolution, $duration);
    expect($message)->not->toBeNull()
        ->and($message)->toContain($expectedFragment);
})->with('v1_matrix_invalid_triples');

it('exposes the implementation subset in IMPLEMENTED_MODELS', function (): void {
    // The four t2v models are implemented; the four i2v siblings are
    // matrix-only for now (the i2v code path is a follow-up PR).
    expect(MiniMaxVideoV1Matrix::IMPLEMENTED_MODELS)->toBe([
        'MiniMax-Hailuo-2.3',
        'MiniMax-Hailuo-02',
        'T2V-01-Director',
        'T2V-01',
    ]);
});

it('exposes all eight v1 models in SUPPORTED_MODELS', function (): void {
    expect(MiniMaxVideoV1Matrix::SUPPORTED_MODELS)->toContain('MiniMax-Hailuo-2.3')
        ->toContain('MiniMax-Hailuo-2.3-Fast')
        ->toContain('MiniMax-Hailuo-02')
        ->toContain('T2V-01-Director')
        ->toContain('T2V-01')
        ->toContain('I2V-01-Director')
        ->toContain('I2V-01-live')
        ->toContain('I2V-01');
});

it('exposes the four v1 resolution literals in RESOLUTIONS', function (): void {
    expect(MiniMaxVideoV1Matrix::RESOLUTIONS)->toBe(['512P', '720P', '768P', '1080P']);
});
