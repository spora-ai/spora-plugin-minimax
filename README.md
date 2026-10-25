# spora-plugin-minimax

Spora plugin: minimax. The full reference (install, configuration,
per-tool parameters, development) has moved to the docs site:

**[docs.spora-ai.com/develop/plugins/reference/minimax](https://docs.spora-ai.com/develop/plugins/reference/minimax)**

See the docs site for the canonical reference. The README previously
in this repo has been migrated there.

## Translations

This plugin ships a German catalogue at [`locales/de.json`](locales/de.json),
declared through `translationsPaths()` in `src/MiniMaxPlugin.php`. The host
merges it under core's own catalogue, so a German install reads the plugin's
operator copy in German.

The **key is the English source string**, byte for byte — editing the English
in the source changes the key, so the old entry stops matching and the new
English has no entry. The gate is this repository's own
`tests/Unit/LocaleCatalogTest.php`: it fails on an empty translation, on a
translation identical to its key, and on a key the source no longer produces.

**Translated**

- the five tool display names — `MiniMax Image`, `MiniMax Speech`,
  `MiniMax Music (legacy)`, `MiniMax Video`, `MiniMax Video (v1 legacy)`
- every distinct `#[ToolSetting]` description
- the twelve approval prompts the tools return from `describeAction()`
- the `plugin.json` description

`MiniMaxTranscribeProvider`'s `display_name` setting is a free-text operator
rename, not copy, so it has no fixed English source string to key on — only
its *description* is translated. Its **label** is not translated either, like
every other `#[ToolSetting]` label: the label is the part a reader would
expect to be translated, and it is deliberately left English.

**Never translated** — these strings are read by the model, so the operator
sees exactly what the model sees:

- `#[Tool].description`
- `#[ToolOperation].description`
- `#[ToolParameter].description`
- every `#[ToolSetting].label` and its option labels

An approval prompt is translatable only in the `%s` form. The host looks the
stored, already-rendered sentence up and matches it against the catalogue's
`%s` / `%d` templates, so the key is the `sprintf()` template —
`"Generate image for prompt: '%s'"` — and never the rendered text. A `{$prompt}`
interpolation has no template to match and never resolves.

See the [plugin author guide → Translations](https://docs.spora-ai.com/develop/plugins/author-guide/translations).

## Archived-output filenames

When a MiniMax tool (`image`, `video`, `music`, `speech`) successfully
ingests its output into the Media Archive, the row is persisted with a
human-readable filename that downstream surfaces — download links, the
Media Archive detail page, the `asset_url` query parameter — all
reflect.

### LLM-supplied `filename` (preferred)

Every MiniMax tool accepts an optional `filename` ToolParameter. When
the agent supplies one, that name is sanitised (path components
stripped, only `[A-Za-z0-9._-]` kept, length capped at 240 chars) and
returned with the tool's canonical extension appended. A wrong
extension on the user input (e.g. `.jpg` for music) is overridden to
the canonical one (`.mp3`). The result is **never** made unique — two
ingests of the same user-named asset land in the Media Archive with the
same display name, which is the intended behaviour.

Examples:

| LLM input | Persisted filename |
|---|---|
| `sunset-at-the-beach` | `sunset-at-the-beach.png` |
| `track.mp3` (music) | `track.mp3` |
| `song.txt` (music) | `song.mp3` *(wrong ext overridden)* |
| `../../etc/passwd.png` | `etcpasswd.png` *(paths stripped)* |
| `Track.MP3` (music) | `Track.mp3` *(case-normalised)* |

### Generated fallback

When the agent doesn't supply a `filename`, the plugin slugifies the
prompt (lower-cased, transliterated to ASCII via `Transliterator` or
`iconv`, non-alphanumerics replaced with `-`, length capped at 60 chars
on a word boundary) and prepends the kind tag. The slug becomes the
filename stem:

| Prompt | Persisted filename |
|---|---|
| `a red fox` | `minimax-image-a-red-fox.png` |
| `Sonnenuntergang am Strand` | `minimax-image-sonnenuntergang-am-strand.png` |
| `🌅🌊` (no ASCII chars) | `minimax-image.png` *(prefix fallback)* |
| *(empty)* | `minimax-image.png` |

Filenames are not unique-enforced (`media_assets.filename` is plain
`string(255)`, no unique index). The same prompt ingested twice
produces the same name, which is what the operator wants — readable,
sortable, predictable.
