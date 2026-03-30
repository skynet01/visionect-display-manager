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

- **NEVER update, restart, or reconfigure** the Visionect server containers (`vss`, `visionect-pdb`, `visionect-web-content`, `visionect-redis`). The Visionect server software is locked to a specific version to avoid a subscription requirement. Only edit files inside `htdocs/` and `config/`.
- **Display is black & white e-ink.** All images must be greyscale. Use Imagick's `transformImageColorspace(Imagick::COLORSPACE_GRAY)` before saving.
- **Display resolution:** 1440×2560 px. All full-screen content must match exactly.
- **PHP `file_get_contents()` fails for HTTPS** inside this container. Always use `curl_exec()` for any HTTP/HTTPS requests. See the `curlGet()`/`curlPost()` helpers in `ainews/cron.php` as a pattern to copy.
- **All images must be saved as `.jpg`** — the display cannot render GIF/PNG/WebP. Always convert with Imagick before saving.
- **Imagick must be wrapped in `ob_start()` / `ob_end_clean()`** — Imagick can leak binary data to stdout which corrupts Docker's JSON log file. See the Docker Logs section below.

---

## Architecture Overview

```
Display device
    └── polls Visionect Server (vss container, port 8080)
            └── WebSocket server (visionectd.php, port 12345)
                    └── tells display which URL to load
                            └── Apache serves htdocs/ pages
```

### Key files

| Path | Purpose |
|------|---------|
| `app/config/PREFS.json` | Master config: all pages, timeslots, scheduling |
| `app/cli/visionectd.php` | WebSocket server — reads PREFS.json at startup, sends URLs to display |
| `app/cli/cron.php` | Master cron — loops all `htdocs/*/cron.php` files; ainews always runs last |
| `app/htdocs/admin/index.php` | Admin panel UI at `/admin` — lets you override current page |
| `app/htdocs/` | One subfolder per module |

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
4. **Restart visionectd** so it reloads PREFS.json: `docker exec visionect-web-content kill <pid>` — it will restart automatically from the container's startup command. Or restart the whole container (apache will also restart, which is fine).

To check visionectd PID: `docker exec visionect-web-content ps aux | grep visionectd`

---

## Module Structure

Each module is a folder in `htdocs/`:

```
htdocs/modulename/
    index.php       ← rendered by the display (required)
    cron.php        ← optional, run daily by cli/cron.php
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

- Shebang line: `#!/usr/local/bin/php`
- `chdir(__DIR__)` at the top so relative paths work
- **Use `curl_exec()` for all HTTP(S)** — `file_get_contents()` fails on HTTPS
- **Save all images as `.jpg`** — never `.gif`, `.png`, or `.webp`
- **Wrap all Imagick operations in `ob_start()` / `ob_end_clean()`** — prevents binary stdout corruption of Docker logs
- Write output files (images, data.json) to the module's own folder
- Print progress to stdout — it shows in `docker logs visionect-web-content`

---

## Modules Reference

### `clock/`
Static clock display. Multiple variants (`clock.html`, `clock.digital.html`, etc.). No cron.

### `art/`
Rotates through a collection of black & white art JPEGs (`bw-art-*.jpg`). No cron (images added manually).

### `quotes/`
Rotates through quote images (`*.jpg`). No cron (images added manually).

### `haynesmann/`
Static/manual content. No cron.

### `newspaper/`
Downloads front pages of major newspapers daily via cron.php. Config in `config.json`:
```json
{
  "NewYorkTimes": {"prefix": "NY_NYT", "style": "...css..."},
  "WallStreetJournal": {"prefix": "WSJ", "style": "..."},
  "USAToday": {"prefix": "USAT", "style": "..."}
}
```

### `comics/`
Downloads daily comic strips. Cron fetches from GoComics (via curl + og:image scraping) and thefarside.com.

**Current strip order:** Far Side panels (row 1) → Garfield → Dilbert → Calvin & Hobbes → Pearls Before Swine

**Strips:**
- Garfield, Calvin & Hobbes, Pearls Before Swine — GoComics (`fetchGoComics()` helper, tries today/yesterday/two days ago)
- Dilbert — `https://dilbert-viewer.herokuapp.com/YYYY-MM-DD` with a random date from the 1989–2023 archive each day. A wake-up GET to the Heroku root is made first to prevent cold-start timeouts (dyno sleeps after inactivity).
- Far Side — scraped from `thefarside.com`, up to 4 panels, using `data-src` attribute (not `src` which is an SVG placeholder)

**All images saved as `.jpg`** via `convertToJpg()` helper (Imagick greyscale conversion, wrapped in `ob_start/ob_end_clean`).

**Layout engine** (`index.php`):
- Far Side: row of individual panels at top (up to 4 side by side), panel padding `15px 5px`, caption `margin-top: 15px`, font `1.05em`
- Comic strips: shown full-width below, as many as fit without cutting off
- Gap constants: `GAP_STRIP=32`, `GAP_MIN=6`, `GAP_MAX=48`
- Gap squeeze algorithm: tries standard gap → min gap → drops last strip
- All content vertically centered

**Metadata pattern:** cron.php writes `metadata.json` with image dimensions; index.php reads it for layout math at render time.

**GoComics scraping note:** Pages now use Next.js but `og:image` meta tag is still in standard HTML. Use DOMDocument + XPath `//meta[@property="og:image"]`. Requires curl with a browser User-Agent — `file_get_contents()` fails.

**Dilbert regex note:** The viewer HTML has no space between `alt` and `src` attributes. Use `[^>]*` (not `[^>]+`) between them: `/<img[^>]+alt="Comic for [0-9-]+"[^>]*src=([^\s>]+)/i`

### `ainews/` — AI News Module
Generates daily AI-illustrated news stories (one per source). Each story gets its own full-screen page; `index.php` picks one at random on each load.

**Config file** (`config.json`) — all behaviour is configurable here:
```json
{
  "groq_api_key": "...",
  "kie_api_key": "...",
  "gemini_api_key": "...",
  "gemini_model": "gemini-2.5-flash-image",
  "pollinations_api_key": "...",
  "huggingface_api_key": "...",
  "summary_words": 60,
  "summary_prompt": "Summarize this news story in about {words} words. Simple plain language, no jargon, no bullets.",
  "image_prompt": "...full detailed prompt for kie.ai and Gemini. Use {title} and {summary} placeholders...",
  "sources": [
    {"label": "World",    "feed": "https://www.aljazeera.com/xml/rss/all.xml"},
    {"label": "Business", "feed": "https://feeds.bbci.co.uk/news/business/rss.xml"},
    {"label": "Tech",     "feed": "https://rss.nytimes.com/services/xml/rss/nyt/Technology.xml"},
    {"label": "Tech",     "feed": "https://feeds.arstechnica.com/arstechnica/index"},
    {"label": "Global",   "feed": "https://feeds.bbci.co.uk/news/rss.xml"}
  ]
}
```

**RSS feeds must be RSS, not Atom.** The parser uses `$feed->channel->item[0]` (RSS structure). Atom feeds (`<feed xmlns="...Atom">` with `<entry>` elements) will parse silently but return no items. Always verify a new feed URL returns proper `<channel><item>` XML before adding it.

Add/remove sources freely — story count is fully dynamic, no hardcoded limit anywhere.

**Prompt placeholders:**
- `summary_prompt`: use `{words}` for word count
- `image_prompt`: use `{title}` and `{summary}` for story content

**How cron.php works:**
1. For each source: fetch RSS → parse first item → attempt full article body fetch (6s timeout) → summarize with Groq
2. Generate satirical Far Side/Mad Magazine-style illustration via image cascade
3. Resize image to fit 1440×2560 with black letterbox padding using Imagick (no cropping — preserves speech bubbles)
4. Write `data.json` with all story metadata
5. Images saved as `story1.jpg`, `story2.jpg`, etc. in module root

**cron.php runs last** in the master cron chain (all other modules run first). This is intentional — ainews is the slowest job and shouldn't hold up the others.

**Article fetch:** 6s timeout (sites that don't respond in 6s are usually paywalled or blocking bots). Article body capped at 2000 chars before sending to Groq — more than enough for a 50-word summary. Uses Chrome User-Agent to avoid bot blocks.

**APIs used:**
- Groq (`llama-3.3-70b-versatile`) — summarization via `https://api.groq.com/openai/v1/chat/completions`
- Image generation — cascades automatically: **kie.ai → Gemini → Pollinations → HuggingFace**, first success wins. No `image_provider` config needed; just populate the API keys you want active.
  - **kie.ai** (`google/nano-banana`) — primary. Async API: POST `/api/v1/jobs/createTask` → poll `GET /api/v1/jobs/recordInfo?taskId=...` with `Authorization: Bearer` header (required on both create AND poll). `input.aspect_ratio = "9:16"` requested but the nano-banana model returns 1024×1024 square images regardless — they get letterboxed into the 1440×2560 frame. State field values: `waiting` / `queuing` / `generating` / `success` / `fail`. Result URL in `data.resultJson` (JSON string) → `resultUrls[0]`. Fast (~10s). Free tier available.
  - **Gemini** (`gemini-2.5-flash-image`) — second. Uses full detailed `image_prompt` from config. 9:16 aspect ratio + 2K resolution via `generationConfig: { imageConfig: { aspectRatio: "9:16", imageSize: "2K" } }`. **`imageSize` is required for consistent ratio** — without it Gemini sometimes returns 1:1 square images. Field is `imageConfig` NOT `imageGenerationConfig`. Base64 image in `candidates[0].content.parts[n].inlineData.data`. Note: `gemini-2.5-flash-image` has no free tier — it will return a quota error on free API keys. Raw dimensions logged to cron output.
  - **Pollinations** — GET `https://gen.pollinations.ai/image/{prompt}?...&key=...`. Uses an **auto-generated concise prompt** (not `image_prompt` from config — FLUX diffusion models work better with short keyword-style prompts). Prompt auto-capped at 400 chars to avoid Cloudflare 400 errors from long URLs.
  - **HuggingFace** (`FLUX.1-schnell`) — POST `https://router.huggingface.co/hf-inference/models/black-forest-labs/FLUX.1-schnell`. Uses same concise prompt as Pollinations. Returns JPEG binary directly. Free tier ~1000 req/month. Note: old `api-inference.huggingface.co` retired (410); always use `router.huggingface.co`.
  - To list all available Gemini model names: `curl 'https://generativelanguage.googleapis.com/v1beta/models?key=KEY'`

**Layout:** Full-frame illustration as background, gradient overlay fixed to bottom 20% of frame (512px), title + summary overlaid. Movie poster style.

**Fallback:** If RSS fetch/parse fails, the previous day's full story is kept. If image generation fails and a previous image exists, it is reused.

---

## Admin Panel (`/admin`)

- Shows live preview of current display content (iframe, low contrast)
- Dropdown to override current page — sends `{"task":"setPage","page":"modulename"}` over WebSocket to visionectd
- Pause/unpause button
- **If a page doesn't appear in the dropdown:** visionectd loaded PREFS.json before you added it. Restart visionectd (kill its process, the container startup command will relaunch it).

### Admin UI expansion

- `/admin/api.php` now owns server-side reads and writes for `PREFS.json`, module `config.json` files, gallery uploads, feed validation, and newspaper discovery.
- `reloadPrefs` is available over the WebSocket server so the admin can save updated scheduling data without restarting the container.
- `comics/config.json` now controls gap tuning plus strip enablement and order, and both `comics/index.php` and `comics/cron.php` read from it.
- `clock/config.json` controls which clock templates are eligible on each render.
- Gallery uploads are converted to grayscale JPG via Imagick and must keep the `ob_start()` / `ob_end_clean()` wrapper.

### Current admin capabilities

- Auth is required for `/admin` and `/admin/api.php`.
- A first-run setup flow now exists:
  - if `config/admin_account.json` is missing, `/admin` shows a bootstrap screen to create the first account
  - the password is hashed and the user is logged in immediately
  - once the account exists, the bootstrap screen disappears and normal sign-in takes over
- The admin account can be renamed and its password changed from the UI.
- The top-bar settings panel is now split into:
  - `General` for frame resolution plus mirrored frame sleep settings
  - `Home Assistant` for presence pause only
- The mirrored frame sleep settings do not put the panel to sleep. They exist so the admin can reflect the physical frame's own sleep window.
- The admin header shows the active slot and live countdown using runtime status from the WebSocket server.
- When the frame is asleep, both the admin header countdown and the login screen `Next update` value switch to the next wake time instead of the normal page-rotation timer.
- The live page now shows:
  - `Upcoming module` / `Upcoming reload` preview on the left
  - `Current frame` preview on the right
  - the `Current frame` card also shows when that exact frame was served
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

### Exact frame tracking

- `lib/security.php` now writes exact frame-response metadata into `config/runtime_status.json`.
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
- `status.php?ws_token=1` is public and returns a short-lived display WebSocket token.
- The root `index.html` now:
  - fetches that token via XHR
  - opens the socket with the token when possible
  - normalizes runtime URLs to root-relative paths
  - uses `iframe.src` instead of `contentWindow.location`
  - adds a reload-stamp query when the target URL is unchanged
- However, the worker was also made backward-compatible:
  - display clients may connect read-only without auth
  - admin-only tasks like `setPage`, `pause`, `reloadPrefs`, and `resumeSchedule` still require an authenticated token
- This compatibility change was necessary because the real frame was still using an older public shell in some tests.

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

### Manual cron implementation note

- `admin/api.php?action=run_module_cron&module=<name>` runs the module `cron.php` through PHP CLI.
- Do not rely on `PHP_BINARY` under Apache for this. The code now resolves a CLI-safe binary using a small candidate list before falling back to `php`.

### Current known problems

- GoComics is currently blocked by Bunny Shield / anti-bot protection. The scraper gets a challenge page instead of comic HTML, so fresh GoComics strips fail even though older cached images may still exist.
- `newspaper/cron.php` still uses the older fetch implementation and was intentionally not refactored in the recent hardening pass.
- If this app is exposed publicly behind a reverse proxy later, session cookie `Secure` handling should be made proxy-aware in `lib/security.php`.
- The last deployment batch had one transient SSH reset while re-copying `htdocs/index.html`, so if root-shell behavior ever seems inconsistent, verify the live file contents directly before assuming the latest local copy is active.

### Newspaper behavior note

- `newspaper/index.php` no longer rotates papers with browser `localStorage`.
- Paper selection is now server-side so the app can know exactly which paper was served to the frame.
- It supports:
  - `?prefix=NY_NYT` to pin a specific paper
  - automatic round-robin selection across enabled papers for real frame requests
- The round-robin position is stored in `runtime_status.json` under `modules.newspaper.next_index`.

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

## Running Cron Manually

```bash
# Run all module crons (same as what the system does daily)
docker exec visionect-web-content php /app/cli/cron.php

# Run a specific module's cron
docker exec visionect-web-content php /app/htdocs/ainews/cron.php
docker exec visionect-web-content php /app/htdocs/comics/cron.php
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

**Prevention:** Always wrap Imagick operations in output buffering:
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
| HTTPS fetch returns empty/false | `file_get_contents()` broken for HTTPS in container | Use `curl_exec()` instead |
| Image shows wrong size / not filling frame | Image not resized to 1440×2560 | Use Imagick letterbox: `thumbnailImage` + composite on black canvas |
| Image is color / looks wrong on display | Not converted to greyscale | Use `transformImageColorspace(Imagick::COLORSPACE_GRAY)` |
| Comic strips show old/stale content | Images saved as `.gif` with JPEG data inside | Save as `.jpg` from the start |
| `docker logs` throws null byte error | Imagick leaked binary data to stdout | Wrap Imagick in `ob_start/ob_end_clean`; truncate log file to recover |
| New module not running in cron | cron.php not executable or missing shebang | Add `#!/usr/local/bin/php` on line 1, `chmod +x cron.php` |
| ainews cron silently skipped by master cron | deploy/copy step stripped the executable bit | After every deploy of ainews/cron.php run: `chmod +x app/htdocs/ainews/cron.php` on the host |
| Far Side images are SVGs | Wrong HTML attribute used | Use `data-src`, not `src` |
| Dilbert regex not matching | No space between `alt` and `src` in viewer HTML | Use `[^>]*` not `[^>]+` between the two attributes |
| Dilbert fetch times out | Heroku dyno is cold/sleeping | Wake-up GET to root URL already added; if still failing, dyno may be dead |
| ainews article fetch slow/failing | Bot-blocker or paywall — VisionectBot UA was blocked | Chrome User-Agent used now; 6s timeout drops non-responsive sites fast |
| Pollinations image fails (400/URL too long) | image_prompt too long for GET URL | Pollinations uses a separate auto-generated short prompt, capped at 400 chars |
| HuggingFace API 410 error | Old api-inference.huggingface.co endpoint retired | Use router.huggingface.co instead (already in code) |
| ainews illustration looks square / letterboxed | Gemini returned 1:1 image despite aspectRatio request | Add `imageSize: "2K"` to imageConfig — required for consistent ratio enforcement |
| kie.ai poll returns 401 Unauthorized | curlGet doesn't send auth headers by default | Pass `['Authorization: Bearer ' . $apiKey]` as third arg to curlGet when polling kie.ai |
| kie.ai nano-banana image sizing | Wrong parameter name — `aspect_ratio` is silently ignored | Use `image_size` instead (e.g. `"9:16"`); returns 768×1344 which letterboxes cleanly to 1440×2560 |
| RSS feed silently returns no stories | Feed is Atom format, not RSS | Parser uses `channel->item` (RSS only). Atom feeds have `<entry>` not `<item>`. Test new feeds before adding. |
