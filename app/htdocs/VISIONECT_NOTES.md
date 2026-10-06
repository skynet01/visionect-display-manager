# Visionect Web App — Developer Notes

> Written for AI assistants picking up this project. Read this before touching anything.

## What This Is

A PHP/Apache web app running inside Docker that drives a **Visionect e-ink display** (black & white, 1440×2560px, 8:15 portrait aspect ratio). The display polls the web server, renders whatever page is currently active as a JPEG, and shows it on the physical display.

**Container name:** `visionect-web-content`  
**Host port:** `4412`  
**App root (inside container):** `/app/`  
**App root (on host):** `~/config/visionect/webserver/app/`  
**Web root:** `~/config/visionect/webserver/app/htdocs/`  

---

## ⚠️ Critical Constraints

- **NEVER update, recreate, or reconfigure** the Visionect server containers (`vss`, `visionect-pdb`, `visionect-web-content`, `visionect-redis`). The Visionect server software is locked to a specific version to avoid a subscription requirement. Only edit files inside `htdocs/`, `lib/`, `cli/` and `config/`. (The host root crontab runs the crons with `docker exec` daily and restarts `visionect-web-content` weekly; see Cron Schedule below.)
- **PHP is 7.4.33.** No PHP 8 functions (`str_starts_with`, `str_contains`, `match`, etc.). Use `strpos(...) === 0` / `strncmp()`.
- **Always `require_once`** the `lib/` files. Several libs load each other, so a plain `require` causes "Cannot redeclare function" fatals.
- **Write files atomically.** Use `visionect_write_json_atomic()` / `visionect_write_file_atomic()` from `lib/security.php` (temp file + `rename()`), and `visionect_mutate_runtime_status()` for read-modify-write of `runtime_status.json`. Never truncate-then-write a file the frame, visionectd or the admin might be reading.
- **Display is black & white e-ink.** All images must be greyscale. Use `visionect_image_to_frame()` from `lib/image.php`, which converts to greyscale and saves atomically.
- **Display resolution:** 1440×2560 px. All full-screen content must match exactly.
- **PHP `file_get_contents()` fails for HTTPS** inside this container. Use `visionect_http_get()` / `visionect_http_post()` from `lib/http.php` (curl-based) for every HTTP/HTTPS request.
- **All images must be saved as `.jpg`** — the display cannot render GIF/PNG/WebP. `visionect_image_to_frame()` always writes JPEG.
- **Imagick must be wrapped in `ob_start()` / `ob_end_clean()`** — Imagick can leak binary data to stdout which corrupts Docker's JSON log file. `lib/image.php` already does this; any direct Imagick code must too. See the Docker Logs section below.

---

## Architecture Overview

```
Physical frame
    └── loads public shell at `/`
            └── gets short-lived WS token from `status.php?ws_token=1`
                    └── connects to `visionectd.php` on port 12345
                            ├── scheduler chooses active module from `PREFS.json`
                            ├── runtime writes `config/runtime_status.json`
                            └── sends module URL to the frame shell

Admin UI (`/admin`)
    ├── authenticates with PHP session + CSRF
    ├── reads/writes config through `admin/api.php`
    ├── fetches a fresh role=admin WS token from `api.php?action=ws_token` before every (re)connect
    ├── listens to runtime status over WebSocket
    └── can control the display via WebSocket or the file control queue (`config/control_queue/`)

Local automations (HA / Node-RED)
    └── `control.php` → one JSON file per command in `config/control_queue/` → visionectd (polled every second)

Apache serves `htdocs/` module pages
    └── modules record exact frame requests/served variants into `runtime_status.json`

Host root crontab
    ├── 06:05 America/Phoenix daily: `docker exec visionect-web-content php /app/cli/cron.php --run`
    └── 03:40 Mondays: `docker restart visionect-web-content` (runs no crons)

Container command: `apache2-foreground & cd /app/cli && php cron.php && php visionectd.php`
    └── `cron.php` with no args is a no-op, so visionectd starts immediately
```

### Key files

| Path | Purpose |
|------|---------|
| `app/config/PREFS.json` | Master config: all pages, timeslots, scheduling |
| `app/config/general_settings.json` | Frame resolution + mirrored frame sleep window |
| `app/config/runtime_status.json` | Shared runtime snapshot for admin UI, previews, and exact frame tracking |
| `app/cli/visionectd.php` | WebSocket scheduler/runtime — reads config, sends URLs, runs commands (WS + control queue), writes runtime status |
| `app/cli/cron.php` | Module cron runner: no-op without args; `--run` runs enabled modules (ainews last), `--run <module>` one module, `--dry-run` |
| `app/config/control_queue/` | One JSON file per queued control command (control.php, admin restart) |
| `app/htdocs/admin/index.php` | Admin SPA at `/admin` |
| `app/htdocs/admin/api.php` | Authenticated config/API layer for admin actions |
| `app/htdocs/admin/admin_common.php` | Helpers shared by `index.php` and `api.php` (general settings payload) |
| `app/htdocs/admin/vendor/` | Self-hosted, pinned front-end scripts: `lucide-1.52.0.min.js`, `tailwindcss-play-3.4.17.js` (no CDN) |
| `app/htdocs/control.php` | Local-network control endpoint for Home Assistant / Node-RED |
| `app/htdocs/.htaccess` | No-cache headers for the shell/status; denies `config.json` and `*.bak*` over HTTP |
| `app/lib/security.php` | Auth, CSRF, encryption, atomic writes + file locks, runtime-status, and exact-frame helpers |
| `app/lib/runtime.php` | Shared HA / general-settings / activity helpers (also used by `status.php` and visionectd) and the `control.php` token check |
| `app/lib/http.php` | `visionect_http_get()` / `visionect_http_post()` curl helpers |
| `app/lib/image.php` | `visionect_image_to_frame()`, `visionect_is_image_blob()`, `visionect_image_last_error()` |
| `app/lib/gallery_page.php` | Shared page renderer for the `art`, `quotes` and `haynesmann` galleries |
| `app/htdocs/` | One subfolder per module |

### Shared lib helpers (`app/lib/`)

- `security.php`
  - `visionect_write_file_atomic($path, $data, $mode)` writes a temp file in the same directory, then `rename()`s it over the target and keeps the target's mode. It returns false and leaves the target untouched on failure.
  - `visionect_write_json_atomic($path, $data)` does pretty JSON with `JSON_INVALID_UTF8_SUBSTITUTE`. It returns false without writing if `json_encode` fails. `visionect_write_json_file()` now just calls it.
  - `visionect_with_file_lock($path, $fn)` runs `$fn` under an exclusive `flock` on the sidecar `<path>.lock`. It is re-entrant within one process.
  - WS tokens: `visionect_issue_websocket_token($user, $ttl, $role)` with `$role` `admin` or `display`; `visionect_websocket_token_role($claims)` treats old tokens with no role as `display`.
  - Control queue: `visionect_queue_remote_control($cmd)` writes `config/control_queue/<microtime>-<rand>.json` atomically; `visionect_take_remote_controls()` drains it oldest-first. `visionect_request_daemon_restart()` queues `restartDaemon` and waits up to 4s for visionectd to take it (falls back to SIGTERM of the recorded daemon PID).
  - `visionect_mutate_runtime_status($fn)` is a locked read-modify-write of `runtime_status.json`. `visionect_update_runtime_status($patch)` uses it. Use it whenever the new value depends on the old one (e.g. newspaper `next_index`).
- `http.php`: `visionect_http_get($url, $timeout = 20, $headers = [], $curlOpts = [])` and `visionect_http_post($url, $body, $headers = [], $timeout = 30)`.
  - Both return `ok` (2xx and a non-empty body), `status`, `body`, `error`, `headers` (lower-cased) and `content_type`.
  - They send a Chrome User-Agent. Always check `['ok']`, not just a non-null body.
- `runtime.php`: `visionect_ha_config()`, `visionect_general_config()`, `visionect_is_sleep_window()`, `visionect_ha_presence()` (HA curl: 2s connect / 3s total), `visionect_compute_activity()` (`home` / `away` / `sleep`), and `visionect_control_token_required()` / `visionect_control_token_ok()`.
- `image.php`: `visionect_image_to_frame($srcPathOrBlob, $destPath, $opts)` reads the first frame, flattens alpha, converts to greyscale and writes a JPEG atomically.
  - `mode` is `contain` (default, letterbox), `cover` (centre-crop) or `fit_width` (scale down to width, free height; used for comic strips).
  - Other options are `width`/`height` (default 1440×2560), `background` (default white), `quality` (default 82) and `upscale`.
  - It returns false on failure; read the reason from `visionect_image_last_error()`.
  - `visionect_is_image_blob($data)` checks that downloaded bytes are a real image, not an HTML error page.

---

## PREFS.json — How Scheduling Works

```json
{
  "pages": {
    "modulename": {
      "url": "modulename/",   // path served by Apache
      "chance": 3,            // relative weight in random selection
      "dynamic": true,        // true = same page can repeat back-to-back
      "duration": 3800        // seconds to show before switching
    }
  },
  "timeslots": {
    "morning": {
      "day_time": { "mon": {"from": "08:45", "till": "13:30"}, ... },
      "duration": 3000,       // overrides page's own duration during this slot
      "pages": ["newspaper", "ainews"]  // only these pages show in this slot
    }
  }
}
```

**Current pages:** `clock`, `newspaper`, `art`, `haynesmann`, `comics`, `quotes`, `ainews`

**Current timeslots:** `morning`, `breakfast`, `dinner`, `night`

Outside any timeslot, all pages are eligible weighted by `chance`.

### Adding a new module

1. Create `htdocs/newmodule/index.php` — must render correctly at exactly 1440×2560px
2. Add entry to `PREFS.json` under `pages`
3. Optionally add to relevant timeslot `pages` arrays
4. Save/reload prefs through `/admin`, or call `reloadPrefs` over WebSocket / `control.php`
5. If the worker still has stale scheduling state, use the admin restart button (visionectd exits cleanly, container is back in ~2s)

If PREFS.json is ever missing or invalid, visionectd keeps the last good PREFS and never sends an empty URL.

To check visionectd PID: `docker exec visionect-web-content ps aux | grep visionectd`

---

## Module Structure

Each module is a folder in `htdocs/`:

```
htdocs/modulename/
    index.php       ← rendered by the display (required)
    cron.php        ← optional, run daily by `cli/cron.php --run`
    config.json     ← optional, module-specific settings
    data.json       ← optional, cron output consumed by index.php
    *.jpg           ← image assets (JPG only — display does not support GIF/PNG)
```

### index.php conventions

- Fixed size: `html, body { width: 1440px; height: 2560px; overflow: hidden; }`
- Background black (`#000`) for e-ink
- Images: reference by relative URL (`story1.jpg`, not `/ainews/story1.jpg`)
- Use `htmlspecialchars()` on all output — XSS matters even locally
- Google Fonts work (container has internet access)

### cron.php conventions

- Shebang line: `#!/usr/local/bin/php` (`cli/cron.php` runs it as `php cron.php 2>&1` in the module folder, so the exec bit no longer matters)
- Exit non-zero on failure: the exit code is recorded in `runtime_status.json` → `cron.<module>.last_cli_result`
- `chdir(__DIR__)` at the top so relative paths work
- `require_once` the libs you need: `__DIR__ . '/../../lib/security.php'`, `http.php`, `image.php`
- **Use `visionect_http_get()` / `visionect_http_post()` for all HTTP(S)** — `file_get_contents()` fails on HTTPS
- **Save images with `visionect_image_to_frame()`** — always `.jpg`, greyscale, atomic. Never `.gif`, `.png`, or `.webp`
- **Validate downloads** before using them: HTTP 200 (`['ok']`) plus `visionect_is_image_blob()` (or a `%PDF` check for PDFs)
- **Never destroy the last good output on failure.** Download/convert to a temp file, then `rename()` it into place only on success. Write `data.json` / `metadata.json` with `visionect_write_json_atomic()`
- Write output files (images, data.json) to the module's own folder
- Print progress to stdout — it shows in `docker logs visionect-web-content`

---

## Modules Reference

### `clock/`
Static clock display. `index.php` picks a style from `config.json` `enabled_styles` (or `?style=`) and includes `clock.<style>.html`. Styles: `digital`, `analog`, `words`, `clocks`, `flip`. No cron.

### `art/`, `quotes/`, `haynesmann/` — galleries
All three `index.php` files are two lines that call `visionect_render_gallery_page()` from `lib/gallery_page.php`.
- It shows one random image (or `?file=<name>`) full-frame and records it as the frame's `asset_file`.
- Images whose name contains `.black` get a black background.
- No cron; images are uploaded through the admin.
- `art/index.js.php` is a legacy client-side cycler (`visionect_render_gallery_js_page()`). The frame rotation doesn't use it.
- As of 2026-10-05, all 455 images (art 60, quotes 40, haynesmann 355) are 1440×2560 single-channel greyscale JPEGs.
- Admin uploads are named `bw-<module>-<time>-<8hex>.jpg`.

### `newspaper/`
Downloads front pages of major newspapers daily via cron.php. Config in `config.json`; prefixes must match `^[A-Za-z0-9_-]+$` (the admin and cron.php both enforce this):
```json
{
  "NewYorkTimes": {"prefix": "NY_NYT", "style": "...css...", "enabled": true},
  "WallStreetJournal": {"prefix": "WSJ", "style": "...", "enabled": true},
  "USAToday": {"prefix": "USAT", "style": "...", "enabled": true}
}
```

**How cron.php works:**
- Fetches `https://cdn.freedomforum.org/dfp/pdf<day>/<PREFIX>.pdf` via `visionect_http_get`, trying today, yesterday and the day before.
- The body must start with `%PDF`.
- Converts page 1 with `convert -density 150 ... -colorspace Gray -resize 1440 -quality 80` to a temp file. On success it renames to `<PREFIX>_<Ymd>.jpg`. The PDF is always deleted.
- `<PREFIX>_latest.jpg` is a symlink, relinked atomically (new link + rename) **only** after a successful fetch.
- Broken `_latest` links are repaired to the newest file on disk, or removed if there is none.
- Files older than 5 days are pruned, but a current `_latest` target is never pruned.

`index.php` round-robins enabled papers server-side and stores the position in `runtime_status.json` → `modules.newspaper.next_index` via `visionect_mutate_runtime_status()` (locked).

### `comics/`
Builds one long comics page from multiple strip sources.

**Supported strip source modes:**
- `auto` — cron fetches the strip automatically
- `upload` — admin keeps a manual uploaded JPG for that strip
- `url` — admin imports a strip from a direct image URL

**Automatic sources:**
- Garfield, Calvin & Hobbes, Pearls Before Swine — GoComics
- Dilbert — `https://dilbert-viewer.herokuapp.com/YYYY-MM-DD`
- Far Side — scraped from `thefarside.com`

**Fallback behavior:**
- every strip is downloaded and converted with `visionect_image_to_frame(..., ['mode' => 'fit_width'])`, which writes a temp file and renames it over `<slug>.jpg` only on success. If an automatic fetch fails, the last good JPG is kept
- Far Side panels are downloaded to `.farside_new_*` temp files. The old `farside_*.jpg` files are replaced only if the whole fetch succeeds; otherwise the previous panels stay
- `metadata.json` (written atomically) records per-strip source state, `stale_since`, and last success time
- the admin UI shows blocked/missing warnings and lets you switch each strip to manual upload or direct image URL. Manual uploads/URL imports also use `fit_width`

**Layout engine** (`index.php`):
- Far Side: row of individual panels at top (up to 4 side by side), panel padding `15px 5px`, caption `margin-top: 15px`, font `1.05em`
- Comic strips: shown full-width below, as many as fit without cutting off
- Height is computed explicitly from named constants: `BODY_PADDING=16` per side (so the content area is 1408×2528), the Far Side panel padding, and an estimated caption height (`comics_caption_height()`: characters per line from the font size and line height). It also keeps `LAYOUT_SAFETY=16` spare pixels, so long captions no longer clip the bottom
- Gap settings come from `comics/config.json` (`gap_strip`, `gap_min`, `gap_max`; defaults 32 / 6 / 48)
- Gap squeeze algorithm: tries standard gap → min gap → drops last strip
- All content vertically centered

**Metadata pattern:** cron.php writes `metadata.json` with:
- image dimensions for layout (`strips`, `farside`)
- `sources` map for strip health/state, including a `sources.farside` entry (with `panels` count)
- per-strip `status`, `message`, `last_success_at`, and `stale_since`

**Slugs:** strip slugs must match `^[A-Za-z0-9_-]+$` (enforced by the admin).

**GoComics fetch note:** the module now expects either:
- fresh cookies from `gocomics_auth.json`, or
- a manual fallback path (`upload` / `url`) if automatic refresh is blocked

**Dilbert regex note:** The viewer HTML has no space between `alt` and `src` attributes. Use `[^>]*` (not `[^>]+`) between them: `/<img[^>]+alt="Comic for [0-9-]+"[^>]*src=([^\s>]+)/i`

---

#### GoComics Anti-Bot Bypass — `cookie-refresh` Service

GoComics is served behind BunnyCDN which blocks non-browser HTTP clients. Plain curl requests without valid session cookies return a challenge page instead of the comic. This is handled automatically by the `cookie-refresh` Docker service.

**Architecture:**

```
cookie-refresh container (Python 3.11, TZ=America/Phoenix)
    runs once at container start, then daily at 05:55 America/Phoenix (12:55 UTC)
    solves BunnyCDN challenge → writes /app/config/gocomics_auth.json (cookies ONLY)
            ↓
comics/cron.php (PHP) runs once daily at 06:05 America/Phoenix (13:05 UTC) via `cron.php --run`
    reads gocomics_auth.json
    injects cookies into all GoComics requests, downloads + converts the strips
```

The container has no DST (America/Phoenix), so 05:55/06:05 local is always 12:55/13:05 UTC.

**`gocomics_auth.json` schema** (`~/config/visionect/webserver/app/config/gocomics_auth.json`):
```json
{
  "cookies":      "INGRESSCOOKIE=...; bunny_shield_id_33498=...",
  "expires_at":   0,
  "refreshed_at": "2026-10-05T22:33:45Z",
  "source":       "cookie-refresh"
}
```

- `cookies` — raw `Cookie:` header string, ready to inject as `CURLOPT_COOKIE`
- The Bunny cookie is now named `bunny_shield_id_<n>` (it used to be `bunny_shield`). Both sides match it **by prefix** (`bunny_shield*`)
- `expires_at` — Unix expiry of the `bunny_shield*` cookie, or `0` when unknown. The current `bunny_shield_id_*` cookie is a session cookie, so this is normally `0`. Old-style values carried the expiry in segment 3 (`userkey#challenge_hash#expiry_unix_ts#...`)
- `source` — `"cookie-refresh"` (auto-refresh) or `"manual"` (pasted via admin UI)
- The old per-strip `strips` map is gone; refresh.py drops it on write
- This file is **gitignored** — never commit it

**One refresh per cron run.** The refresh runs 10 minutes before the single daily cron. If you ever add another cron run time, add a matching refresh 10 minutes earlier (the schedule lives in `entrypoint.sh`).

**PHP side — `loadGoComicsCookieOptions()` in `comics/cron.php`:**
- Reads `gocomics_auth.json` and returns `[CURLOPT_COOKIE => '...']`
- If the file is missing or a known expiry is in the past, it waits in a loop (polling every 15s, up to 3 minutes) for the `cookie-refresh` service to write fresh cookies
- When the expiry is unknown it uses the cookies, but warns if `refreshed_at` is more than 36h old (cookie-refresh probably not running)
- If cookies still aren't available after 3 minutes, it proceeds without them (strips will fail but cron won't crash)
- `comicsHttpGet()` wraps `visionect_http_get()` with the required Accept headers; cookie options are passed as `$curlOpts`. `isCdnChallenge()` checks the `cdn-challenge` header and the body

**Required HTTP headers for ALL GoComics requests:**
Both the Python refresh script and the PHP cron **must** send these headers on every GoComics request, even when a valid cookie is present:
```
Accept: text/html,application/xhtml+xml,*/*;q=0.8
Accept-Language: en-US,en;q=0.5
```
Without these, BunnyCDN returns the challenge page (status 200, ~1761 bytes, `cdn-challenge: true` response header) regardless of cookie validity. The request looks successful (200 OK) but the body is the challenge page, not the comic.

**Docker service files:**
- Dockerfile: `~/docker/visionect/docker-cookie-refresh/Dockerfile`
- Python script: `~/docker/visionect/docker-cookie-refresh/refresh.py`
  - It ONLY refreshes cookies and never touches the strip images.
  - The proof-of-work loop is capped at `POW_MAX_ITERATIONS = 20000`.
  - If no cookies come back, the existing file is kept.
- Entrypoint: `~/docker/visionect/docker-cookie-refresh/entrypoint.sh` (sleep loop to the next 05:55 in `$TZ`)
- Compose service: `cookie-refresh` (container `visionect-cookie-refresh`) in `~/docker/visionect/docker-compose.yml`
- Mounts `~/config/visionect/webserver/app` as `/app` — same volume as the PHP app
- The container runs as root. It writes `gocomics_auth.json` atomically (temp + `os.replace`), mode 644, and chowns it to the owner of `config/` (uid 1000). The admin's manual cookie save can still overwrite it.

**Manual cookie fallback (admin UI):**
- The admin panel (`/admin`) has a "Cookie settings" button in the Comics module section.
- It opens a modal where you can paste cookies manually: Netscape tab-delimited format from a browser extension (`#HttpOnly_` lines are kept), or plain `name=value` pairs.
- This writes `gocomics_auth.json` atomically, mode 600, with `source: "manual"`.
- The modal shows current cookie status including expiry.

---

#### ⚠️ GoComics — Do Not Repeat These Dead-Ends

**1. Do NOT add a `fix_cookie_paths()` function.**
A previous attempt added logic to re-add cookies with `Path=/`, believing the challenge endpoint set a path-restricted cookie. This was wrong — the endpoint already returns `Set-Cookie: bunny_shield=...; Path=/`. Any code that removes and re-adds `bunny_shield` will corrupt the session.

**2. Do NOT assume 200 OK means the challenge was bypassed.**
BunnyCDN returns status 200 for the challenge page. Always check body content. The challenge page is ~1761 bytes and contains `"Establishing a secure connection"`. A real page is hundreds of kilobytes.

**3. Do NOT skip `Accept`/`Accept-Language` headers.**
BunnyCDN validates these headers even on requests with a valid cookie. Without them you get the challenge page back. This applies to both the Python refresh script and the PHP cron curl calls.

**4. Do NOT let the refresh drift away from the cron run.**
Refresh 10 minutes before each cron run. The cron runs once a day at 06:05 America/Phoenix, so the single 05:55 America/Phoenix refresh is correct. Before 2026-10-05 the refresh ran at 05:55/15:55 UTC, about 7 hours before the real 13:05 UTC cron.

**5. Do NOT use `file_get_contents()` for GoComics.**
GoComics requires specific headers and cookies. Use `visionect_http_get()` (curl) with the Accept headers and `CURLOPT_COOKIE`.

**6. Do NOT match the cookie by the exact name `bunny_shield`.**
It is now `bunny_shield_id_<n>`. Match by prefix, or the expiry/wait logic silently never runs.

**7. Do NOT let cookie-refresh write strip images.**
It used to download raw strips over `comics/<slug>.jpg` after the PHP cron, leaving colour GIF data in `.jpg` files. Strip downloads belong to `comics/cron.php` only.


### `ainews/` — AI News Module
Generates daily AI-illustrated news stories (one per source). Each story gets its own full-screen page; `index.php` picks one at random on each load.

**Config file** (`config.json`) — all behaviour is configurable here:
```json
{
  "groq_api_key": "<encrypted>",
  "kie_api_key": "<encrypted>",
  "kie_model": "google/nano-banana",
  "gemini_api_key": "<encrypted>",
  "gemini_model": "gemini-2.5-flash-image",
  "pollinations_api_key": "<encrypted>",
  "huggingface_api_key": "<encrypted>",
  "provider_order": ["kie", "gemini", "pollinations", "huggingface"],
  "summary_words": 70,
  "summary_prompt": "Summarize this news story in about {words} words. Simple plain language, no jargon, no bullets.",
  "image_prompt": "...full detailed prompt for kie.ai and Gemini. Use {title} and {summary} placeholders...",
  "sources": [
    {"label": "Tech",     "feed": "https://hnrss.org/frontpage?points=100"},
    {"label": "Business", "feed": "https://feeds.bbci.co.uk/news/business/rss.xml"},
    {"label": "Global",   "feed": "https://feeds.bbci.co.uk/news/world/rss.xml"}
  ]
}
```

API keys are encrypted at rest (`lib/security.php`), and `htdocs/.htaccess` denies `config.json` over HTTP.

**RSS feeds must be RSS, not Atom.** The parser uses `$feed->channel->item[0]` (RSS structure). Atom feeds (`<feed xmlns="...Atom">` with `<entry>` elements) will parse silently but return no items. Always verify a new feed URL returns proper `<channel><item>` XML before adding it.

Add/remove sources freely — story count is fully dynamic, no hardcoded limit anywhere.

**Prompt placeholders:**
- `summary_prompt`: use `{words}` for word count
- `image_prompt`: use `{title}` and `{summary}` for story content

**How cron.php works:**
1. For each source, fetch RSS with `visionect_http_get` (15s), retrying once after 5s because hnrss fails intermittently. Parse the first item.
2. Try the full article body (8s timeout).
   - It uses the `<body>` `textContent` with script/nav/etc. stripped, capped at 2000 bytes.
   - Paywall or bot-check pages fall back to the RSS description. They are detected by page `<title>`, or by "enable JavaScript"-style phrases only when the body is under 1500 chars.
3. Summarise with Groq. Grounding rules forbid inventing facts.
   - Text under 400 chars is "thin": a short summary from that text only.
   - Under 40 chars counts as no content: one or two sentences restating the headline only.
4. Generate a satirical Far Side/Mad Magazine-style illustration via the image cascade, with a **150s per-story budget**. A provider isn't started with under 20s left.
   - Provider output must be HTTP 200 **and** pass `visionect_is_image_blob()`.
   - It is saved with `visionect_image_to_frame(..., ['mode' => 'contain', 'background' => 'black', 'quality' => 80])`: black letterbox, no cropping, so speech bubbles survive. `story{n}.jpg` is replaced atomically only on success.
5. Titles and summaries go through `ainewsNormalizeText()`. It maps U+2010-U+2012 and U+2212 (odd hyphens/minus) to `-`, and U+00A0/U+202F/U+2007/U+2009/U+200A (no-break/narrow/thin spaces) to a space. It drops zero-width characters; en/em dashes are kept because they render fine. This is needed because gpt-oss emits these characters and they render as boxes on the frame.
6. Write `data.json` atomically (`visionect_write_json_atomic`). Images are `story1.jpg`, `story2.jpg`, etc. in the module root.

**cron.php runs last** in the master cron chain (all other modules run first). This is intentional — ainews is the slowest job and shouldn't hold up the others.

**Article fetch:** 8s timeout (sites that don't respond are usually paywalled or blocking bots). Uses the Chrome User-Agent from `lib/http.php`.

**APIs used:**
- Groq (`openai/gpt-oss-120b`, since 2026-09-27; `llama-3.3-70b-versatile` was decommissioned) — summarization via `https://api.groq.com/openai/v1/chat/completions`. It is a reasoning model: reasoning tokens count toward `max_tokens`
- Image generation — cascades automatically: **kie.ai → Gemini → Pollinations → HuggingFace**, first success wins. No `image_provider` config needed; just populate the API keys you want active.
  - **kie.ai** (`google/nano-banana`) — primary. Async API: POST `/api/v1/jobs/createTask` → poll `GET /api/v1/jobs/recordInfo?taskId=...` with `Authorization: Bearer` header (required on both create AND poll). Sizing uses `input.image_size = "9:16"` (`aspect_ratio` is silently ignored); it returns roughly 768×1344/768×1376, which letterboxes into the 1440×2560 frame. The `kie_model` and `provider_order` come from config. State field values: `waiting` / `queuing` / `generating` / `success` / `fail`. Result URL in `data.resultJson` (JSON string) → `resultUrls[0]`. Fast (~10s). Free tier available.
  - **Gemini** (`gemini-2.5-flash-image`) — second. Uses full detailed `image_prompt` from config. 9:16 aspect ratio + 2K resolution via `generationConfig: { imageConfig: { aspectRatio: "9:16", imageSize: "2K" } }`. **`imageSize` is required for consistent ratio** — without it Gemini sometimes returns 1:1 square images. Field is `imageConfig` NOT `imageGenerationConfig`. Base64 image in `candidates[0].content.parts[n].inlineData.data`. Note: `gemini-2.5-flash-image` has no free tier — it will return a quota error on free API keys. Raw dimensions logged to cron output.
  - **Pollinations** — GET `https://gen.pollinations.ai/image/{prompt}?...&key=...`. Uses an **auto-generated concise prompt** (not `image_prompt` from config — FLUX diffusion models work better with short keyword-style prompts). Prompt auto-capped at 400 chars to avoid Cloudflare 400 errors from long URLs.
  - **HuggingFace** (`FLUX.1-schnell`) — POST `https://router.huggingface.co/hf-inference/models/black-forest-labs/FLUX.1-schnell`. Uses same concise prompt as Pollinations. Returns JPEG binary directly. Free tier ~1000 req/month. Note: old `api-inference.huggingface.co` retired (410); always use `router.huggingface.co`.
  - To list all available Gemini model names: `curl 'https://generativelanguage.googleapis.com/v1beta/models?key=KEY'`

**Layout:** Full-frame illustration as background, gradient overlay fixed to bottom 20% of frame (512px), title + summary overlaid. Movie poster style.

**Fallback:** If the RSS fetch/parse fails (after the retry), the previous full story is kept. If image generation fails, the **whole previous story** (title, summary and image) is kept, so a new headline is never shown over an unrelated old image. Only when there is no previous story is a text-only story saved.

---

## Admin Panel (`/admin`)

- Authenticated single-account admin UI
- First-run bootstrap creates the initial admin account if `config/admin_account.json` is missing
- Live dashboard shows:
  - current slot
  - active page
  - current frame asset
  - system health
  - current frame preview
  - current module preview tool
- Top-nav controls:
  - pause / unpause
  - reload current page
  - settings
  - account
  - restart button (POST `restart` → `visionect_request_daemon_restart()`): visionectd exits cleanly, the container command chain ends and Docker's `unless-stopped` policy brings the container back in about 2s. Crons do not run on restart
- When the frame is asleep, the admin reflects that state:
  - countdown switches to wake time
  - live badge shows `Sleeping`
  - reload/jump actions are disabled or blocked

### Admin UI expansion

- `/admin/api.php` now owns server-side reads and writes for `PREFS.json`, module `config.json` files, gallery uploads, feed validation, and newspaper discovery.
- `reloadPrefs` is available over the WebSocket server so the admin can save updated scheduling data without restarting the container.
- `control.php` mirrors the important live actions for local automation without requiring WebSocket token handling. It queues commands in `config/control_queue/`; an optional control token can be set in General settings.
- `comics/config.json` now controls gap tuning plus strip enablement and order, and both `comics/index.php` and `comics/cron.php` read from it.
- `comics/config.json` also stores per-strip source mode (`auto`, `upload`, `url`) and optional direct image URLs.
- `clock/config.json` controls which clock templates are eligible on each render.
- Gallery uploads go through `process_uploaded_image()` → `visionect_image_to_frame()` (`contain`, white letterbox, frame size from general settings). They are named `bw-<module>-<time>-<8hex>.jpg`, so multi-file uploads in the same second no longer overwrite each other. Comic strip uploads/URL imports use `process_comic_strip()` (`fit_width`).
- Front-end scripts are self-hosted and pinned in `admin/vendor/` (`lucide-1.52.0.min.js`, `tailwindcss-play-3.4.17.js`). There are no CDN or `@latest` scripts. The old `admin/js/` (jQuery), `css/`, `fonts/` (FontAwesome) and `manifest.json` were deleted.
- Shared PHP helpers for `index.php` and `api.php` live in `admin/admin_common.php`. `api.php` loads `lib/http.php` and `lib/image.php`.
- Gallery panels show cached ~300px thumbnails (`api.php?action=thumb`, stored in `<module>/.thumbs/`, URL keyed on the source mtime, `loading="lazy"`) instead of full-res images.
- Only the visible panel is rendered (`renderCurrentPanel()` / `showPanel()`), not every panel on every status change.
- Module saves go through one `saveModule(module, {label, buildConfig, afterSave})` helper with error handling and toasts.
- Schedule slot edits live in `state.scheduleDraft` until the schedule is saved, so saving another module no longer saves (or drops) unsaved slot edits.
- The WebSocket client fetches a fresh role=admin token (`api('ws_token')`) before every connect/reconnect, so controls keep working after the 1h token expires. visionectd replies `{"type":"auth","ok":...,"role":...}` after connect.
- General settings include a write-only `control_token` for `control.php` (currently unset, so control.php is open to the LAN).

### Current admin capabilities

- Auth is required for `/admin` and `/admin/api.php`.
- A first-run setup flow exists:
  - if `config/admin_account.json` is missing, `/admin` shows a bootstrap screen
  - the password is hashed and the user is logged in immediately
  - once the account exists, normal sign-in takes over
- The admin account can be renamed and its password changed from the UI.
- The top-bar settings panel is now split into:
  - `General` for frame resolution plus mirrored frame sleep settings
  - `Home Assistant` for presence pause only
- The mirrored frame sleep settings do not put the panel to sleep. They exist so the admin can reflect the physical frame's own sleep window.
- The admin header shows the active slot and live countdown using runtime status from the WebSocket server.
- When the frame is asleep, both the admin header countdown and the login screen `Next update` value switch to the next wake time instead of the normal page-rotation timer.
- The live page now shows:
  - `Current module` preview tool on the left
  - `Current frame` preview on the right
  - the `Current frame` card shows when that exact frame was served
- the module preview tool lets you pick any enabled module and reload it for testing
- Sidebar module toggles allow quick enable/disable from anywhere in the UI.
- Disabled modules:
  - are removed from rotation
  - are removed from schedule slot eligibility
  - are skipped by `cli/cron.php`
  - cannot all be disabled at once
- Manual cron buttons now exist in the admin for:
  - `newspaper`
  - `comics`
  - `ainews`
- Manual cron runs refresh the matching preview in the admin and also refresh the live preview if that module is currently active.
- `System Health` only shows cron-backed modules that are currently enabled.
- The Live status line now prefers exact frame metadata, so it can show the real asset filename plus exact served URL instead of only `module/`.
- Schedule editor now supports:
  - adding/removing slots
  - editing times for all seven days
  - enabling/disabling individual days inside each slot
  - full-width two-row weekday/weekend layout
- Newspaper editor now supports:
  - adding papers by Freedom Forum discovery or by direct tag like `NY_NYT`
  - enabling/disabling papers
  - today’s paper previews
- Comics editor now supports:
  - drag-reorder of all strips including Far Side and Dilbert
  - per-strip manual upload and direct-image URL fallback
  - blocked/stale warnings when GoComics fails
- AiNews editor now supports:
  - configurable `kie.ai` model
  - provider fallback order
  - today’s generated story previews
- Clock editor shows live preview cards for each enabled style.

### API quick reference

Public endpoints:

- `GET /status.php`
  - Returns the current activity as plain text: `home`, `away` or `sleep` (same logic as visionectd, via `lib/runtime.php`).
- `GET /status.php?ws_token=1`
  - Public endpoint for the frame shell.
  - Returns a 24h `role=display` WebSocket token used by `/` when opening the display socket. Display tokens can watch but not control.
- `GET|POST /control.php?task=<task>[&page=<module>]`
  - Local-network automation endpoint for Home Assistant / Node-RED (private-network clients only).
  - Optional token: if General settings has a `control_token`, send it as the `X-Control-Token` header or a `token` param (401 otherwise). Currently unset.
  - Supported tasks: `setPage`, `resumeSchedule`, `reloadCurrent`, `pause`, `unpause`, `reloadPrefs`, `refreshActivity`.
  - GET and JSON POST both work. Each command becomes one file in `config/control_queue/`; visionectd drops queued commands older than 5 minutes.

Admin API: `/admin/api.php?action=<action>`.
- Every action requires a logged-in session, and every non-GET request also needs the CSRF token.
- These are the actual `case` labels in `api.php`; anything else returns 404 `Unknown action`.

| Action | Method | Purpose |
|--------|--------|---------|
| `status` | GET | Runtime snapshot as `{ ok: true, status: <snapshot> }` (read `data.status`) |
| `ws_token` | GET | `{ token }`: fresh 1h `role=admin` WS token; the admin calls it before every (re)connect |
| `prefs` | GET / POST | Read / validate and atomically save `PREFS.json`. The admin then sends `reloadPrefs` over the WebSocket |
| `module_config&module=<name>` | GET / POST | Module `config.json` for `clock`, `newspaper`, `art`, `haynesmann`, `comics`, `quotes`, `ainews`. For ainews, GET returns `secrets: {<field>: {set: bool}}` instead of keys |
| `account` | POST | Change admin username/password (needs `current_password`; new password ≥ 12 chars). There is no GET |
| `ha_config` | GET / POST | Home Assistant presence-pause settings. The token is write-only (`secrets.access_token.set`) |
| `general_config` | GET / POST | Frame resolution, mirrored sleep window and the write-only `control_token` (`config/general_settings.json`) |
| `ha_status` | POST | Test the HA connection with the (unsaved) form values; a blank token uses the stored one. There is no GET |
| `gallery&module=<art\|quotes\|haynesmann>` | GET | `{ files: [{name, mtime}] }` |
| `thumb&module=<m>&file=<name>&v=<mtime>` | GET | Cached ~300px JPEG thumbnail from `<module>/.thumbs/` (generated on demand; private cache 7 days) |
| `comics_preview` | GET | Today's comic assets plus blocked/stale source information |
| `comics_upload_strip` | POST (multipart `slug`, `file`) | Manual strip upload, converted with `fit_width` |
| `comics_import_url` | POST JSON `{slug, url}` | Fetch a strip from a direct image URL (must be a real image), `fit_width` |
| `comics_auth_status` | GET | GoComics cookie status (configured / expired / expiring soon / source) |
| `comics_save_cookies` | POST JSON `{cookies}` | Manual cookie paste → `gocomics_auth.json` (`source: "manual"`) |
| `ainews_preview` | GET | Today's generated AiNews stories and images |
| `newspaper_preview` | GET | Today's newspaper assets for the admin preview |
| `run_module_cron&module=<name>` | POST | Run `newspaper`, `comics` or `ainews` cron now (the `Run now` buttons; records result and shows failures) |
| `upload` | POST (multipart `module`, `file`) | Gallery upload → `bw-<module>-<time>-<8hex>.jpg` |
| `delete_image` | POST `{module, file}` | Delete a gallery image (must be in the gallery list) and its thumbnail |
| `validate_feed` | POST JSON `{url}` | Check an RSS URL has `channel/item` before saving it to AiNews |
| `newspapers` | GET | Papers discovered from Freedom Forum (fallback list if the scrape fails) |
| `restart` | POST | `visionect_request_daemon_restart()`: visionectd exits cleanly and the container is back in ~2s (503 if the daemon didn't take it) |

Secrets are write-only:
- The browser never receives AiNews API keys, the HA token or the control token, only `{set: bool}` under `secrets`.
- On save, a blank secret field keeps the stored value.
- To empty a secret, list the field in `clear_secrets` (the admin's explicit "clear" checkbox).

### Exact frame tracking

- `lib/security.php` now writes exact frame-response metadata into `config/runtime_status.json`.
- All `runtime_status.json` writes go through `visionect_update_runtime_status()` / `visionect_mutate_runtime_status()`: locked (`runtime_status.json.lock`) read-modify-write plus an atomic rename. Concurrent writers (visionectd, frame requests, crons, admin) no longer tear the file or lose each other's keys.
- `visionect_track_frame_request()` records that the real frame hit a module page.
- `visionect_record_frame_response()` records the exact variant served to that request.
- Current `frame` keys may include:
  - `last_seen_at`
  - `last_module`
  - `last_path`
  - `exact_url`
  - `exact_kind`
  - `asset_file`
  - `style`
  - `paper_prefix`
  - `paper_name`
  - `story_title`
- This data now drives the admin's `Current frame` preview.

Important implementation detail:

- `visionect_record_frame_response()` should clear module-specific fields it is not using, otherwise stale values can carry over between modules.
- `admin/api.php?action=status` returns the runtime snapshot as `{ ok: true, status: <snapshot> }`.
  - The admin UI must read `data.status`.
  - If you read the whole response object directly, `frame` and `display` look missing/stale and the `Current frame` preview falls back to module URLs.

### Public frame shell / WebSocket compatibility

- The public display shell at `/` is intentionally still public.
- WS tokens carry a role:
  - `status.php?ws_token=1` (public) issues `role=display` tokens for the frame shell.
  - `api.php?action=ws_token` (admin session) issues `role=admin` tokens.
  - Old tokens without a role claim count as `display`.
- visionectd sends `{"type":"auth","ok":<bool>,"role":"admin|display"}` after connect, then the status.
- All commands (WebSocket and control queue) go through one `handleCommand()`:
  - `getStatus` is open to any client.
  - `pause`, `unpause`, `setPage`, `reloadPrefs`, `resumeSchedule`, `reloadCurrent`, `refreshActivity` and `restartDaemon` need `role=admin`. The control queue counts as admin (it is written by control.php / the admin).
  - Display clients (and clients with no valid token) can connect read-only.
- The root `index.html`:
  - fetches the display token via XHR
  - opens the socket with the token when possible
  - normalizes runtime URLs to root-relative paths
  - uses `iframe.src` instead of `contentWindow.location`
  - adds a reload-stamp query when the target URL is unchanged
  - never navigates to an empty or `about:` URL
  - The physical frame only picks up a new `index.html` after VSS reloads the shell.
- For local automations, prefer `control.php` over direct WebSocket writes.
  - `setPage` creates a 30-minute manual override and then returns to schedule automatically

### Scheduler behavior (visionectd)

- Rotation is frozen while paused, away or asleep, so `curPage` always matches what the frame shows.
- PREFS: invalid or empty PREFS.json keeps the last good PREFS; the daemon never sends an empty URL. If nothing is eligible, it stays on the current page.
- `nextPage` is precomputed and is the page that really comes next. All chances 0 → uniform pick.
- Minute work (timeslot, activity) runs on wall-clock minute changes, so no minute is skipped. Timeslots crossing midnight (e.g. 22:00–06:00) work.
- HA presence is checked at most once a minute with a 2s connect / 3s total timeout.
- `runtime_status.json` → `display` is written only when it changes.
- `restartDaemon` makes visionectd exit cleanly (after ~1.5s); a `restartDaemon` queued before the current daemon started is ignored, and any queued command older than 5 minutes is dropped.

### Logs-based truth

- Recent log evidence showed the jump path working end to end:
  - admin sent `setPage`
  - `visionectd.php` logged `Received message ... {"task":"setPage","page":"..."}`
  - the Visionect user agent immediately requested the new module URL
- Example sequence seen live:
  - `setPage -> quotes`
  - frame GET `/quotes/`
  - `setPage -> haynesmann`
  - frame GET `/haynesmann/`
  - `resumeSchedule`
  - frame GET `/art/`
- When debugging future “button does nothing” reports, trust the logs first before assuming the control path is broken.

### Secret handling

- Passwords are stored hashed in `config/admin_account.json`.
- General frame settings are stored in `config/general_settings.json`.
- Legacy sleep values may still exist in `config/ha_integration.json`, but runtime and UI now treat `general_settings.json` as the source of truth. Fallback logic exists so older installs keep working until the settings are re-saved.
- Secrets the app must reuse are encrypted at rest, not hashed:
  - Home Assistant token in `config/ha_integration.json`
  - AiNews provider keys in `htdocs/ainews/config.json`
- Encryption/decryption lives in `lib/security.php`.
- Secrets are write-only in the admin. The browser only sees `{set: bool}`, a blank field keeps the stored value, and `clear_secrets` empties a field (see API quick reference).
- `htdocs/.htaccess` denies `config.json` and `*.bak*` files over HTTP. PHP still reads them from disk.
- Secret files in `config/` (`secret_key.b64`, `admin_account.json`, `ha_integration.json`) are mode 600. `gocomics_auth.json` is 600 after a manual admin save and 644 when cookie-refresh writes it. `control_queue/` is 750.

### Manual cron implementation note

- `admin/api.php?action=run_module_cron&module=<name>` runs the module `cron.php` through PHP CLI under the same per-module lock as `cli/cron.php`.
- It captures combined stdout/stderr and the process exit code, then records `cron.<module>.last_cli_result` (`at`, `kind`, `exit_code`, `ok`) and `cron.<module>.last_output` in `runtime_status.json`. Output is capped at 8,000 characters, like the CLI runner.
- The API returns `ok`, `exit_code`, `output` and `last_cli_result`; the admin refreshes runtime status and shows an error toast for a nonzero exit.
- Do not rely on `PHP_BINARY` under Apache for this. The code resolves a CLI-safe binary using a small candidate list before falling back to `php`.

### Current known problems

- GoComics can still fail if cookie refresh is stale or unavailable. The cookie-refresh service should handle this, but the comics module also keeps stale/manual fallbacks so the page still renders.
- Leftovers (minor):
  - `docker restart visionect-web-content` takes about 10s, because PID 1 is `sh` (ignores SIGTERM, so Docker waits, then SIGKILLs). The admin restart button avoids this.
  - The frame uses a new `index.html` only after VSS reloads the shell.
- If this app is exposed publicly behind a reverse proxy later, session cookie `Secure` handling should be made proxy-aware in `lib/security.php`.
- The last deployment batch had one transient SSH reset while re-copying `htdocs/index.html`, so if root-shell behavior ever seems inconsistent, verify the live file contents directly before assuming the latest local copy is active.

### Newspaper behavior note

- `newspaper/index.php` no longer rotates papers with browser `localStorage`.
- Paper selection is now server-side so the app can know exactly which paper was served to the frame.
- It supports:
  - `?prefix=NY_NYT` to pin a specific paper
  - automatic round-robin selection across enabled papers for real frame requests
- The round-robin position is stored in `runtime_status.json` under `modules.newspaper.next_index`, updated via the locked `visionect_mutate_runtime_status()`. Papers whose image is missing are skipped.

### Schedule UI note

- The schedule editor intentionally uses:
  - 5 weekday cards in row one
  - 2 weekend cards in row two
  - plus 3 empty spacer cells so Saturday/Sunday stay the same width as weekdays

### Verification shortcuts

- To confirm exact frame tracking is working, curl a module locally on the host and then inspect `config/runtime_status.json`.
- Examples:
  - `curl -s "http://127.0.0.1:4412/art/" > /dev/null`
  - `curl -s "http://127.0.0.1:4412/newspaper/" > /dev/null`
  - `sed -n '/"frame"/,/}/p' ~/config/visionect/webserver/app/config/runtime_status.json`
- For random modules, this is more trustworthy than reloading the admin preview and assuming it matches the real frame.
- To prove a jump reached the device:
  - `docker logs visionect-web-content --tail 80`
  - look for `Received message ... {"task":"setPage",...}`
  - then look for a matching `WebKit-VisionectOkular` request to the same module path

---

## Cron Schedule

- There is **no crontab inside the container**. The host **root** crontab (host and container TZ is America/Phoenix, no DST) has:
  - `5 6 * * * docker exec visionect-web-content php /app/cli/cron.php --run` — 06:05 local / 13:05 UTC, **once a day**
  - `40 3 * * 1 docker restart visionect-web-content` — weekly restart (Mon 03:40, frame asleep); runs no crons
- The container command is still `sh -c "apache2-foreground & cd /app/cli && php cron.php && php visionectd.php"`. `cron.php` with no args is a no-op (prints one line, exits 0), so visionectd starts right away and a restart never re-runs the paid ainews cron.
- `cli/cron.php --run` runs every module with a `cron.php` that is **enabled in PREFS.json** (modules missing from PREFS count as disabled), ainews last. `--run <module>` runs one module (enabled or not); `--dry-run` only lists.
- Each module runs as `php cron.php 2>&1` under `config/cron_<module>.lock`. Its exit code goes to `runtime_status.json` → `cron.<module>.last_cli_result` (`exit_code`, `ok`), and the run summary to `cron_runner` (`ran`, `failed`, `skipped`). The runner exits 0 (all ok), 1 (a module failed) or 2 (usage).
- `visionect-cookie-refresh` refreshes GoComics cookies at 05:55 America/Phoenix, 10 minutes before.

## Running Cron Manually

```bash
# Run all enabled module crons (exactly what the daily host crontab does)
docker exec visionect-web-content php /app/cli/cron.php --run

# See what would run
docker exec visionect-web-content php /app/cli/cron.php --run --dry-run

# Run one module (records last_cli_result)
docker exec visionect-web-content php /app/cli/cron.php --run comics
```

---

## Docker Logs

View logs: `docker logs visionect-web-content --tail 50`

To tail live: `docker logs visionect-web-content -f`

**Known issue — log corruption:** Imagick can leak binary data to stdout, which corrupts Docker's JSON log file with null bytes (`\x00`). Symptom: `docker logs` throws `invalid character '\x00' looking for beginning of value`.

**Fix (no restart needed):**
```bash
# Get container ID
docker inspect visionect-web-content --format='{{.Id}}'
# Truncate the corrupted log file
sudo truncate -s 0 /var/lib/docker/containers/<id>/<id>-json.log
```

**Prevention:** Prefer `visionect_image_to_frame()` (`lib/image.php`), which already does this. Any direct Imagick code must wrap its operations in output buffering:
```php
ob_start();
try {
    $img = new Imagick($src);
    // ... imagick operations ...
    ob_end_clean();
    return true;
} catch (Exception $e) {
    ob_end_clean();
    print 'Imagick error: ' . $e->getMessage() . "\n";
    return false;
}
```

---

## Common Gotchas

| Problem | Cause | Fix |
|---------|-------|-----|
| Admin dropdown does nothing for a module | visionectd loaded PREFS.json before that module was added | Restart visionectd process in container |
| HTTPS fetch returns empty/false | `file_get_contents()` broken for HTTPS in container | Use `visionect_http_get()` (`lib/http.php`) |
| Image shows wrong size / not filling frame | Image not resized to 1440×2560 | `visionect_image_to_frame()` with `contain` (letterbox) or `cover` |
| Image is color / looks wrong on display | Not converted to greyscale | `visionect_image_to_frame()` always converts to greyscale |
| Fatal `Call to undefined function str_starts_with()` | Container runs PHP 7.4 | Use `strpos($s, $p) === 0` / `strncmp()` |
| Fatal `Cannot redeclare function visionect_...` | A lib was loaded twice with `require` | Always `require_once` lib files |
| Admin/visionectd sees empty prefs or status, frame goes blank | Torn JSON from a non-atomic write | Write with `visionect_write_json_atomic()`; use `visionect_mutate_runtime_status()` for read-modify-write |
| `docker restart visionect-web-content` takes ~10s | PID 1 is `sh`, which ignores SIGTERM | Expected; the admin restart button is faster (~2s) |
| Crons "didn't run at 16:05" / after a restart | They only run once, at 06:05 America/Phoenix (host `docker exec ... cron.php --run`); restarts run no crons | See Cron Schedule; run manually with `docker exec ... php /app/cli/cron.php --run` |
| WS `pause`/`setPage` ignored, log says `(role display)` | The client used a display token | Use an admin token (`api.php?action=ws_token`) or `control.php` |
| Comic strips show old/stale content | Images saved as `.gif` with JPEG data inside | Save as `.jpg` from the start |
| GoComics strips stay stale | cookie refresh failed or Bunny challenge still returned | Check `gocomics_auth.json`, cookie-refresh schedule, and Comics source status in admin |
| Comics admin shows `blocked` | automatic GoComics refresh failed | Upload a strip manually or switch that strip to a direct image URL |
| `docker logs` throws null byte error | Imagick leaked binary data to stdout | Wrap Imagick in `ob_start/ob_end_clean`; truncate log file to recover |
| New module not running in cron | Module missing from PREFS.json or disabled (`--run` skips both) | Add it to PREFS `pages` and enable it; check with `cli/cron.php --run --dry-run` |
| Far Side images are SVGs | Wrong HTML attribute used | Use `data-src`, not `src` |
| Dilbert regex not matching | No space between `alt` and `src` in viewer HTML | Use `[^>]*` not `[^>]+` between the two attributes |
| Dilbert fetch times out | Heroku dyno is cold/sleeping | Wake-up GET to root URL already added; if still failing, dyno may be dead |
| ainews article fetch slow/failing | Bot-blocker or paywall — VisionectBot UA was blocked | Chrome User-Agent (`lib/http.php`); 8s timeout; challenge pages fall back to the RSS description |
| Pollinations image fails (400/URL too long) | image_prompt too long for GET URL | Pollinations uses a separate auto-generated short prompt, capped at 400 chars |
| HuggingFace API 410 error | Old api-inference.huggingface.co endpoint retired | Use router.huggingface.co instead (already in code) |
| ainews illustration looks square / letterboxed | Gemini returned 1:1 image despite aspectRatio request | Add `imageSize: "2K"` to imageConfig — required for consistent ratio enforcement |
| kie.ai poll returns 401 Unauthorized | Auth header missing on the poll request | Pass `['Authorization: Bearer ' . $apiKey]` as the headers arg to `visionect_http_get()` when polling kie.ai (create AND poll) |
| kie.ai nano-banana image sizing | Wrong parameter name — `aspect_ratio` is silently ignored | Use `image_size` instead (e.g. `"9:16"`); returns 768×1344 which letterboxes cleanly to 1440×2560 |
| RSS feed silently returns no stories | Feed is Atom format, not RSS | Parser uses `channel->item` (RSS only). Atom feeds have `<entry>` not `<item>`. Test new feeds before adding. |

---

## Changelog

- **2026-10-05** — Admin manual "Run now" cron actions now record the process exit code and output in runtime status and show failures in the admin.
- **2026-10-05** — Cleanup from the 2026-10-05 review.
  - Libs: atomic/locked writes in `lib/security.php`; new `lib/http.php`, `lib/image.php` and `lib/gallery_page.php`; PHP 7.4 fix (`str_starts_with` removed).
  - Admin: self-hosted vendor scripts, write-only secrets, `ws_token` and `thumb` actions, POST-only `restart`/`account`/`ha_status`.
  - Modules: comics, ainews and newspaper hardened (temp + rename, validated downloads); cookie-refresh now only refreshes cookies, daily at 05:55 America/Phoenix; all gallery images normalised to 1440×2560 greyscale.
  - Deleted: `weather/`, `unsplash/`, `clock/clock.html`, `clock/index.php.ok`, `haynesmann/index2.php`, `comics/settings.php` and test files, newspaper leftover crons, `admin/js|css|fonts|manifest.json`.
  - Docs corrected: crons run once daily at 06:05 America/Phoenix.
  - Runtime phase (same day): crons via host `docker exec ... cron.php --run` (no-op at container start) with recorded exit codes; weekly restart; WS token roles + auth reply; one `handleCommand`; control queue as one file per command; working admin restart (`restartDaemon`); visionectd fixes (frozen rotation, last-good PREFS, no empty URL, short HA timeout, minute tracking, midnight slots, write on change); new `lib/runtime.php`; optional control token; secret files 600; deleted `cli/test.php`, `htdocs/time.php`, `PREFS.json.old`.
