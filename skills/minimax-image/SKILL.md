---
name: minimax-image
description: Generate images via the MiniMax multimodal plugin (single `generate` operation). Use when the user asks for an "image", "picture", "illustration", "photo", "poster", "thumbnail", or any visual that needs to be created from a text description. Pick an `aspect_ratio` that matches the intended surface (1:1 for square avatars/tiles, 16:9 for hero banners, 9:16 for phone-wallpapers, etc.).
license: MIT
compatibility: spora>=0.7 spora-plugin-minimax>=1.0
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxImageTool
---

# MiniMax image

One tool, one `generate` operation. Returns a list of generated image URLs (typically 1 image per call; MiniMax may return more) which the tool embeds as Markdown images via the Media Archive when possible.

## Calling

```
minimax_image_minimax(prompt: "<subject + style + lighting>", aspect_ratio: "16:9", filename: "hero-banner")
```

| Parameter      | Required | Default | Notes |
|----------------|----------|---------|-------|
| `prompt`       | Yes      | —       | Subject + style description (max 1500 chars). Be specific — MiniMax's text-to-image model responds well to concrete material/lighting/camera cues. |
| `aspect_ratio` | No       | `1:1`   | Enumerated. See table below. |
| `filename`     | No       | auto    | Stem only — extension is auto-appended. Use kebab-case Latin to keep the slug stable. |

### `aspect_ratio` — pick by surface

| Surface                              | Ratio  |
|--------------------------------------|--------|
| Avatar, profile tile, generic preview | `1:1`  |
| Hero / banner / YouTube thumbnail    | `16:9` |
| Slide / classic display              | `4:3`  |
| Print photo / desktop wallpaper      | `3:2`  |
| Vertical print / portrait poster     | `2:3`  |
| Phone-portrait poster                | `3:4`  |
| Phone-wallpaper / Story / Reel / TikTok | `9:16` |
| Cinemascope header                  | `21:9` |

When the user doesn't specify an aspect ratio, **ask before guessing** if the surface is ambiguous. A square avatar and a hero banner are not interchangeable.

## Settings (operator-scoped, set via the Agent config UI)

| Setting                   | Default               | What it does |
|---------------------------|-----------------------|--------------|
| `api_key`                 | — (required)          | MiniMax API key for `api.minimax.io`. Required. |
| `base_url`                | `https://api.minimax.io` | Override only for China-region (`https://api.minimaxi.com`) or a private gateway. |
| `model`                   | `image-01`            | Image model id. Stick with the default unless an operator has switched it. |
| `http_timeout_seconds`    | `60`                  | Per-request timeout. Image calls usually finish in <10 s; raise this if the operator sees `cURL error 28: Operation timed out`. |

The `api_key` is **shared across all MiniMax tools** — set it once and the speech/music/video tools pick it up.

## Failure modes

- `MiniMax returned no image URLs.` — upstream returned an empty `image_urls` array. Retry once with a more specific `prompt`; if it fails again, surface the error to the user.
- `MiniMax API key is not configured for this agent.` — `api_key` setting is empty at every scope. Edit the tool's settings; this error doesn't fall back to env vars.
- `MiniMax returned image URLs that are not strings.` — defensive guard; never reached in practice. Retry.

## Limits

- `prompt` max length: **1500 characters** (hard cap, the tool rejects longer).
- `filename` max length: **120 characters** (sanitised to `[A-Za-z0-9._-]`, paths stripped, wrong extension overridden to `.png`).
- One image per call on average. MiniMax occasionally returns 2 — the tool returns them all, separated by a blank line in the markdown.

## Rendering

The tool already emits Markdown image embeds. Echo its `content` block verbatim — every image is already served via `/api/v1/assets/<token>.png` (the Media Archive path), so the chat UI renders inline:

```markdown
Generated 1 image for prompt: "..."

![Generated image 1: ...](https://.../api/v1/assets/<token>.png)

Echo the markdown image block above verbatim — its URL is `/api/v1/assets/<token>.<ext>` served by the Media Archive, not a relative filename (rewriting it breaks the image). Don't strip this sentence; it tells the chat UI to render the URL inline. For the raw URL, read `ToolResult.data.image_urls`.
```

When the Media Archive plugin is **absent**, the tool falls back to the upstream MiniMax CDN URL — still inline-rendering but the link expires in ~24 h. Mention the dependency if the operator asks why archived assets are missing.

If the user wants the raw URL (for download, scripting, etc.), read it from `ToolResult.data.image_urls` — never re-extract from the markdown.

## Don'ts

- **Don't fabricate URLs.** If the tool didn't return an image, say so. Don't link a stock photo or "best guess" art.
- **Don't retry on a successful call.** One generation per user request is the rule; extra calls cost quota.
- **Don't ask the user to upload a reference image** — MiniMax's `image_generation` endpoint this tool targets is text-only. Reference-image / img2img is a different (unset) endpoint.
- **Don't strip the "Echo the markdown image block above verbatim…" sentence.** It's the instruction that tells future turns (and other agents) to render the URL inline, not as a click-through link — and it spells out the verbatim-echo rule for callers that didn't load this skill.
