<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Psr\Log\LoggerInterface;
use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Synthesizes speech from text via MiniMax's t2a_v2 (text-to-audio) API.
 * Returns the upstream audio URL (24h expiry) if a CDN URL is available;
 * otherwise embeds the audio bytes inline.
 */
#[Tool(
    name: 'speech',
    description: 'Synthesize speech from text via MiniMax (text-to-speech). Returns a CDN URL valid for 24 hours when available, otherwise inline hex-encoded audio.',
    displayName: 'MiniMax Speech',
    category: 'generation',
)]
#[ToolOperation(name: 'synthesize', description: 'Synthesize speech from text', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'plugin.minimax.speech.api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimax.io (shared across all MiniMax tools).',
    required: true,
)]
#[ToolSetting(
    key: 'plugin.minimax.speech.base_url',
    label: 'Base URL',
    type: 'text',
    description: 'MiniMax base URL. Default is the Global endpoint (https://api.minimax.io). For China-region, set to https://api.minimaxi.com.',
    default: 'https://api.minimax.io',
)]
#[ToolSetting(
    key: 'plugin.minimax.speech.model',
    label: 'Model',
    type: 'text',
    description: 'TTS model id (default: speech-2.8-hd).',
    default: 'speech-2.8-hd',
)]
#[ToolSetting(
    key: 'plugin.minimax.speech.voice_id',
    label: 'Default voice',
    type: 'text',
    description: 'Default voice id from the MiniMax voice library (overridden by the `voice_id` parameter).',
    default: 'English_PassionateWarrior',
)]
#[ToolParameter(
    name: 'text',
    type: 'string',
    description: 'The text to synthesize (max 10000 characters).',
    required: true,
    maximum: 10000,
)]
#[ToolParameter(
    name: 'voice_id',
    type: 'string',
    description: 'Override the default voice id for this call.',
    required: false,
)]
#[ToolParameter(
    name: 'speed',
    type: 'number',
    description: 'Speech speed multiplier (0.5 - 2.0).',
    required: false,
    minimum: 0.5,
    maximum: 2.0,
    default: 1.0,
)]
final class MiniMaxSpeechTool extends AbstractTool
{
    private const PROVIDER = 'speech';
    private const DEFAULT_MODEL = 'speech-2.8-hd';

    public function __construct(
        private readonly ToolConfigService   $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly MiniMaxLogWriter    $logWriter,
        private readonly ?LoggerInterface    $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return $this->synthesize($arguments, $agentId, $userId, $taskId);
    }

    public function describeAction(array $arguments): string
    {
        $text = mb_substr(trim((string) ($arguments['text'] ?? '')), 0, 80);
        return "Synthesize speech for: '{$text}'";
    }

    public function synthesize(array $arguments, int $agentId, ?int $userId, ?int $taskId): ToolResult
    {
        $text = trim((string) ($arguments['text'] ?? ''));
        $voiceOverride = trim((string) ($arguments['voice_id'] ?? ''));
        $speed = (float) ($arguments['speed'] ?? 1.0);

        if ($text === '') {
            return new ToolResult(false, 'Text cannot be empty.');
        }
        if (mb_strlen($text) > 10000) {
            return new ToolResult(false, 'Text exceeds the 10000-character MiniMax limit.');
        }
        if ($speed < 0.5 || $speed > 2.0) {
            return new ToolResult(false, 'Speed must be between 0.5 and 2.0.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = MiniMaxSettings::apiKey(self::PROVIDER, $settings);
        if ($apiKey === '') {
            return new ToolResult(false, 'MiniMax API key is not configured for this agent. Edit the MiniMax Speech settings.');
        }

        // Resolution order: LLM-provided `voice_id` (per call) > operator-configured
        // setting (`plugin.minimax.speech.voice_id`) > hard-coded default. The
        // LLM-visible #[ToolParameter] lets the model pick a voice per call; the
        // operator setting is the fallback when the model doesn't pass one.
        $configuredVoice = is_string($settings['plugin.minimax.speech.voice_id'] ?? null)
            ? trim((string) $settings['plugin.minimax.speech.voice_id'])
            : '';
        $voiceId = $voiceOverride !== '' ? $voiceOverride : ($configuredVoice !== '' ? $configuredVoice : 'English_PassionateWarrior');

        $client = new MiniMaxHttpClient(
            $this->httpClient,
            $apiKey,
            MiniMaxSettings::baseUrl(self::PROVIDER, $settings),
            timeoutSeconds: 60,
            logger: $this->logger,
        );

        $qualifiedName = 'minimax:' . 'speech';

        try {
            $response = $client->postJson('/v1/t2a_v2', [
                'model' => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
                'text'  => $text,
                'voice_setting' => [
                    'voice_id' => $voiceId,
                    'speed'    => $speed,
                ],
                'audio_setting' => [
                    'sample_rate' => 32000,
                    'bitrate'     => 128000,
                    'format'      => 'mp3',
                ],
            ]);

            $hexAudio = $response['data']['audio'] ?? null;
            $audioUrl = $response['data']['audio_url'] ?? null;
            $lengthMs = $response['extra_info']['audio_length'] ?? null;
            $sizeBytes = $response['extra_info']['audio_size'] ?? null;
            $usageChars = $response['extra_info']['usage_characters'] ?? null;

            if (!is_string($hexAudio) && !is_string($audioUrl)) {
                $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $response, false, 'No audio in response', $userId, $agentId);
                return new ToolResult(false, 'MiniMax returned no audio data.');
            }

            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $response, true, null, $userId, $agentId);

            $stats = [];
            if (is_int($lengthMs) || (is_string($lengthMs) && ctype_digit($lengthMs))) {
                $stats[] = round(((int) $lengthMs) / 1000, 2) . 's';
            }
            if (is_int($sizeBytes) || (is_string($sizeBytes) && ctype_digit($sizeBytes))) {
                $stats[] = round(((int) $sizeBytes) / 1024, 1) . ' KB';
            }
            if (is_int($usageChars) || (is_string($usageChars) && ctype_digit($usageChars))) {
                $stats[] = $usageChars . ' chars';
            }
            $statsLine = $stats === [] ? '' : ' (' . implode(', ', $stats) . ')';

            if (is_string($audioUrl) && $audioUrl !== '') {
                $content = "Synthesized speech{$statsLine}.\n\nCDN URL (valid 24h): {$audioUrl}";
                return new ToolResult(true, $content, ['audio_url' => $audioUrl, 'voice_id' => $voiceId]);
            }

            $byteCount = is_string($hexAudio) ? (int) (strlen($hexAudio) / 2) : 0;
            $content = "Synthesized speech{$statsLine}.\n\nAudio payload: {$byteCount} bytes (hex-encoded, inline). Voice: {$voiceId}.";
            return new ToolResult(true, $content, ['audio_bytes' => $byteCount, 'voice_id' => $voiceId]);
        } catch (MiniMaxApiException $e) {
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger?->error('MiniMaxSpeechTool: unexpected exception', ['exception' => $e]);
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, 'Speech synthesis failed: ' . $e->getMessage());
        }
    }
}
