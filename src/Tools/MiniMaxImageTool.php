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
 * Generates an image from a text prompt via MiniMax's image_generation API.
 * Returns the upstream image URL (expires in 24h — the LLM must consume it
 * in the same task).
 */
#[Tool(
    name: 'image',
    description: 'Generate an image from a text prompt via MiniMax. Returns a URL that is valid for 24 hours.',
    displayName: 'MiniMax Image',
    category: 'generation',
)]
#[ToolOperation(name: 'generate', description: 'Generate an image from a text prompt', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'plugin.minimax.image.api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimax.io (shared across all MiniMax tools).',
    required: true,
)]
#[ToolSetting(
    key: 'plugin.minimax.image.base_url',
    label: 'Base URL',
    type: 'text',
    description: 'Override the MiniMax base URL (default: https://api.minimax.io).',
    default: 'https://api.minimax.io',
)]
#[ToolSetting(
    key: 'plugin.minimax.image.model',
    label: 'Model',
    type: 'text',
    description: 'Image model id (default: image-01).',
    default: 'image-01',
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'The text prompt describing the image to generate (max 1500 characters).',
    required: true,
    maximum: 1500,
)]
#[ToolParameter(
    name: 'aspect_ratio',
    type: 'string',
    description: 'Aspect ratio of the generated image.',
    required: false,
    enum: ['1:1', '16:9', '4:3', '3:2', '2:3', '3:4', '9:16', '21:9'],
    default: '1:1',
)]
final class MiniMaxImageTool extends AbstractTool
{
    private const PROVIDER = 'image';
    private const DEFAULT_MODEL = 'image-01';

    public function __construct(
        private readonly ToolConfigService   $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly MiniMaxLogWriter    $logWriter,
        private readonly ?LoggerInterface    $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return $this->generate($arguments, $agentId, $userId, $taskId);
    }

    public function describeAction(array $arguments): string
    {
        $prompt = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);
        return "Generate image for prompt: '{$prompt}'";
    }

    public function generate(array $arguments, int $agentId, ?int $userId, ?int $taskId): ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $aspectRatio = trim((string) ($arguments['aspect_ratio'] ?? '1:1'));

        if ($prompt === '') {
            return $this->fail('Prompt cannot be empty.', self::PROVIDER, $arguments, $agentId, $userId);
        }
        if (mb_strlen($prompt) > 1500) {
            return $this->fail('Prompt exceeds the 1500-character MiniMax limit.', self::PROVIDER, $arguments, $agentId, $userId);
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = MiniMaxSettings::apiKey(self::PROVIDER, $settings);
        if ($apiKey === '') {
            return $this->fail(
                'MiniMax API key is not configured for this agent. Edit the MiniMax Image settings.',
                self::PROVIDER,
                $arguments,
                $agentId,
                $userId,
            );
        }

        $client = new MiniMaxHttpClient(
            $this->httpClient,
            $apiKey,
            MiniMaxSettings::baseUrl(self::PROVIDER, $settings),
            timeoutSeconds: 60,
            logger: $this->logger,
        );

        $qualifiedName = 'minimax:' . 'image';

        try {
            $response = $client->postJson('/v1/image_generation', [
                'model'        => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
                'prompt'       => $prompt,
                'aspect_ratio' => $aspectRatio,
                'response_format' => 'url',
            ]);

            $urls = $response['data']['image_urls'] ?? [];
            if (!is_array($urls) || $urls === []) {
                $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $response, false, 'No image URLs returned', $userId, $agentId);
                return $this->fail('MiniMax returned no image URLs.', self::PROVIDER, $arguments, $agentId, $userId);
            }

            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $response, true, null, $userId, $agentId);

            $list = array_map(static fn($i, $u) => '[' . ($i + 1) . "] {$u}", array_keys($urls), $urls);
            $urlsBlock = implode("\n", $list);
            $content = "Generated image for prompt: \"{$prompt}\"\n\nImage URLs (valid for 24 hours):\n{$urlsBlock}";

            return new ToolResult(true, $content, [
                'image_urls'  => array_values($urls),
                'model'       => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            ]);
        } catch (MiniMaxApiException $e) {
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return $this->fail($e->getMessage(), self::PROVIDER, $arguments, $agentId, $userId);
        } catch (Throwable $e) {
            $this->logger?->error('MiniMaxImageTool: unexpected exception', ['exception' => $e]);
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return $this->fail('Image generation failed: ' . $e->getMessage(), self::PROVIDER, $arguments, $agentId, $userId);
        }
    }

    private function fail(string $message, string $provider, array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return new ToolResult(false, $message);
    }
}
