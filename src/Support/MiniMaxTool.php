<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Support;

use Psr\Log\LoggerInterface;
use Spora\Plugins\MiniMax\Tools\MiniMaxMediaArchiveResolver;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Transliterator;

/**
 * Abstract base for every MiniMax tool. Owns the cross-cutting wiring
 * (the {@see MiniMaxToolSupport} plumbing, the constructor) and the
 * `validate → prepare → run` orchestration that every tool follows.
 *
 * A subclass only has to provide:
 *   - the {@see PROVIDER}/{@see QUALIFIED_NAME}/{@see TIMEOUT_SECONDS}/
 *     {@see TOOL_LABEL} constants
 *   - a {@see describeAction()} (LLM-facing one-liner)
 *   - a {@see validateArguments()} (returns ?ToolResult)
 *   - a {@see doWork()} (the actual API call + response parsing)
 *
 * The base class handles:
 *   - building the {@see MiniMaxToolSupport} from the constructor args
 *     (so subclasses never store unused fields — SonarQube php:S1068)
 *   - orchestrating validate → prepare → run via {@see runWithValidation()}
 *
 * Multi-operation tools (e.g. {@see \Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool})
 * override `execute()` to dispatch to per-operation methods, each of which
 * then calls `runWithValidation()` independently.
 */
abstract class MiniMaxTool extends AbstractTool
{
    protected const PROVIDER       = '';
    protected const QUALIFIED_NAME = '';
    protected const TIMEOUT_SECONDS = 30;
    protected const TOOL_LABEL      = '';

    protected MiniMaxToolSupport $support;

    /**
     * Spora Media Archive UUID → data URI resolver. Wired by the
     * plugin's DI registration when the host application provides
     * a {@see \Spora\Services\MediaArchive\MediaAssetReader}; left
     * null for tools that don't accept first-frame images (image,
     * speech, music) and for tests that don't stand up a reader.
     */
    protected ?MiniMaxMediaArchiveResolver $mediaArchiveResolver = null;

    /**
     * Resolve Media Archive references in `$arguments` before any
     * operation method runs. Returns either the rewritten argument
     * array or a {@see ToolResult} to short-circuit on resolver
     * failure (asset not found, over the 50 MB data URI cap, etc.).
     *
     * The resolver MUST be invoked at the top of every multi-operation
     * tool's {@see execute()} override — *before* dispatching to
     * per-operation methods (`generate()`, `resume()`, ...). The
     * per-operation methods build a `fn()` closure that captures
     * `$arguments` by value at definition time, so rebinding it
     * later (e.g. inside `runWithValidation()`) leaves the closure
     * holding the *original* URL. Resolving at the entry point
     * means each per-operation closure captures already-resolved
     * arguments and `doGenerate()` (and friends) hand the resolver's
     * `data:` URI — or a forwarded external URL — to the H3 submit.
     *
     * @param  array<string, mixed>   $arguments
     * @return array<string, mixed>|ToolResult
     */
    protected function resolveMediaArchiveReferences(array $arguments, ?int $userId): array|ToolResult
    {
        if ($this->mediaArchiveResolver === null) {
            return $arguments;
        }
        $resolution = $this->mediaArchiveResolver->resolve($arguments, $userId);
        if (isset($resolution['failed'])) {
            return $resolution['failed'];
        }
        return $resolution['resolved'];
    }

    public function __construct(
        ToolConfigService   $configService,
        HttpClientInterface $httpClient,
        MiniMaxLogWriter    $logWriter,
        ?LoggerInterface    $logger = null,
        ?MiniMaxToolSupport $support = null,
    ) {
        // Constructor params are consumed once to build the support and then
        // go out of scope. The support owns the long-lived references.
        $this->support = $support ?? new MiniMaxToolSupport($configService, $httpClient, $logWriter, $logger);
    }

    /**
     * Wired by PHP-DI from {@see MiniMaxPlugin::register()}.
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->support->setLogger($logger);
    }

    /**
     * Wired by PHP-DI from {@see MiniMaxPlugin::register()} for the
     * video tools that accept first-frame / reference images. Optional:
     * tools that don't take asset URLs (image, speech, music) skip
     * this step entirely.
     */
    public function setMediaArchiveResolver(?MiniMaxMediaArchiveResolver $resolver): void
    {
        $this->mediaArchiveResolver = $resolver;
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        // Resolve Media Archive references BEFORE building the work
        // closure so `doWork()` receives the rewritten argument array
        // (data: URI, forwarded external URL, or resolver failure).
        // See {@see resolveMediaArchiveReferences()} for the rationale
        // — `fn()` closures capture `$arguments` by value at definition
        // time, so any rebinding inside `runWithValidation()` would
        // never reach `doWork()`.
        $resolved = $this->resolveMediaArchiveReferences($arguments, $userId);
        if ($resolved instanceof ToolResult) {
            return $resolved;
        }
        $arguments = $resolved;

        return $this->runWithValidation(
            $arguments,
            $agentId,
            $userId,
            static::TIMEOUT_SECONDS,
            static::TOOL_LABEL,
            fn(MiniMaxToolContext $ctx) => $this->doWork($ctx, $arguments),
            fn(array $a) => $this->validateArguments($a),
        );
    }

    /**
     * Resolve a per-stage HTTP timeout from the layered config (per-tool
     * setting → env → default). Subclasses call this instead of reading
     * `static::TIMEOUT_SECONDS` so the operator can override individual
     * stages via the settings UI.
     *
     * @param array<string, mixed> $settings
     */
    protected function resolveTimeout(string $field, array $settings, int $fallback): int
    {
        return MiniMaxSettings::timeoutSeconds(static::PROVIDER, $field, $settings) ?: $fallback;
    }

    /**
     * Resolve the filename to store alongside an archived asset.
     *
     * If the LLM supplied a name via the `filename` ToolParameter, that
     * name is sanitised (path components stripped, only `[A-Za-z0-9._-]`
     * kept, length capped at 240 chars) and returned with the tool's
     * canonical extension appended. If no name was supplied — or if
     * sanitisation yielded an empty stem — a speaking fallback is
     * generated by slugifying the user-facing `$prompt` and prepending
     * `$prefix`. The full stem (prefix + slug) is capped at 60 chars
     * on a word boundary.
     *
     * No random suffix or timestamp is added. Filenames are not required
     * to be unique — `media_assets.filename` is a plain `string(255)`
     * with no unique index (spora-core migration 0056). The same prompt
     * ingested twice produces the same name, which is what the operator
     * wants: readable, sortable, predictable.
     *
     * @param string|null $userFilename Optional LLM-supplied name, with or
     *                                  without an extension.
     * @param string      $prompt       User-facing prompt; seed for the
     *                                  slugified fallback.
     * @param string      $prefix       Kind name prepended to the slug in
     *                                  the fallback path. Also used as the
     *                                  fallback stem when slugification
     *                                  yields empty.
     * @param string      $extension    File extension without a leading dot.
     */
    public static function resolveFilename(
        ?string $userFilename,
        string $prompt,
        string $prefix,
        string $extension,
    ): string {
        if ($userFilename !== null && trim($userFilename) !== '') {
            $sanitised = self::sanitiseUserFilename(trim($userFilename), $extension);
            if ($sanitised !== null) {
                return $sanitised;
            }
        }
        return self::slugifyPrompt($prompt, $prefix) . '.' . $extension;
    }

    /**
     * Sanitise an LLM-supplied filename. Strips path components,
     * collapses runs of disallowed characters, caps length, and forces
     * the canonical extension. Returns null when nothing useful
     * survives sanitisation — callers fall through to the slugified
     * fallback.
     *
     * Defence-in-depth: even though the LLM shouldn't pass paths, a
     * stray `..` or `/` should never reach the storage layer where it
     * could affect a `Content-Disposition` header.
     */
    private static function sanitiseUserFilename(string $raw, string $extension): ?string
    {
        $withoutSlashes = preg_replace('#[\\\\/]+#', '', $raw) ?? '';
        $collapsed = preg_replace('#[^A-Za-z0-9._-]+#', '-', $withoutSlashes) ?? '';
        $collapsed = preg_replace('#-+#', '-', $collapsed) ?? '';
        $basename = trim($collapsed, '-.');
        if ($basename === '') {
            return null;
        }

        // Split off whatever extension the user wrote; keep it only if
        // it matches the canonical extension for this tool.
        $stem = $basename;
        $userExt = '';
        $dot = strrpos($basename, '.');
        if ($dot !== false && $dot > 0 && $dot < strlen($basename) - 1) {
            $stem = substr($basename, 0, $dot);
            $userExt = strtolower(substr($basename, $dot + 1));
        }
        $stem = trim($stem, '-.');
        if ($stem === '') {
            return null;
        }

        $ext = strtolower($userExt) === strtolower($extension) ? $userExt : strtolower($extension);

        // 240-char cap leaves headroom under the 255-char column.
        if (strlen($stem) > 240) {
            $stem = substr($stem, 0, 240);
            $stem = rtrim($stem, '-.');
        }
        return $stem . '.' . $ext;
    }

    /**
     * Slugify a user prompt into a speaking filename stem prefixed with
     * `$fallbackPrefix`. Returns just the prefix when the prompt is
     * empty or yields no ASCII chars. The full stem (prefix + slug) is
     * capped at 60 chars on a word boundary — the cut never lands on
     * the prefix separator, so the kind tag always survives.
     */
    private static function slugifyPrompt(string $prompt, string $fallbackPrefix): string
    {
        $text = trim($prompt);
        if ($text === '') {
            return $fallbackPrefix;
        }

        $ascii = null;
        if (class_exists(Transliterator::class)) {
            $trans = Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            if ($trans !== null) {
                $ascii = $trans->transliterate($text);
            }
        }
        if (!is_string($ascii) || $ascii === '') {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            $ascii = $converted !== false ? strtolower($converted) : strtolower($text);
        } else {
            $ascii = strtolower($ascii);
        }

        $slug = trim((string) preg_replace('#[^a-z0-9]+#', '-', $ascii), '-');
        if ($slug === '') {
            return $fallbackPrefix;
        }

        $stem = $fallbackPrefix . '-' . $slug;
        if (strlen($stem) > 60) {
            // Cap at 60 chars on a word boundary inside the slug —
            // never cut at the prefix separator (`minimax-image`), so
            // the kind tag always survives the cut.
            $cut = substr($stem, 0, 60);
            $lastDash = strrpos($cut, '-');
            if ($lastDash !== false && $lastDash > strlen($fallbackPrefix)) {
                $cut = substr($cut, 0, $lastDash);
            }
            $stem = rtrim($cut, '-');
        }
        return $stem;
    }

    /**
     * Standard validate → prepare → run orchestration. Multi-operation tools
     * call this from each per-operation method instead of `execute()`.
     *
     * @param  array<string, mixed> $arguments
     * @param  callable(MiniMaxToolContext, array<string, mixed>): ToolResult $work
     * @param  ?callable(array<string, mixed>): ?ToolResult                  $validate
     */
    protected function runWithValidation(
        array   $arguments,
        int     $agentId,
        ?int    $userId,
        int     $timeoutSeconds,
        string  $toolLabel,
        callable $work,
        ?callable $validate = null,
    ): ToolResult {
        // Media Archive UUID resolution runs at the top of the calling
        // tool's {@see execute()} override (see
        // {@see resolveMediaArchiveReferences()}) — *before* dispatch
        // to per-operation methods. It cannot live here: per-operation
        // methods (`generate()`, `resume()`, ...) build their work as
        // an `fn()` closure that captures `$arguments` by value at
        // definition time, so rebinding it inside this method would
        // leave the closure holding the unresolved original URL and
        // `doGenerate()` would forward `/api/v1/assets/<uuid>.<ext>`
        // raw to MiniMax's API.
        $ctx = $this->prepareContextOrFail($arguments, $validate, $agentId, $userId, $timeoutSeconds);
        if ($ctx instanceof ToolResult) {
            return $ctx;
        }

        return $this->support->run($ctx, $toolLabel, fn(MiniMaxToolContext $c) => $work($c, $arguments));
    }

    /**
     * Run the per-operation validator (if any) and prepare the tool
     * context. Returns a {@see ToolResult} on either failure path —
     * validation error from the validator callback, or a context
     * preparation error from {@see MiniMaxToolSupport::prepare()}.
     * Returns the prepared context on the happy path.
     */
    private function prepareContextOrFail(
        array $arguments,
        ?callable $validate,
        int $agentId,
        ?int $userId,
        int $timeoutSeconds,
    ): MiniMaxToolContext|ToolResult {
        if ($validate !== null) {
            $validation = $validate($arguments);
            if ($validation !== null) {
                return $validation;
            }
        }
        return $this->support->prepare(
            toolClass: static::class,
            provider: static::PROVIDER,
            qualifiedName: static::QUALIFIED_NAME,
            arguments: $arguments,
            agentId: $agentId,
            userId: $userId,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    /** @param array<string, mixed> $arguments */
    abstract protected function validateArguments(array $arguments): ?ToolResult;

    /** @param array<string, mixed> $arguments */
    abstract protected function doWork(MiniMaxToolContext $ctx, array $arguments): ToolResult;
}
