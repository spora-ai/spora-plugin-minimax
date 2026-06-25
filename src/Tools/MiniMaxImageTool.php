<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Psr\Log\LoggerInterface;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;
use Spora\Plugins\MiniMax\Support\MiniMaxToolContext;
use Spora\Plugins\MiniMax\Support\MiniMaxToolSupport;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
    description: 'MiniMax base URL. Default is the Global endpoint (https://api.minimax.io). For China-region, set to https://api.minimaxi.com.',
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
    private const QUALIFIED_NAME = 'minimax:image';
    private const TIMEOUT_SECONDS = 60;
    private const TOOL_LABEL = 'Image generation';

    public function __construct(
        ToolConfigService   $configService,
        HttpClientInterface $httpClient,
        MiniMaxLogWriter    $logWriter,
        ?LoggerInterface    $logger = null,
        ?MiniMaxToolSupport $support = null,
    ) {
        // Constructor parameters are consumed once to build the support; they
        // are intentionally not stored as fields (SonarQube php:S1068) since
        // every read goes through the support.
        $this->support = $support ?? new MiniMaxToolSupport($configService, $httpClient, $logWriter, $logger);
    }

    private MiniMaxToolSupport $support;

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $validation = $this->validateArguments($arguments);
        if ($validation !== null) {
            return $validation;
        }

        $ctx = $this->support->prepare(
            toolClass: static::class,
            provider: self::PROVIDER,
            qualifiedName: self::QUALIFIED_NAME,
            arguments: $arguments,
            agentId: $agentId,
            userId: $userId,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );
        if ($ctx instanceof ToolResult) {
            return $ctx;
        }

        return $this->support->run($ctx, self::TOOL_LABEL, fn(MiniMaxToolContext $c) => $this->doGenerate($c, $arguments));
    }

    public function describeAction(array $arguments): string
    {
        $prompt = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);
        return "Generate image for prompt: '{$prompt}'";
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function validateArguments(array $arguments): ?ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $errors = [];
        if ($prompt === '') {
            $errors[] = 'Prompt cannot be empty.';
        }
        if (mb_strlen($prompt) > 1500) {
            $errors[] = 'Prompt exceeds the 1500-character MiniMax limit.';
        }
        return $errors === [] ? null : new ToolResult(false, implode(' ', $errors));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function doGenerate(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $prompt      = trim((string) ($arguments['prompt'] ?? ''));
        $aspectRatio = trim((string) ($arguments['aspect_ratio'] ?? '1:1'));

        /** @var MiniMaxHttpClient $client */
        $client = $ctx->client;
        $response = $client->postJson('/v1/image_generation', [
            'model'        => MiniMaxSettings::model(self::PROVIDER, $ctx->settings, self::DEFAULT_MODEL),
            'prompt'       => $prompt,
            'aspect_ratio' => $aspectRatio,
            'response_format' => 'url',
        ]);

        $urls = $response['data']['image_urls'] ?? [];
        if (!is_array($urls) || $urls === []) {
            $this->support->logFailure($ctx, $response, 'No image URLs returned');
            return new ToolResult(false, 'MiniMax returned no image URLs.');
        }

        $this->support->logSuccess($ctx, $response);

        $list = array_map(static fn($i, $u) => '[' . ($i + 1) . "] {$u}", array_keys($urls), $urls);
        $urlsBlock = implode("\n", $list);
        $content = "Generated image for prompt: \"{$prompt}\"\n\nImage URLs (valid for 24 hours):\n{$urlsBlock}";

        return new ToolResult(true, $content, [
            'image_urls'  => array_values($urls),
            'model'       => MiniMaxSettings::model(self::PROVIDER, $ctx->settings, self::DEFAULT_MODEL),
        ]);
    }
}
