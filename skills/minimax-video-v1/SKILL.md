---
name: minimax-video-v1
description: "Generate a short video clip via MiniMax's legacy v1 video_generation API. Use as the fall-back when `minimax:video` (the H3 / v2 tool) returns MiniMax's 2013 'TokenPlan or Credit does not currently support MiniMax-H3 series models' error. This v1 tool talks to POST /v1/video_generation + GET /v1/files/retrieve and supports the Hailuo family + T2V-01-*. Models: MiniMax-Hailuo-2.3 (default), MiniMax-Hailuo-02, T2V-01-Director, T2V-01. Resolutions and durations are validated against the v1 matrix before submit so the LLM gets a clear client-side error instead of an upstream 400."
license: MIT
compatibility: spora>=0.7 spora-plugin-minimax>=1.3
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxVideoV1Tool
---

# MiniMax v1 video (legacy) workflow

The H3 / v2 tool (`minimax:video`) is the primary path. Use `minimax:video_v1` only when:

1. `minimax:video` returns an error containing `[2013]` and the message `TokenPlan or Credit does not currently support MiniMax-H3 series models`. That's the plan-tier cap on H3.
2. The operator configured this tool with a model on a plan that doesn't include H3.
3. The Agent specifically needs a v1-only behaviour (e.g. `MiniMax-Hailuo-2.3` at 1080P 6s — H3 doesn't expose 1080P).

## When to load

Load this skill when the user wants the v1 path. The Media Agent's `media-agent.json` routing table mentions `minimax-video-v1` for "Set up a v1 video"; the Agent will pick it when the system prompt or the H3 error message calls for it.

## Calling

```
minimax_video_v1(
  prompt: "...",
  duration_seconds: "6" | "10",
  resolution: "512P" | "720P" | "768P" | "1080P",
  filename: "optional-speaking-name",
)
```

The tool validates the argument set against the v1 matrix before any upstream call. Forbidden combinations surface as clear client-side errors with the allowed combinations listed.

## Resolution × duration matrix

| Model | 512P | 720P | 768P | 1080P |
|---|---|---|---|---|
| `MiniMax-Hailuo-2.3` | — | 6s | 6s or 10s | 6s |
| `MiniMax-Hailuo-02` | — | 6s | 6s or 10s | 6s |
| `T2V-01-Director` | — | 6s | — | 6s |
| `T2V-01` | — | 6s | — | 6s |

The Hailuo family is the only one that supports 10s (and only at 768P). The T2V-01 family has no 768P at all — fall back to 720P (default) or 1080P, both 6s only.

`first_frame_image` is accepted by the v1 matrix but the i2v code path is not yet shipped in this tool. If you need image-to-video on a plan that only supports v1, use the H3 / v2 tool (`minimax:video`) instead, or generate a fresh still via `minimax:video_v1` + regenerate.

## Settings (operator-scoped)

| Setting | Default | What it does |
|---|---|---|
| `api_key` | — (required) | MiniMax API key. |
| `base_url` | `https://api.minimax.io` | Override only for China-region (`https://api.minimaxi.com`) or a private gateway. |
| `model` | `MiniMax-Hailuo-2.3` | One of the v1 models above. |
| `poll_interval_seconds` | `10` | Seconds between status polls. |
| `poll_timeout_seconds` | `900` | Total wait window. 1080P clips on Hailuo-2.3 routinely hit 8–12 min. |
| `submit_timeout_seconds` | `120` | Per-request timeout for the submit call. |
| `retrieve_timeout_seconds` | `30` | Per-request timeout for `/v1/files/retrieve`. |

## When generation exceeds `poll_timeout_seconds`

If the upstream hasn't reported `Success` or `Fail` before `poll_timeout_seconds` elapses, the tool returns:

```json
{
  "success": false,
  "content": "MiniMax v1 video generation did not finish within 900s (task_id=task-slow). The task is still running on MiniMax's side and is billable. Increase `poll_timeout_seconds` and call `minimax_video_v1(action: \"resume\", task_id: \"task-slow\", prompt: \"<original prompt>\", duration_seconds: \"<original duration>\", resolution: \"<original resolution>\")` to keep waiting, or abandon it and accept the billed quota.",
  "data": {
    "task_id": "task-slow",
    "status": "still_running",
    "timed_out": true,
    "prompt": "...",
    "duration_seconds": 6,
    "resolution": "768P"
  }
}
```

The task is **still billable** on MiniMax's side. Surface this to the user and offer the choice: keep waiting (call `resume`) or abandon.

## Fall-back flow from v1 → v1 (when H3 fails)

The chain looks like:

```
generate_call(minimax:video_h3) → 400 [2013] TokenPlan doesn't support H3
                                  ↓
                                 retry with minimax:video_v1
                                  ↓
                                 same prompt, same as the first call
```

The Media Agent's system prompt carries this routing. When the H3 error message comes back, the LLM should retry with `minimax:video_v1` and the same prompt without surfacing the 2013 detail to the user (the orchestrator's `MiniMaxHttpClient` now includes the upstream error.message in the exception, so the LLM gets a clear "TokenPlan or Credit does not support H3" message).

## After the video renders

Echo `ToolResult.content` from the `minimax_video_v1` call verbatim — it carries the `<video>` element + the trailing "Echo the `<video>` element above verbatim..." sentence that tells the chat UI to render the player inline. Don't strip that sentence.

For the raw URL, read `ToolResult.data.asset_url` (Media Archive long-lived URL) or `ToolResult.data.download_url` (upstream CDN, ~1h expiry).
