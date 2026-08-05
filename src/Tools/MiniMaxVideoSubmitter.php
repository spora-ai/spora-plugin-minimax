<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;

/**
 * Builds and POSTs the v2 H3 video endpoint request bodies. Stateless —
 * every method is static. Split off the main `MiniMaxVideoTool` to keep
 * its method count under Sonar's 20-method threshold (S1448).
 */
final class MiniMaxVideoSubmitter
{
    private const PROVIDER      = 'video';
    private const DEFAULT_MODEL = 'MiniMax-H3';

    /**
     * Build and POST the v2 `video_generation` body.
     *
     * @param  list<array<string, mixed>> $content
     * @param  array<string, mixed>       $settings
     */
    public static function submitTask(
        MiniMaxHttpClient $client,
        array $settings,
        array $content,
        int $duration,
        string $resolution,
        string $ratio,
        int $timeoutSeconds,
    ): string {
        $body = [
            'model'      => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'content'    => $content,
            'duration'   => $duration,
            'resolution' => $resolution,
            'ratio'      => $ratio,
        ];

        return self::postAndExtractTaskId($client, '/v2/video_generation', $body, $timeoutSeconds);
    }

    /**
     * Build and POST the v2 `h3_context_ir` body. Same shape minus
     * `resolution` (H3-Context-IR returns a prompt, not a video, so
     * resolution doesn't apply).
     *
     * @param  list<array<string, mixed>> $content
     * @param  array<string, mixed>       $settings
     */
    public static function submitEnhancePromptTask(
        MiniMaxHttpClient $client,
        array $settings,
        array $content,
        int $duration,
        string $ratio,
        int $timeoutSeconds,
    ): string {
        $body = [
            'model'    => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'content'  => $content,
            'duration' => $duration,
            'ratio'    => $ratio,
        ];

        return self::postAndExtractTaskId($client, '/v2/h3_context_ir', $body, $timeoutSeconds);
    }

    /**
     * Build and POST the v2 `video_regeneration` body. Per the spec,
     * `content` must reproduce the original generation's `content[]`
     * verbatim (the `text` is the FINAL prompt sent to the model, not
     * the user's original pre-Context-IR prompt) and append exactly
     * one `base_video` source.
     *
     * Only `resolution: '2K'` is supported upstream.
     *
     * @param  list<array<string, mixed>> $content
     * @param  array<string, mixed>       $settings
     */
    public static function submitRegenerationTask(
        MiniMaxHttpClient $client,
        array $settings,
        array $content,
        string $baseVideoUrl,
        int $timeoutSeconds,
    ): string {
        if ($baseVideoUrl === '') {
            throw new MiniMaxApiException(
                'regenerate: base_video_url is required — pass the previous 768P output\'s `download_url` (or `asset_url` from the Media Archive).',
                0,
            );
        }
        $content[] = [
            'type'      => 'video_url',
            'video_url' => ['url' => $baseVideoUrl],
            'role'      => 'base_video',
        ];

        $body = [
            'model'      => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'content'    => $content,
            'resolution' => '2K',
        ];

        return self::postAndExtractTaskId($client, '/v2/video_regeneration', $body, $timeoutSeconds);
    }

    /**
     * POST a body to one of the v2 video endpoints and extract the
     * returned `task_id`. Synthetic {@see MiniMaxApiException} on
     * transport / upstream error or when the response is missing the
     * id — both surface as `ToolResult::fail(...)` so the agent loop
     * can continue.
     *
     * @param  array<string, mixed> $body
     */
    public static function postAndExtractTaskId(
        MiniMaxHttpClient $client,
        string $endpoint,
        array $body,
        int $timeoutSeconds,
    ): string {
        $response = $client->postJson($endpoint, $body, timeoutSeconds: $timeoutSeconds);

        // Two response shapes are tolerated:
        //   - { "task_id": "task-..." }            (v2 create endpoints)
        //   - { "data": { "task_id": "task-..." }} (some upstream paths)
        $taskId = '';
        if (is_string($response['task_id'] ?? null)) {
            $taskId = $response['task_id'];
        } elseif (isset($response['data']['task_id']) && is_string($response['data']['task_id'])) {
            $taskId = $response['data']['task_id'];
        }

        if ($taskId === '') {
            throw new MiniMaxApiException(
                "MiniMax H3 submit ({$endpoint}) returned no task_id (model={$body['model']}).",
                0,
                $response,
            );
        }

        return $taskId;
    }
}
