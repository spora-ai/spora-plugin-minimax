<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Psr\Log\LoggerInterface;
use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;

/**
 * Polls a previously-submitted H3 task until it reaches a terminal
 * state. Stateless — every method is static. Split off the main
 * `MiniMaxVideoTool` to keep its method count under Sonar's 20-method
 * threshold (S1448) and to reduce the polling loop's cognitive
 * complexity (S3776).
 */
final class MiniMaxVideoPoller
{
    /**
     * Per-poll HTTP timeout. Bounds the single `GET /v2/query/...`
     * request so a stalled probe can't outlive the loop's overall
     * deadline — without this, a stalled request makes the
     * `timed_out` envelope never reachable even when wall-clock time
     * has already exceeded `poll_timeout_seconds`.
     */
    public const POLL_REQUEST_TIMEOUT_SECONDS = 30;

    /**
     * Hard floor for `poll_interval_seconds`. Operators can configure
     * the setting lower, but the loop clamps below this so a zero / negative
     * value can't spin a busy poll against a stalled endpoint.
     */
    public const MIN_POLL_INTERVAL_SECONDS = 1;

    /**
     * Hard ceiling for `poll_interval_seconds`. The setting accepts
     * any positive number, but >10 minutes between status probes is
     * almost always an operator typo that masks a stuck task.
     */
    public const MAX_POLL_INTERVAL_SECONDS = 600;

    /**
     * Polls `/v2/query/video_generation/{task_id}` until the task
     * reaches a terminal state or the configured deadline expires.
     *
     * @param  ?LoggerInterface         $logger      Optional PSR-3 logger for
     *                                              debug / info / warning
     *                                              emission at every probe
     *                                              boundary. Pass `null` in
     *                                              tests that don't need log
     *                                              assertions.
     *
     * @return array{success: bool, data: array<string, mixed>}
     */
    public static function pollUntilDone(
        MiniMaxHttpClient $client,
        string $taskId,
        int $pollTimeout,
        int $intervalSeconds,
        ?LoggerInterface $logger,
        ?string $expectKind,
    ): array {
        $intervalSeconds = max(self::MIN_POLL_INTERVAL_SECONDS, min(self::MAX_POLL_INTERVAL_SECONDS, $intervalSeconds));
        $deadline        = microtime(true) + max(10, $pollTimeout);

        $logger?->info('MiniMaxVideoTool: poll loop started', [
            'task_id'      => $taskId,
            'interval'     => $intervalSeconds,
            'poll_timeout' => $pollTimeout,
            'expect_kind'  => $expectKind,
        ]);

        while (true) {
            if (microtime(true) >= $deadline) {
                return self::timeoutEnvelope($taskId, $pollTimeout);
            }

            $response = self::probeOnce($client, $taskId, $deadline);
            $terminal = self::classifyTerminalState($taskId, $response, $expectKind, $logger);
            if ($terminal !== null) {
                return $terminal;
            }

            self::sleepUntilNextProbe($deadline, $intervalSeconds, $taskId, $response, $logger);
        }
    }

    /**
     * Single probe against `/v2/query/video_generation/{task_id}`. Caps
     * the per-request HTTP timeout to the remaining deadline.
     *
     * @return array<string, mixed>
     */
    private static function probeOnce(MiniMaxHttpClient $client, string $taskId, float $deadline): array
    {
        $remainingSeconds    = (int) ceil($deadline - microtime(true));
        $effectivePerRequest = max(1, min($remainingSeconds, self::POLL_REQUEST_TIMEOUT_SECONDS));

        return $client->getJson(
            '/v2/query/video_generation/' . $taskId,
            [],
            timeoutSeconds: $effectivePerRequest,
        );
    }

    /**
     * Inspect a probe response. Returns a terminal envelope if the task
     * reached `succeeded` / `failed` / `cancelled`; otherwise `null`
     * (the loop will sleep and re-probe).
     *
     * @param  array<string, mixed> $response
     * @return array{success: bool, data: array<string, mixed>}|null
     */
    private static function classifyTerminalState(
        string $taskId,
        array $response,
        ?string $expectKind,
        ?LoggerInterface $logger,
    ): ?array {
        $task   = is_array($response['task'] ?? null) ? $response['task'] : [];
        $status = is_string($task['status'] ?? null) ? $task['status'] : '';

        if ($status === 'succeeded') {
            if ($expectKind !== null && isset($task['task_type']) && $task['task_type'] !== $expectKind) {
                $logger?->warning('MiniMaxVideoTool: unexpected task_type on success', [
                    'task_id'  => $taskId,
                    'expected' => $expectKind,
                    'actual'   => $task['task_type'],
                ]);
            }
            return ['success' => true, 'data' => $task];
        }

        if ($status === 'failed') {
            $err  = is_array($task['error'] ?? null) ? $task['error'] : [];
            $code = is_string($err['code'] ?? null) ? $err['code'] : 'unknown';
            $msg  = is_string($err['message'] ?? null) ? $err['message'] : 'video task failed';
            throw new MiniMaxApiException("MiniMax H3 task failed (code={$code}): {$msg}", 0, $task);
        }

        if ($status === 'cancelled') {
            throw new MiniMaxApiException('MiniMax H3 task was cancelled.', 0, $task);
        }

        return null;
    }

    /**
     * Sleep until the next probe. The sleep is capped to the remaining
     * deadline so `timed_out` fires within `poll_timeout_seconds`
     * instead of up to one full interval late.
     *
     * @param array<string, mixed> $response
     */
    private static function sleepUntilNextProbe(
        float $deadline,
        int $intervalSeconds,
        string $taskId,
        array $response,
        ?LoggerInterface $logger,
    ): void {
        $task   = is_array($response['task'] ?? null) ? $response['task'] : [];
        $status = is_string($task['status'] ?? null) ? $task['status'] : '';

        $logger?->debug('MiniMaxVideoTool: still processing, sleeping', [
            'task_id'  => $taskId,
            'status'   => $status,
            'interval' => $intervalSeconds,
        ]);

        $remainingAfterProbe = (int) ceil($deadline - microtime(true));
        $sleepFor            = max(1, min($intervalSeconds, $remainingAfterProbe));
        sleep($sleepFor);
    }

    /**
     * Format the timeout envelope returned by {@see pollUntilDone}
     * when the configured deadline expires before a terminal state.
     *
     * @return array{success: false, data: array<string, mixed>}
     */
    private static function timeoutEnvelope(string $taskId, int $pollTimeout): array
    {
        return [
            'success' => false,
            'data'    => [
                'task_id'   => $taskId,
                'status'    => 'still_running',
                'timed_out' => true,
                'content'   => sprintf(
                    'H3 task did not finish within %ds (task_id=%s). The task is still running on MiniMax\'s side and is billable. '
                    . 'Increase `poll_timeout_seconds` and call `minimax_video_minimax(action: "resume", task_id: "%s")` to keep waiting, '
                    . 'or abandon it and accept the billed quota.',
                    $pollTimeout,
                    $taskId,
                    $taskId,
                ),
            ],
        ];
    }
}
