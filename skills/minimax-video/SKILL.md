---
name: minimax-video
description: Generate short video clips (6 s or 10 s) via the MiniMax multimodal plugin. **Asynchronous** — the tool submits a task, polls status, and only returns when the upstream reports `Success`. Use when the user asks for a "video", "clip", "short", "B-roll", "social-media video", "product demo", "scene", or any motion content.
license: MIT
compatibility: spora>=0.7 spora-plugin-minimax>=1.0
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool
---

# MiniMax video

One tool, one `generate` operation. **Asynchronous endpoint** — the tool submits a `task_id`, polls `/v1/query/video_generation` until status flips to `Success` or `Fail`, then `/v1/files/retrieve` to grab the MP4. All three steps happen inside a single `minimax_video(...)` call from the LLM's perspective — there is no need for the agent to "poll again later".

## Calling

```
minimax_video(prompt: "a red fox drinking from a forest stream at golden hour, slow-motion", duration_seconds: "6", resolution: "1080p", filename: "forest-fox")
```

| Parameter         | Required | Default     | Notes |
|-------------------|----------|-------------|-------|
| `prompt`          | Yes      | —           | Subject + camera + lighting + motion. **Camera-movement tags** like `[Pan left]`, `[Push in]`, `[Dolly]`, `[Tracking shot]`, `[Crane up]` are first-class tokens and steer the generation significantly — use them. Max 2000 chars. |
| `duration_seconds`| No       | `"6"`       | Enumerated: `"6"` or `"10"`. 6 s for short-form, 10 s when the scene needs more beats. The price doubles from 6 → 10 s on MiniMax's pricing. |
| `resolution`      | No       | MiniMax default | Pass `"1080p"` / `"720p"` etc. MiniMax picks a default if omitted. The MiniMax docs do not enumerate valid values; pass the standard string and let upstream decide. |
| `filename`        | No       | auto        | Stem only; `.mp4` auto-appended. |

## Prompt craft — what matters

MiniMax's video model responds strongly to **camera direction**. Generic prose ("a fox in a forest") yields generic shots; a one-line direction makes the difference:

```
# Avoid — vague
"A red fox in a forest"

# Better — subject + action + camera + light
"A red fox lapping water from a forest stream at golden hour, water dripping off whiskers, slow push-in shot, shallow depth of field"

# Best — explicit camera tags the model parses as directives
"[Push in] A red fox lapping water from a forest stream at golden hour, [Shallow DOF], [Slow motion], water dripping off whiskers"
```

Other useful camera tags: `[Pan left]`, `[Pan right]`, `[Tilt up]`, `[Dolly back]`, `[Tracking shot]`, `[Crane up]`, `[Static]`.

Avoid prompt bloat — keep under 500 chars when possible. Beyond that, the model attends less to the salient bits.

## Settings (operator-scoped)

| Setting                      | Default               | What it does |
|------------------------------|-----------------------|--------------|
| `api_key`                    | — (required)          | MiniMax API key. Required. Shared with image/speech/music. |
| `base_url`                   | `https://api.minimax.io` | Override only for China-region or a private gateway. |
| `model`                      | `MiniMax-Hailuo-2.3`  | Video model id. |
| `poll_interval_seconds`      | `10`                  | Seconds between status polls. Below 5 s on a busy agent risks rate-limiting. |
| `poll_timeout_seconds`       | `600`                 | **Total** wait window before the tool gives up. Generation can exceed 5 min on a busy day. |
| `submit_timeout_seconds`     | `120`                 | Per-request timeout for the submit call (MiniMax queues the task server-side; the response can take > 30 s). |
| `retrieve_timeout_seconds`   | `30`                  | Per-request timeout for `/v1/files/retrieve`. |

## Limits

- `prompt` max **2000 chars** (hard cap).
- `duration_seconds` is `"6"` or `"10"` — the tool rejects anything else.
- `filename` max **120 chars** (sanitised like the other tools; `.mp4` extension appended).
- Output MP4: ~5–20 MB for a 6–10 s clip depending on resolution.

## Rendering

The tool returns a `<video>` element with the Media Archive URL. Echo `ToolResult.content` verbatim:

```html
MiniMax video succeeded (task_id=…, file_id=…, 6.0s).

<video controls preload="metadata" playsinline src="/api/v1/assets/<token>.mp4"></video>

Use the same video embed above to show the media player in your reply.
```

The "Use the same video embed above…" sentence tells future turns to render the URL inline. Don't strip it.

For the raw download URL, read `ToolResult.data.asset_url` — never re-extract from the markdown.

## Failure modes

- `MiniMax returned no task_id.` — upstream didn't queue the task. Retry once; if it persists, the API key or region is wrong.
- `MiniMax video timed out after <N>s.` — the generation didn't finish within `poll_timeout_seconds`. Surface to the user; offer to retry with a shorter prompt or `duration_seconds: "6"`.
- `MiniMax video failed: <reason>` — upstream flagged the prompt as unsafe or the model couldn't render the scene. Suggest re-wording (generic safe-prompt prompts trip the safety filter rarely, but explicit-violation content does).
- `MiniMax returned a download URL of an unsupported type.` — upstream returned a non-MP4 video file the asset store can't serve. Retry; if it persists, surface.

## Don'ts

- **Don't poll externally** — the tool polls internally up to `poll_timeout_seconds`. If you're tempted to call `minimax_video(...)` again to "check on it", don't — you've already issued another submit, you'll get a second task_id billed.
- **Don't promise instant delivery** — generation takes 30 s to several minutes. The user sees a spinner; don't claim "ready" before the tool returns.
- **Don't request 10 s when 6 s would do** — 10 s clips cost ~2× quota and often hit `poll_timeout_seconds` on busy days.
- **Don't pass more than one `prompt`** per call — call the tool once, see what you get, iterate based on the result.
- **Don't override `model` to anything not validated by MiniMax** — the field is free-form and a typo will return a silent upstream failure.
