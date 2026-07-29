---
name: minimax-video
description: "Generate short video clips (6 s or 10 s) via the MiniMax multimodal plugin. **Asynchronous** — the tool submits a task, polls status, and only returns when the upstream reports `Success` or `Fail`. Use when the user asks for a 'video', 'clip', 'short', 'B-roll', 'social-media video', 'product demo', 'scene', or any motion content. If a previous `generate` call returned `success: false` with `data.timed_out: true`, call `minimax_video(action: 'resume', task_id: '...')` to keep waiting without re-submitting."
license: MIT
compatibility: spora>=0.7 spora-plugin-minimax>=1.1
metadata:
  author: spora-ai
  version: "1.1"
allowed-tools: Spora\Plugins\MiniMax\Tools\MiniMaxVideoTool
---

# MiniMax video

Two operations on one tool: `generate` (default) submits a prompt and polls; `resume` re-attaches to an in-flight task by id. Both share the same submit → poll → file-retrieve → archive pipeline — the only difference is whether the task is fresh or pre-existing.

## Calling

```
# Default — submit a new task.
minimax_video(
  prompt: "a red fox drinking from a forest stream at golden hour, slow-motion",
  duration_seconds: "6",
  resolution: "1080P",
  filename: "forest-fox",
)

# Recover from a timeout — keep waiting on an in-flight task.
minimax_video(action: "resume", task_id: "115334141465231360", poll_timeout_seconds: 600)
```

| Parameter            | Required                  | Default        | Notes |
|----------------------|---------------------------|----------------|-------|
| `action`             | No                        | `"generate"`   | `"generate"` or `"resume"`. |
| `prompt`             | `generate` only           | —              | Subject + camera + lighting + motion. **Camera-movement tags** like `[Pan left]`, `[Push in]`, `[Tracking shot]`, `[Shake]` are first-class tokens the model parses as directives — use them. Max 2000 chars. |
| `duration_seconds`   | No                        | `"6"`          | Enumerated: `"6"` or `"10"`. 10 s is only valid on `MiniMax-Hailuo-2.3` and `MiniMax-Hailuo-02` at 768P. See *Resolution × duration matrix* below. |
| `resolution`         | No                        | model-dependent| One of `720P`, `768P`, `1080P` (uppercase P, exact match). Allowed values depend on the model and duration — see the matrix below. Default is 768P for Hailuo models, 720P for T2V-01*. |
| `filename`           | No                        | auto           | Stem only; `.mp4` auto-appended. |
| `poll_timeout_seconds` | No                      | operator setting (900 s) | Override the total wait window for this call (10–3600). Useful when the agent suspects a long-running generation and wants to give up faster (or wait longer than the operator-configured default). |
| `task_id`            | `resume` only             | —              | The MiniMax task id from a previous `generate` call. |

## Resolution × duration matrix

Cross-product of model, resolution, and duration — sourced verbatim from the MiniMax API docs.

| Model                  | 720P    | 768P        | 1080P  |
|------------------------|---------|-------------|--------|
| `MiniMax-Hailuo-2.3`   | —       | 6 s or 10 s | 6 s    |
| `MiniMax-Hailuo-02`    | —       | 6 s or 10 s | 6 s    |
| `T2V-01-Director`      | 6 s     | —           | 6 s    |
| `T2V-01`               | 6 s     | —           | 6 s    |

The tool validates this matrix client-side and rejects illegal combinations **before submitting**. **Most expensive trap: `resolution: "1080P"` + `duration_seconds: "10"`** is silently rejected by upstream with `2013 invalid input parameters` after the task is queued — burning quota. Always pair 10 s with 768P.

Default resolution when the LLM omits it:

| Model family | Default resolution |
|--------------|--------------------|
| Hailuo-2.3 / Hailuo-02 | 768P |
| T2V-01-Director / T2V-01 | 720P |

## Operations

### `generate` — submit a new task

Flow: POST `/v1/video_generation` → returns `task_id` → poll `/v1/query/video_generation` until `status` is `Success` or `Fail` → GET `/v1/files/retrieve?file_id=…` → archive to Media Archive. All three HTTP steps happen inside a single `minimax_video(...)` call from the LLM's perspective — there is no need to "poll again later".

### `resume` — re-attach to an in-flight task

Use when a previous `generate` returned `success: false` with `data.timed_out: true`. Pass the `task_id` from that response. The tool polls the existing task only — it does **not** re-submit, so no new quota is billed.

```
# First call timed out at 900 s with task_id=task-slow.
minimax_video(action: "resume", task_id: "task-slow", poll_timeout_seconds: 600)
```

The `resume` operation ignores `prompt`, `duration_seconds`, and `resolution` — the task is already in flight on MiniMax's side with the values from the original submit.

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
| `model`                      | `MiniMax-Hailuo-2.3`  | Video model id. Must be one of `MiniMax-Hailuo-2.3`, `MiniMax-Hailuo-02`, `T2V-01-Director`, `T2V-01`. Validated client-side; an unrecognised value is rejected before submit. |
| `poll_interval_seconds`      | `10`                  | Seconds between status polls. Below 5 s on a busy agent risks rate-limiting. |
| `poll_timeout_seconds`       | `900`                 | **Total** wait window before the tool gives up. The tool returns the `task_id` on timeout so `resume` can keep waiting — see *When generation exceeds `poll_timeout_seconds`* below. |
| `submit_timeout_seconds`     | `120`                 | Per-request timeout for the submit call (MiniMax queues the task server-side; the response can take > 30 s). |
| `retrieve_timeout_seconds`   | `30`                  | Per-request timeout for `/v1/files/retrieve`. |

## When generation exceeds `poll_timeout_seconds`

If the upstream hasn't reported `Success` or `Fail` before `poll_timeout_seconds` elapses, the tool returns:

```json
{
  "success": false,
  "content": "MiniMax video generation did not finish within 900s (task_id=task-slow). The task is still running on MiniMax's side and is billable. Increase `poll_timeout_seconds` and call `minimax_video(action: \"resume\", task_id: \"task-slow\")` to keep waiting, or abandon it and accept the billed quota.",
  "data": {
    "task_id": "task-slow",
    "status": "still_running",
    "timed_out": true
  }
}
```

The task is **still billable** on MiniMax's side. Surface this to the user and offer the choice: keep waiting (call `resume`) or abandon the task.

If the upstream reports `Fail` instead, the tool surfaces the message verbatim:

```
MiniMax video generation failed: <status_msg from base_resp>
```

## Limits

- `prompt` max **2000 chars** (hard cap).
- `duration_seconds` is `"6"` or `"10"` — the tool rejects anything else.
- `resolution` must be `"720P"`, `"768P"`, or `"1080P"` (uppercase P, exact match).
- `model` setting must be one of the four supported MiniMax video models.
- `(model, resolution, duration)` must satisfy the matrix above — rejected client-side.
- `filename` max **120 chars** (sanitised like the other tools; `.mp4` extension appended).
- `poll_timeout_seconds` per-call override is bounded to **10–3600 s**.
- Output MP4: ~5–20 MB for a 6–10 s clip depending on resolution.

## Rendering

The tool returns a `<video>` element with the Media Archive URL. Echo `ToolResult.content` verbatim:

```html
Generated video (1920x1080) for prompt: "…"

<video controls preload="metadata" playsinline src="/api/v1/assets/<token>.mp4"></video>

task_id: …  file_id: …

Echo the `<video>` element above verbatim — its `src` is `/api/v1/assets/<token>.mp4` served by the Media Archive, not a relative filename (rewriting it breaks playback). Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`.
```

The "Echo the `<video>` element above verbatim…" sentence tells future turns to render the URL inline and spells out the verbatim-echo rule. Don't strip it.

For the raw download URL, read `ToolResult.data.asset_url` — never re-extract from the markdown.

## Failure modes

- `MiniMax returned no task_id.` — upstream didn't queue the task. Retry once; if it persists, the API key or region is wrong.
- `MiniMax video generation did not finish within <N>s.` — the generation didn't finish within `poll_timeout_seconds`. The response carries `task_id` and `data.timed_out: true` — call `resume` to keep waiting or abandon.
- `MiniMax video generation failed: <reason>` — upstream flagged the prompt as unsafe or the model couldn't render the scene. Suggest re-wording (generic safe-prompt prompts trip the safety filter rarely, but explicit-violation content does).
- `MiniMax video succeeded but returned no download_url.` — file-retrieve didn't carry the URL. Retry; if it persists, surface.
- `<resolution> + <duration_seconds> is not a valid combination for model <M>` — client-side rejection of an illegal cross-product. See the matrix above.

## Don'ts

- **Don't combine `resolution: "1080P"` with `duration_seconds: "10"`** — this is the most expensive trap (1080P only supports 6 s on every model). The tool rejects it client-side.
- **Don't combine `duration_seconds: "10"` with `T2V-01*` models** — the T2V-01 family doesn't support 10 s at any resolution.
- **Don't use lowercase `p`** (`"1080p"`, `"720p"`) — MiniMax's enum is uppercase `P` (`"1080P"`, `"720P"`). The tool rejects lowercase values.
- **Don't poll externally** — the tool polls internally up to `poll_timeout_seconds`. If you're tempted to call `minimax_video(...)` again to "check on it", don't — you've already issued another submit, you'll get a second task_id billed. Use `resume` with the existing `task_id` instead.
- **Don't promise instant delivery** — generation takes 30 s to several minutes. The user sees a spinner; don't claim "ready" before the tool returns.
- **Don't request 10 s when 6 s would do** — 10 s clips cost ~2× quota and often hit `poll_timeout_seconds` on busy days.
- **Don't pass more than one `prompt`** per call — call the tool once, see what you get, iterate based on the result.
- **Don't override `model` to anything not in the four-value list** — the field is free-form on the operator setting; the tool rejects unrecognised values before submit.
- **Don't abandon a timed-out task silently** — the task is still billable on MiniMax's side. Surface the choice (keep waiting via `resume` vs accept the cost) to the user.

## What this tool does not do

- **`prompt_optimizer`** is locked at upstream's default (`true`). Not currently exposed — MiniMax's optimization step helps most prompts; if you need exact prompt fidelity for A/B testing, raise an issue.
- **`fast_pretreatment`** is locked at upstream's default (`false`). Not currently exposed.
- **`callback_url`** (asynchronous callbacks) is not supported. The tool polls synchronously until the task finishes or `poll_timeout_seconds` elapses. Operators who need callbacks should use MiniMax's dashboard directly.
- **Image-to-Video (`I2V`)** is a different endpoint shape (`image_url` field). Not currently supported — see the MiniMax docs for the I2V endpoint if you need it.
