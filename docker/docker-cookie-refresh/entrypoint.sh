#!/bin/bash
set -e

echo "[entrypoint] cookie-refresh service starting (TZ=${TZ:-unset})"

run_refresh() {
    python3 /opt/refresh.py || echo "[entrypoint] WARNING: refresh failed"
}

run_refresh

while true; do
    # Run daily at 05:55 local time (America/Phoenix), 10 minutes before the
    # 06:05 comics cron that uses the cookies.
    SLEEP_SECS=$(python3 -c "
import os
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
tz = ZoneInfo(os.environ.get('TZ') or 'America/Phoenix')
now = datetime.now(tz)
target = now.replace(hour=5, minute=55, second=0, microsecond=0)
if target <= now:
    target += timedelta(days=1)
print(max(60, int((target - now).total_seconds())))
")
    echo "[entrypoint] Sleeping ${SLEEP_SECS}s until next refresh at 05:55 ${TZ:-America/Phoenix}"
    sleep "$SLEEP_SECS"
    run_refresh
done
