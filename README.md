# MiniMax Plugin for Spora

Adds MiniMax's non-text multimodal capabilities — **image, speech, music
(instrumental, with lyrics, or standalone lyrics), video** — to
[Spora](https://github.com/spora-ai/Spora) agents. Text/chat is provided by
Spora's built-in Anthropic-compatible driver pointed at MiniMax's base URL
(see below).

## Installation

```bash
# Option A — clone into the Spora repo
git clone https://github.com/spora-ai/spora-plugin-minimax.git plugins/minimax
php bin/spora spora:install   # applies the plugin's migration

# Option B — external path (no Spora checkout changes)
git clone https://github.com/spora-ai/spora-plugin-minimax.git /opt/spora-plugins/minimax
echo 'SPORA_PLUGINS_PATHS=/opt/spora-plugins/minimax' >> .env
php bin/spora spora:install
```

After install, tools are exposed as `minimax:image`, `minimax:speech`, `minimax:music`, `minimax:video`.

## Configuration

Settings → Tools → MiniMax. All four tools share the same `MINIMAX_API_KEY`
(issued at <https://platform.minimax.io> → API Keys).

The default `base_url` is the **Global** endpoint
(`https://api.minimax.io`). Operators in China should override
`plugin.minimax.{provider}.base_url` to `https://api.minimaxi.com` for the
China-region endpoint.

| Setting | Required | Default |
|---|---|---|
| `plugin.minimax.{provider}.api_key` | yes | — |
| `plugin.minimax.{provider}.base_url` | no | `https://api.minimax.io` (Global) |
| `plugin.minimax.{provider}.model` | no | per provider (see below) |
| `plugin.minimax.speech.voice_id` | no | `English_PassionateWarrior` |
| `plugin.minimax.video.poll_interval_seconds` | no | `10` |
| `plugin.minimax.video.poll_timeout_seconds` | no | `600` |

`api_key` fields are encrypted at rest by Spora's `ToolConfigService`, masked
in the UI, and never logged.

## Per-tool parameters

Each tool accepts a `prompt` and returns `ToolResult::ok` (with the upstream
CDN URL, valid 24h) or `ToolResult::fail`. Never throws — a single API failure
cannot kill the agent loop.

| Tool | Default model | Notes |
|---|---|---|
| `minimax:image` | `image-01` | `aspect_ratio` ∈ 1:1, 16:9, 4:3, 3:2, 2:3, 3:4, 9:16, 21:9 |
| `minimax:speech` | `speech-2.8-hd` | TTS; `voice_id`, `speed` (0.5-2.0) |
| `minimax:music` | `music-2.6` | Operations: `compose` (instrumental or with `lyrics`, 1-3500 chars), `write_lyrics` (full song from a topic), `edit_lyrics` (rewrite existing lyrics) |
| `minimax:video` | `MiniMax-Hailuo-2.3` | Async — polls until `Success` or timeout. Returns `file_id` (the underlying file-retrieval endpoint is not documented on the public docs and is out of v1 scope). |

The music tool's `action` discriminator selects the operation. `compose` uses
`/v1/music_generation` and accepts an optional `lyrics` parameter. The
`write_lyrics` and `edit_lyrics` operations use `/v1/lyrics_generation`. All
three operations share the same `api_key`, `base_url`, and (compose-only)
`model` settings.

Every call writes one row to `minimax_generation_log` (redacted of
`api_key`, `Authorization`, and base64 blobs > 1 KB) for audit.

## Text generation via MiniMax

Spora's **Anthropic-compatible driver** talks to MiniMax's Anthropic-protocol
endpoint. Configure any agent with:

- `llm_provider`: `anthropic`
- `base_url` (Global / international): `https://api.minimax.io/anthropic`
- `base_url` (China): `https://api.minimaxi.com/anthropic`
- `llm_api_key`: the same `MINIMAX_API_KEY`
- `llm_model`: e.g. `MiniMax-M3`

No plugin code is involved.

## Development

```bash
composer install
./vendor/bin/pest           # 19 tests
./vendor/bin/phpstan analyse
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

CI: `.github/workflows/ci.yml` — Pest on PHP 8.4 + 8.5, PHPStan level 5,
php-cs-fixer dry-run. A separate `coverage` job runs Pest with `pcov` and
uploads `coverage.xml` + JUnit; the `sonar` job then uploads both to
SonarCloud (project key `spora-ai_spora-plugin-minimax`), so the
`new_coverage` metric is measurable per PR. Requires the `SONAR_TOKEN`
secret in the repo. MIT license.
