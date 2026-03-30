#!/usr/bin/env python3
"""
Solves BunnyCDN's Argon2id proof-of-work challenge for gocomics.com,
then writes fresh cookies to /app/config/gocomics_auth.json so the
PHP cron can use them for fetching strips.

No browser required — pure Python.
"""
import json
import os
import re
import sys
import urllib.request
import urllib.error
import http.cookiejar
from datetime import datetime, timezone, timedelta

CONFIG_FILE = "/app/htdocs/comics/config.json"
COMICS_DIR  = "/app/htdocs/comics"
AUTH_FILE   = "/app/config/gocomics_auth.json"
BASE_URL    = "https://www.gocomics.com"
USER_AGENT  = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/146.0.0.0 Safari/537.36"
)


def load_config() -> list:
    with open(CONFIG_FILE) as f:
        cfg = json.load(f)
    strips = cfg.get("strips", [])
    strips.sort(key=lambda s: s.get("order", 999))
    return [
        s for s in strips
        if s.get("enabled", True)
        and s.get("type") == "gocomics"
        and s.get("fetch_mode", "auto") == "auto"
    ]


def make_opener(cookie_jar) -> urllib.request.OpenerDirector:
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(cookie_jar),
        urllib.request.HTTPRedirectHandler(),
    )


def http_get(opener, url: str, extra_headers: dict = None) -> tuple[int, str]:
    req = urllib.request.Request(url, headers={
        "User-Agent": USER_AGENT,
        "Accept": "text/html,application/xhtml+xml,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.5",
    })
    if extra_headers:
        for k, v in extra_headers.items():
            req.add_header(k, v)
    try:
        with opener.open(req, timeout=20) as resp:
            return resp.status, resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        return e.code, ""
    except Exception as e:
        print(f"  [http] GET {url}: {e}", flush=True)
        return 0, ""


def solve_pow(data_pow: str) -> str:
    """
    Solve BunnyCDN's Argon2id proof-of-work challenge.

    data-pow format: userkey#challenge#timestamp#signature
    Algorithm: Argon2id(secret=challenge+str(i), salt=userkey, t=2, m=512, p=1, hashLen=32)
    Find i where the hex digest starts with "0" and digest[1] & 0x1f == 0 (diff=13).
    The PoW answer (i) is embedded in the bunny_shield cookie BunnyCDN issues on success.
    """
    from argon2.low_level import hash_secret_raw, Type

    parts     = data_pow.split("#")
    userkey   = parts[0]
    challenge = parts[1]

    diff       = 13
    diff_chars = diff // 8        # = 1 → diffString = "0"
    diff_str   = "0" * diff_chars
    mask       = 0xff >> (((diff_chars + 1) * 8) - diff)  # = 0x1f

    print(f"  [pow] Solving (Argon2id diff={diff})...", flush=True)
    i = 0
    while True:
        raw = hash_secret_raw(
            secret=(challenge + str(i)).encode(),
            salt=userkey.encode(),
            time_cost=2,
            memory_cost=512,
            parallelism=1,
            hash_len=32,
            type=Type.ID,
        )
        h = raw.hex()
        if h.startswith(diff_str) and (int(h[diff_chars], 16) & mask) == 0:
            print(f"  [pow] Solved at i={i} (hash prefix: {h[:6]})", flush=True)
            return str(i)
        i += 1
        if i % 100 == 0:
            print(f"  [pow] Still solving... i={i}", flush=True)


def bypass_challenge(opener, cookie_jar, url: str) -> bool:
    """
    Fetch url, solve BunnyCDN PoW if challenged, return True once accessible.

    BunnyCDN's verify-pow endpoint returns a bunny_shield cookie with Path=/
    and a ~1h TTL. The PoW answer (i) is encoded in the cookie value so
    BunnyCDN can verify server-side without storing state.
    """
    status, html = http_get(opener, url)
    if status == 0:
        return False

    if "Establishing a secure connection" not in html and "bunny-shield" not in html:
        print(f"  [challenge] No challenge detected at {url}", flush=True)
        return True

    print("  [challenge] BunnyCDN challenge detected, solving PoW...", flush=True)
    m = re.search(r'data-pow="([^"]+)"', html)
    if not m:
        print("  [challenge] ERROR: data-pow not found in challenge page", flush=True)
        return False

    data_pow = m.group(1)
    answer   = solve_pow(data_pow)

    verify_url = f"{BASE_URL}/.bunny-shield/verify-pow"
    req = urllib.request.Request(
        verify_url,
        data=b"{}",
        headers={
            "User-Agent": USER_AGENT,
            "Content-Type": "application/json",
            "BunnyShield-Challenge-Response": f"{data_pow}#{answer}",
            "Origin": BASE_URL,
            "Referer": url,
        },
    )
    try:
        with opener.open(req, timeout=20) as resp:
            verify_status = resp.status
    except urllib.error.HTTPError as e:
        verify_status = e.code
    except Exception as e:
        print(f"  [challenge] verify-pow error: {e}", flush=True)
        return False

    print(f"  [challenge] verify-pow status: {verify_status}", flush=True)
    if verify_status >= 400:
        print("  [challenge] PoW submission rejected", flush=True)
        return False

    status2, html2 = http_get(opener, url)
    if "Establishing a secure connection" in html2:
        print("  [challenge] Still challenged after PoW solve", flush=True)
        return False

    print("  [challenge] Challenge bypassed successfully", flush=True)
    return True


def get_og_image(html: str) -> str | None:
    m = re.search(r'<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']', html)
    if not m:
        m = re.search(r'og:image[^>]+content=["\']([^"\']+)["\']', html)
    return m.group(1) if m else None


def download_image(opener, url: str, dest: str) -> bool:
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with opener.open(req, timeout=30) as resp:
            data = resp.read()
        if len(data) < 1000:
            print(f"  [dl] Too small ({len(data)}b): {url}", flush=True)
            return False
        with open(dest, "wb") as f:
            f.write(data)
        print(f"  [dl] Saved {len(data)}b → {dest}", flush=True)
        return True
    except Exception as e:
        print(f"  [dl] Error: {e}", flush=True)
        return False


def fetch_strip(opener, slug: str, out_file: str) -> bool:
    dates = [
        datetime.now(timezone.utc).strftime("%Y/%m/%d"),
        (datetime.now(timezone.utc) - timedelta(days=1)).strftime("%Y/%m/%d"),
        (datetime.now(timezone.utc) - timedelta(days=2)).strftime("%Y/%m/%d"),
    ]
    for date in dates:
        url = f"{BASE_URL}/{slug}/{date}"
        status, html = http_get(opener, url)
        if status == 0:
            continue
        if "Establishing a secure connection" in html or "bunny-shield" in html:
            print(f"  [fetch] Still challenged at {date} — skipping", flush=True)
            continue
        og = get_og_image(html)
        if og:
            return download_image(opener, og, out_file)
    return False


def main() -> int:
    from argon2.low_level import hash_secret_raw, Type  # early import check

    print(f"[refresh] Starting at {datetime.now(timezone.utc).isoformat()}", flush=True)
    os.makedirs(os.path.dirname(AUTH_FILE), exist_ok=True)

    try:
        strips = load_config()
    except Exception as e:
        print(f"[refresh] ERROR reading config: {e}", file=sys.stderr, flush=True)
        return 1

    if not strips:
        print("[refresh] No auto gocomics strips configured — writing auth only", flush=True)
        # Still solve challenge to get fresh cookies for the PHP cron
        strips = [{"slug": "garfield", "label": "Garfield"}]

    cookie_jar = http.cookiejar.CookieJar()
    opener     = make_opener(cookie_jar)

    first_slug = strips[0]["slug"]
    warm_url   = f"{BASE_URL}/{first_slug}/{datetime.now(timezone.utc).strftime('%Y/%m/%d')}"
    print(f"\n[refresh] Solving BunnyCDN challenge via {warm_url}", flush=True)
    if not bypass_challenge(opener, cookie_jar, warm_url):
        print("[refresh] ERROR: Could not bypass challenge", file=sys.stderr, flush=True)
        return 1

    # Extract cookies and bunny_shield expiry
    expires_at  = 0
    all_cookies = []
    for cookie in cookie_jar:
        all_cookies.append(f"{cookie.name}={cookie.value}")
        if cookie.name == "bunny_shield":
            parts = cookie.value.split("#")
            if len(parts) >= 3 and parts[2].isdigit():
                expires_at = int(parts[2])
    cookie_header = "; ".join(all_cookies)
    print(f"[refresh] Cookies: {', '.join(c.split('=')[0] for c in all_cookies)}", flush=True)

    # Fetch strips (bonus — also done by PHP cron, but nice to have fresh copies)
    results = {}
    for strip in strips:
        slug  = strip["slug"]
        label = strip.get("label", slug)
        print(f"\n[refresh] Fetching: {label}", flush=True)
        out = os.path.join(COMICS_DIR, f"{slug}.jpg")
        ok  = fetch_strip(opener, slug, out)
        results[slug] = (
            {"ok": True,  "saved_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")}
            if ok else
            {"ok": False, "reason": "og:image not found or download failed"}
        )
        if not ok:
            print(f"  [refresh] FAILED: {slug}", flush=True)

    # Write auth.json — PHP cron reads this for CURLOPT_COOKIE injection
    existing = {}
    if os.path.exists(AUTH_FILE):
        try:
            with open(AUTH_FILE) as f:
                existing = json.load(f)
        except Exception:
            pass

    auth = {
        **existing,
        "cookies":      cookie_header,
        "expires_at":   expires_at,
        "refreshed_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "source":       "playwright",
        "strips":       results,
    }
    with open(AUTH_FILE, "w") as f:
        json.dump(auth, f, indent=2)

    ok_count = sum(1 for r in results.values() if r.get("ok"))
    print(f"\n[refresh] Done: {ok_count}/{len(results)} strips saved.", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
