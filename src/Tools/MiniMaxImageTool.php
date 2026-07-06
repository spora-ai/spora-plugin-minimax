<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Spora\Plugins\Concerns\StoresBinaryAssets;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;
use Spora\Plugins\MiniMax\Support\MiniMaxTool;
use Spora\Plugins\MiniMax\Support\MiniMaxToolContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\MediaEmbed;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Generates an image from a text prompt via MiniMax's image_generation API.
 * Returns the upstream image URL (expires in 24h — the LLM must consume it
 * in the same task).
 */
#[Tool(
    name: 'image',
    description: 'Generate an image from a text prompt. Returns an embedded image in the chat bubble.',
    displayName: 'MiniMax Image',
    category: 'generation',
)]
#[ToolOperation(name: 'generate', description: 'Generate an image from a text prompt', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimax.io (shared across all MiniMax tools).',
    required: true,
)]
#[ToolSetting(
    key: 'base_url',
    label: 'Base URL',
    type: 'text',
    description: 'MiniMax base URL. Default is the Global endpoint (https://api.minimax.io). For China-region, set to https://api.minimaxi.com.',
    default: 'https://api.minimax.io',
)]
#[ToolSetting(
    key: 'model',
    label: 'Model',
    type: 'text',
    description: 'Image model id (default: image-01).',
    default: 'image-01',
)]
#[ToolSetting(
    key: 'http_timeout_seconds',
    label: 'HTTP timeout (s)',
    type: 'number',
    description: 'Per-request timeout for the MiniMax API. Default 60 seconds.',
    default: '60',
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
final class MiniMaxImageTool extends MiniMaxTool
{
    use StoresBinaryAssets;

    protected const PROVIDER        = 'image';
    protected const DEFAULT_MODEL   = 'image-01';
    protected const QUALIFIED_NAME  = 'minimax:image';
    protected const TIMEOUT_SECONDS = 60;
    protected const TOOL_LABEL      = 'Image generation';

    public function __construct(
        \Spora\Services\ToolConfigService $configService,
        \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        \Spora\Plugins\MiniMax\Support\MiniMaxLogWriter $logWriter,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?\Spora\Plugins\MiniMax\Support\MiniMaxToolSupport $support = null,
        ?\Spora\Services\MediaArchive\MediaArchiveService $mediaArchive = null,
    ) {
        parent::__construct($configService, $httpClient, $logWriter, $logger, $support);
        $this->attachImageMediaArchive($mediaArchive);
    }

    /**
     * Wire the optional {@see MediaArchiveService} into the trait. The
     * opt-in constructor parameter is null when the operator hasn't
     * enabled the media archive; ignore that case silently.
     */
    private function attachImageMediaArchive(?\Spora\Services\MediaArchive\MediaArchiveService $archive): void
    {
        if ($archive !== null) {
            $this->setMediaArchive($archive);
        }
    }

    public function describeAction(array $arguments): string
    {
        $prompt = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);
        return "Generate image for prompt: '{$prompt}'";
    }

    /** @param array<string, mixed> $arguments */
    protected function validateArguments(array $arguments): ?ToolResult
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

    /** @param array<string, mixed> $arguments */
    protected function doWork(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $prompt      = trim((string) ($arguments['prompt'] ?? ''));
        $aspectRatio = trim((string) ($arguments['aspect_ratio'] ?? '1:1'));

        /** @var MiniMaxHttpClient $client */
        $client = $ctx->client;
        $timeout = $this->resolveTimeout('http_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);
        $response = $client->postJson(
            '/v1/image_generation',
            [
                'model'        => MiniMaxSettings::model(self::PROVIDER, $ctx->settings, self::DEFAULT_MODEL),
                'prompt'       => $prompt,
                'aspect_ratio' => $aspectRatio,
                'response_format' => 'url',
            ],
            timeoutSeconds: $timeout,
        );

        $urls = $response['data']['image_urls'] ?? [];
        if (!is_array($urls) || $urls === []) {
            $this->support->logFailure($ctx, $response, 'No image URLs returned');
            return new ToolResult(false, 'MiniMax returned no image URLs.');
        }

        $this->support->logSuccess($ctx, $response);

        // Coerce to a clean, sequentially-indexed list of URL strings. The
        // upstream API always returns int keys today, but defending against
        // non-int / non-string keys here keeps array_map()'s typed callback
        // from throwing a TypeError that would crash the tool instead of
        // surfacing as a clean ToolResult::fail.
        $cleanUrls = [];
        foreach ($urls as $u) {
            if (is_string($u) && $u !== '') {
                $cleanUrls[] = $u;
            }
        }
        if ($cleanUrls === []) {
            $this->support->logFailure($ctx, $response, 'Image URLs were non-string or empty');
            return new ToolResult(false, 'MiniMax returned image URLs that are not strings.');
        }

        // Use the shared MediaEmbed helper so the markdown format is
        // identical to what every other image-producing plugin emits.
        $lines = array_map(
            static fn(int $i, string $u): string => MediaEmbed::image($u, "Generated image " . ($i + 1) . ": {$prompt}"),
            array_keys($cleanUrls),
            $cleanUrls,
        );
        $count = count($cleanUrls);
        $content = "Generated {$count} image" . ($count === 1 ? '' : 's') . " for prompt: \"{$prompt}\"\n\n"
            . implode("\n\n", $lines);

        // Hand each upstream CDN URL to the Media Archive so the operator can
        // browse, filter, and download generated media from the admin UI.
        // Core fetches the bytes, sniffs MIME, and indexes a row per URL.
        // Ingest failures must never break the tool — log and continue.
        foreach ($cleanUrls as $cdnUrl) {
            try {
                $this->mediaArchive()->ingest(
                    url: $cdnUrl,
                    agentId: $ctx->agentId,
                    pluginSlug: 'minimax',
                    toolName: 'image',
                    prompt: $prompt,
                );
            } catch (Throwable $e) {
                $this->support->logger()?->warning('MediaArchive ingest failed (image)', [
                    'exception' => $e,
                    'url'       => $cdnUrl,
                ]);
            }
        }

        return new ToolResult(true, $content, [
            'image_urls'  => $cleanUrls,
            'model'       => MiniMaxSettings::model(self::PROVIDER, $ctx->settings, self::DEFAULT_MODEL),
        ]);
    }
}
