# MiniMax Plugin for Spora

Reference implementation of a Spora plugin that adds the five non-text multimodal
capabilities of [MiniMax](https://platform.minimax.io) — image, speech, music,
lyrics, and video generation — to your Spora agents.

**Text/chat is out of scope for this plugin.** Spora's built-in Anthropic-compatible
driver handles text generation when you point its `base_url` at MiniMax (see
[Text generation via MiniMax](#text-generation-via-minimax) below).

---

## Installation

### Option A — Clone into the Spora repo

```bash
cd /path/to/your/spora
git clone https://github.com/spora-ai/spora-plugin-minimax.git plugins/minimax
php bin/spora spora:install   # applies the plugin's migration
```

### Option B — External path (no Spora checkout changes)

```bash
git clone https://github.com/spora-ai/spora-plugin-minimax.git /opt/spora-plugins/minimax
echo 'SPORA_PLUGINS_PATHS=/opt/spora-plugins/minimax' >> .env
php bin/spora spora:install
```

Both paths produce the same result. Use Option B when you want the plugin's
versioning completely decoupled from your Spora deployment.

## Configuration

Open Spora → **Settings → Tools** and configure one section per tool. All five
tools share the same MiniMax API key (issued at
<https://platform.minimax.io> → **API Keys**).

| Setting key                                   | Type     | Required | Default                  | Notes |
|-----------------------------------------------|----------|----------|--------------------------|-------|
| `plugin.minimax.image.api_key`                | password | yes      | —                        | MiniMax API key |
| `plugin.minimax.image.base_url`               | text     | no       | `https://api.minimaxi.io` | Override for self-hosted MiniMax |
| `plugin.minimax.image.model`                  | text     | no       | `image-01`               | See [Supported models](#supported-models) |
| `plugin.minimax.speech.api_key`               | password | yes      | —                        | |
| `plugin.minimax.speech.base_url`              | text     | no       | `https://api.minimaxi.io` | |
| `plugin.minimax.speech.model`                 | text     | no       | `speech-2.8-hd`          | |
| `plugin.minimax.speech.voice_id`              | text     | no       | `English_PassionateWarrior` | Any voice id from the MiniMax voice library |
| `plugin.minimax.music.api_key`                | password | yes      | —                        | |
| `plugin.minimax.music.base_url`               | text     | no       | `https://api.minimaxi.io` | |
| `plugin.minimax.music.model`                  | text     | no       | `music-2.6`              | |
| `plugin.minimax.lyrics.api_key`               | password | yes      | —                        | |
| `plugin.minimax.lyrics.base_url`              | text     | no       | `https://api.minimaxi.io` | |
| `plugin.minimax.video.api_key`                | password | yes      | —                        | |
| `plugin.minimax.video.base_url`               | text     | no       | `https://api.minimaxi.io` | |
| `plugin.minimax.video.model`                  | text     | no       | `MiniMax-Hailuo-2.3`     | |
| `plugin.minimax.video.poll_interval_seconds`  | text     | no       | `10`                     | Seconds between status polls |
| `plugin.minimax.video.poll_timeout_seconds`   | text     | no       | `600`                    | Maximum total wait for video generation |

`api_key` fields are encrypted at rest by Spora's `ToolConfigService` (same
mechanism as OpenAI/Anthropic keys). They are never logged, never sent to the
LLM, and masked in the Settings UI.

## Per-tool usage

Enable the tools on a Spora agent. After enabling, the LLM can call them with
the standard `tool_call` JSON:

| Tool name        | Purpose | Example call |
|------------------|---------|--------------|
| `minimax:image`  | Generate images from a text prompt | `{"prompt": "a red fox in a snowy forest, cinematic"}` |
| `minimax:speech` | Text-to-speech synthesis | `{"text": "Hello, world.", "voice_id": "English_PassionateWarrior"}` |
| `minimax:music`  | Instrumental music from a prompt | `{"prompt": "ambient synth pad, 80 BPM"}` |
| `minimax:lyrics` | Lyrics generation | `{"mode": "write_full_song", "prompt": "a song about the sea"}` |
| `minimax:video`  | Text-to-video (async) | `{"prompt": "[Push in] a slow zoom into a forest"}` |

The plugin prefixes the bare tool name (`image`, `speech`, …) with the plugin
slug automatically — agents see `minimax:image`, `minimax:speech`, etc.

## Text generation via MiniMax

Spora's **Anthropic-compatible driver** talks to MiniMax's Anthropic-protocol
endpoint. Configure any agent with:

- `llm_provider`: `anthropic`
- `base_url`: `https://api.minimaxi.com/anthropic`
- `llm_api_key`: the same `MINIMAX_API_KEY`
- `llm_model`: e.g. `MiniMax-M3`

No plugin code is involved. The plugin only adds the five non-text capabilities.

## Supported models

**Image**: `image-01` (default)

**Speech**: `speech-2.8-hd` (default), `speech-2.8-turbo`, `speech-2.6-hd`,
`speech-2.6-turbo`, `speech-02-hd`, `speech-02-turbo`, `speech-01-hd`,
`speech-01-turbo`

**Music**: `music-2.6` (default), `music-cover`, `music-2.6-free`,
`music-cover-free`

**Lyrics**: mode-only — `write_full_song` (default) or `edit`

**Video**: `MiniMax-Hailuo-2.3` (default), `MiniMax-Hailuo-02`,
`T2V-01-Director`, `T2V-01`

## How it works

Each tool follows the same shape as Spora's built-in `ReadUrlTool` /
`TavilySearchTool`:

1. Read effective settings via `ToolConfigService::getEffectiveSettings(self::class, $agentId, $userId)`.
2. Validate the required `api_key` is set; return `ToolResult::fail` if not.
3. Build a `MiniMaxHttpClient` (stateless wrapper over Symfony's
   `HttpClientInterface`).
4. Call the MiniMax endpoint. On success, return `ToolResult::ok` with a
   formatted summary the LLM can reason about.
5. Wrap the call in `try { ... } catch (Throwable)` so a single API error never
   kills the agent loop.
6. Persist a row to `minimax_generation_log` for audit via `MiniMaxLogWriter`.

### Asset storage

v1 returns MiniMax's upstream URL directly (image / audio / video). **URLs from
MiniMax expire in 24 hours** — the LLM must consume them in the same task
(e.g. paste them into an `<img>` tag, attach them to a downstream tool call).
Asset proxying / S3 mirroring is deferred to v2.

The `minimax_generation_log` table persists the request and response payloads
(URL only, not binary).

### Logging

Every tool call writes one row to `minimax_generation_log` with the request
payload, response payload, status, and a redacted `error` (if any). The
`MiniMaxLogWriter` redacts `api_key`, `Authorization` headers, and base64 blobs
over 1 MB before insert.

## Development

```bash
# Run the plugin's test suite
composer install
./vendor/bin/pest

# Lint (after a Spora repo is also present for cross-loading):
composer analyse
```

## License

MIT — see the Spora project license.
