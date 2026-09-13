---
name: minimax-music-legacy
description: "Generate music (instrumental or with lyrics) or write / edit song lyrics via the legacy MiniMax music_generation / lyrics_generation APIs. **Deprecated since Aug 20 2026** per MiniMax's Music API Service Adjustment Notice: the paid APIs are no longer available to new users. Existing paying users can keep using this tool. For new deployments, prefer MiniMax Audio (https://www.minimax.io/audio) or self-host the open-source MiniMax Music 3 model (https://huggingface.co/MiniMaxAI/MiniMax-Music3). Load only when the operator is an existing paying user and explicitly wants the legacy API path."
license: MIT
compatibility: spora>=0.7 spora-plugin-minimax>=1.4
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool
---

# MiniMax music (legacy)

> ⚠️ **Deprecated since Aug 20 2026.** The paid Music Generation and
> Lyrics Generation APIs are no longer available to new users. Existing
> paying users can keep using this tool — the upstream endpoints still
> respond for accounts that previously paid. New deployments should
> prefer:
>
> - [MiniMax Audio](https://www.minimax.io/audio) — MiniMax's web product for hosted music generation.
> - [MiniMax Music 3 on Hugging Face](https://huggingface.co/MiniMaxAI/MiniMax-Music3) — open-source model, self-hostable.

One **multi-operation tool** — pick the right operation by user intent. Multi-op tools expose an `action` discriminator; pass `action:` as the first argument, alongside the operation-specific params.

The recipe below is identical to the active `minimax-music` skill (since the underlying endpoint is unchanged). When the user is an existing paying user and the legacy API is the right path, follow the calling pattern exactly — nothing about the wire shape is deprecated, only the API-key eligibility.

## Operations at a glance

| When the user wants…                              | Operation           | Required params                             |
|--------------------------------------------------|---------------------|---------------------------------------------|
| A finished instrumental / vocal track            | `compose`           | `prompt` and/or `lyrics` (at least one)     |
| Lyrics — fresh, from a topic / style             | `write_lyrics`      | `prompt`                                    |
| Lyrics — re-write the existing ones              | `edit_lyrics`       | `lyrics` + `prompt`                         |

## Calling

### `compose`

```
minimax_music_minimax(action: "compose", prompt: "lofi hip-hop, rainy night, warm Rhodes", lyrics: "[Verse]\nCity lights blur past", output_format: "url", filename: "midnight-lofi")
```

- `prompt` — style / mood description (max 2000 chars). **Optional only when `lyrics` is supplied.** Empty prompt + empty lyrics = the tool refuses.
- `lyrics` — lyrics to sing (max 3500 chars). **Optional.** Omit for instrumental.
- `output_format` — `url` (default; 24-hour MiniMax CDN URL) or `hex` (inline MP3 bytes; routed through the Local Asset Store, served from `/api/v1/assets/<token>.mp3`).
- `filename` — stem; auto-appended with `.mp3`.

### `write_lyrics`

```
minimax_music_minimax(action: "write_lyrics", prompt: "song about a late-night coding marathon that turned into a friendship", filename: "midnight-coders")
```

- `prompt` — topic / style description (max 2000 chars). **Required.**
- `filename` — stem; auto-appended with `.lyrics.txt`.

### `edit_lyrics`

```
minimax_music_minimax(action: "edit_lyrics", prompt: "make every other line rhyme and switch to a hopeful tone", lyrics: "[Verse]\nGrey morning\nNo sound…", filename: "tides-rev2")
```

- `prompt` — rewrite instruction (max 2000 chars). **Required.** Describe the transformation, not the destination.
- `lyrics` — existing lyrics to edit (1–3500 chars). **Required.**
- `filename` — same as `write_lyrics`.

## Settings (operator-scoped)

| Setting                        | Default | Notes |
|--------------------------------|---------|-------|
| `api_key`                      | —       | Required. Shared with image/speech/video. The same `MINIMAX_API_KEY` you use for other MiniMax tools. |
| `base_url`                     | `https://api.minimax.io` | Override only for China-region or private gateway. |
| `model`                        | `music-3.0` | Applies to `compose` only. Per https://platform.minimax.io/docs/api-reference/music-generation the upstream endpoint accepts `music-3.0` (recommended), `music-2.6`, `music-cover`, plus the `-free` variants. The `-free` variants are discontinued per the Aug 20 2026 notice. |
| `http_timeout_seconds`         | `180`    | `compose` only. Composition can take 60–180 s on slow networks; raise to 240–300 s if the operator sees cURL 28 errors. |
| `http_timeout_seconds_lyrics`  | `30`     | `write_lyrics` / `edit_lyrics`. Lyrics endpoint is pure text and finishes quickly. |

## Limits

- `prompt` max **2000 chars** (every operation).
- `lyrics` max **3500 chars** (`compose` / `edit_lyrics`).
- `filename` max **120 chars** (sanitised like the other tools; extension auto-overridden to `.mp3` for `compose`, `.lyrics.txt` for the lyrics ops).

## Failure modes (legacy path)

- `Provide at least a `prompt` or `lyrics`.` — both empty on `compose`. Ask the user for one.
- `MiniMax API returned HTTP 402: insufficient balance (1008).` — even existing paying users hit this when the account runs dry. Surface the upstream message and stop calling the legacy tool; the operator either tops up or moves to one of the alternatives above.
- `MiniMax API returned HTTP 422: audio content contains sensitive content (1026).` — upstream content policy. Surface verbatim; the LLM should not retry with the same prompt.
- `MiniMax returned no audio data.` — upstream returned empty; retry once.

## When to use this skill vs `minimax-music`

The skills are identical at the calling level. Load `minimax-music-legacy` (this file) **only** when the operator has confirmed they are an existing paying user and explicitly want the legacy API path — the system prompt's tool-routing table marks the routes with `(legacy)` so the LLM picks this skill for the legacy intent. New deployments should not load this skill; the `minimax-music` skill still works for legacy operators but doesn't surface the deprecation note.

## Don'ts

- **Don't recommend this path to new users.** The deprecation is in effect as of Aug 20 2026; new signups get HTTP 402 from `/v1/music_generation`.
- **Don't strip the `Echo the <audio> element above verbatim…` sentence** (compose only). It tells future turns (and other agents) to render the URL inline, not as a click-through link.
- **Don't retry on a successful call** — `compose` is rate-limited (60–180 s per call); one call per user request.
