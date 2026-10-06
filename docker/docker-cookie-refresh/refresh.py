#!/usr/bin/env python3
"""
Solves BunnyCDN's Argon2id proof-of-work challenge for gocomics.com and saves
the resulting cookies to gocomics_auth.json (written atomically).

This service ONLY refreshes cookies. The PHP cron (htdocs/comics/cron.php)
downloads the strips and converts them to greyscale JPGs for the frame.

No browser required: pure Python.
"""
import json
import os
import re
import sys
import tempfile
import urllib.request
import urllib.error
import http.cookiejar
from datetime import datetime, timezone

CONFIG_FILE = "/app/htdocs/comics/config.json"
AUTH_FILE   = "/app/config/gocomics_auth.json"
BASE_URL    = "https://www.gocomics.com"
USER_AGENT  = (
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/146.0.0.0 Safari/537.36"
)
# Expected ~256 iterations at diff=13; give up long before this could run for hours.
POW_MAX_ITERATIONS = 20000


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


def http_get(opener, url: str) -> tuple:
    req = urllib.request.Request(url, headers={
        "User-Agent": USER_AGENT,
        "Accept": "text/html,application/xhtml+xml,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.5",
    })
    try:
        with opener.open(req, timeout=20) as resp:
            return resp.status, resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        return e.code, ""
    except Exception as e:
        print(f"  [http] GET {url}: {e}", flush=True)
        return 0, ""


def solve_pow(data_pow: str):
    """
    Solve BunnyCDN's Argon2id PoW. Returns the answer as a string, or None if
    no answer was found within POW_MAX_ITERATIONS.
    data-pow format: userkey#challenge#timestamp#signature
    Workers compute: Argon2id(pass=challenge+str(i), salt=userkey, t=2, m=512, hashLen=32, p=1)
    Find i where hash hex passes difficulty check (diff=13, diffString="0").
    """
    from argon2.low_level import hash_secret_raw, Type

    parts = data_pow.split("#")
    if len(parts) < 2:
        print("  [pow] Malformed data-pow", flush=True)
        return None
    userkey   = parts[0]
    challenge = parts[1]

    diff       = 13
    diff_chars = diff // 8        # = 1  -> diffString = "0"
    diff_str   = "0" * diff_chars
    # mask: 0xff >> (((diff_chars+1)*8) - diff) = 0xff >> 3 = 0x1f
    mask = 0xff >> (((diff_chars + 1) * 8) - diff)

    print(f"  [pow] Solving (Argon2id, diff={diff}, expect ~256 iterations)...", flush=True)
    for i in range(POW_MAX_ITERATIONS):
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
        if i and i % 1000 == 0:
            print(f"  [pow] Still solving... i={i}", flush=True)

    print(f"  [pow] Gave up after {POW_MAX_ITERATIONS} iterations", flush=True)
    return None


def bypass_challenge(opener, url: str) -> bool:
    """
    Fetch url, solve BunnyCDN PoW if challenged, return True if page is accessible.
    """
    status, html = http_get(opener, url)
    if status == 0:
        return False

    if "Establishing a secure connection" not in html and "bunny-shield" not in html:
        print(f"  [challenge] No challenge at {url}", flush=True)
        return True

    print("  [challenge] BunnyCDN challenge detected, solving PoW...", flush=True)
    m = re.search(r'data-pow="([^"]+)"', html)
    if not m:
        print("  [challenge] ERROR: data-pow not found in challenge page", flush=True)
        return False

    data_pow = m.group(1)
    answer = solve_pow(data_pow)
    if answer is None:
        return False
    pow_response = f"{data_pow}#{answer}"

    verify_url = f"{BASE_URL}/.bunny-shield/verify-pow"
    req = urllib.request.Request(
        verify_url,
        data=b"{}",
        headers={
            "User-Agent": USER_AGENT,
            "Content-Type": "application/json",
            "BunnyShield-Challenge-Response": pow_response,
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

    _, html2 = http_get(opener, url)
    if "Establishing a secure connection" in html2:
        print("  [challenge] Still challenged after PoW solve", flush=True)
        return False

    print("  [challenge] Challenge bypassed successfully", flush=True)
    return True


def cookie_expiry(cookie) -> int:
    """Expiry (unix time) of a bunny_shield* cookie, or 0 if unknown.

    Older cookies were named "bunny_shield" with the expiry embedded in the
    value (key#sig#<unix>); newer ones are e.g. "bunny_shield_id_33498" and
    only carry a normal Set-Cookie expiry (or none, for session cookies).
    """
    parts = (cookie.value or "").split("#")
    if len(parts) >= 3 and parts[2].isdigit():
        return int(parts[2])
    if cookie.expires:
        return int(cookie.expires)
    return 0


def write_json_atomic(path: str, data: dict) -> None:
    directory = os.path.dirname(path)
    fd, tmp = tempfile.mkstemp(prefix="." + os.path.basename(path) + ".", suffix=".tmp", dir=directory)
    try:
        with os.fdopen(fd, "w") as f:
            json.dump(data, f, indent=2)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp, 0o644)
        # This container runs as root; give the file to the owner of the config dir (the web
        # app's uid 1000) so the admin's manual cookie save can still overwrite it.
        st = os.stat(directory)
        try:
            os.chown(tmp, st.st_uid, st.st_gid)
        except OSError:
            pass
        os.replace(tmp, path)
    except BaseException:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


def main() -> int:
    from argon2.low_level import hash_secret_raw, Type  # noqa: F401 (early import check)

    print(f"[refresh] Starting at {datetime.now(timezone.utc).isoformat()}", flush=True)
    os.makedirs(os.path.dirname(AUTH_FILE), exist_ok=True)

    try:
        strips = load_config()
    except Exception as e:
        print(f"[refresh] ERROR reading config: {e}", file=sys.stderr, flush=True)
        return 1

    if not strips:
        print("[refresh] No auto gocomics strips in config.json", flush=True)
        return 0

    cookie_jar = http.cookiejar.CookieJar()
    opener = make_opener(cookie_jar)

    # Solve the challenge once using the first strip's URL (local date, like the PHP cron)
    first_slug = strips[0]["slug"]
    warm_url = f"{BASE_URL}/{first_slug}/{datetime.now().strftime('%Y/%m/%d')}"
    print(f"[refresh] Solving BunnyCDN challenge via {warm_url}", flush=True)
    if not bypass_challenge(opener, warm_url):
        print("[refresh] ERROR: Could not bypass challenge", file=sys.stderr, flush=True)
        return 1

    # Cookie name is now e.g. bunny_shield_id_33498, so match by prefix.
    expires_at = 0
    all_cookies = []
    for cookie in cookie_jar:
        all_cookies.append(f"{cookie.name}={cookie.value}")
        if cookie.name.startswith("bunny_shield"):
            exp = cookie_expiry(cookie)
            if exp and (expires_at == 0 or exp < expires_at):
                expires_at = exp

    if not all_cookies:
        print("[refresh] WARNING: no cookies received; keeping the existing auth file", flush=True)
        return 1

    print(f"[refresh] Cookies: {', '.join(c.split('=')[0] for c in all_cookies)}", flush=True)
    if expires_at:
        print(f"[refresh] bunny_shield expires at {datetime.fromtimestamp(expires_at, timezone.utc).isoformat()}", flush=True)
    else:
        print("[refresh] bunny_shield expiry unknown (session cookie)", flush=True)

    existing = {}
    if os.path.exists(AUTH_FILE):
        try:
            with open(AUTH_FILE) as f:
                existing = json.load(f)
        except Exception:
            existing = {}
    # Drop the per-strip results left by the old strip-downloading version.
    existing.pop("strips", None)

    auth = {
        **existing,
        "cookies":      "; ".join(all_cookies),
        "expires_at":   expires_at,
        "refreshed_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "source":       "cookie-refresh",
    }
    write_json_atomic(AUTH_FILE, auth)
    print(f"[refresh] Wrote {AUTH_FILE}", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
