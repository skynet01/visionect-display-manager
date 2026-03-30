#!/bin/bash
set -e

echo "[entrypoint] cookie-refresh service starting"

run_refresh() {
    python3 /opt/refresh.py || echo "[entrypoint] WARNING: refresh failed"
}

run_refresh

while true; do
    SLEEP_SECS=$(python3 -c "
from datetime import datetime, timezone, timedelta
now = datetime.now(timezone.utc)
# Run at 05:55 and 15:55 UTC — 10 minutes before each comic cron run (06:05 and 16:05)
candidates = []
for hour, minute in [(5, 55), (15, 55)]:
    t = now.replace(hour=hour, minute=minute, second=0, microsecond=0)
    if t <= now:
        t += timedelta(days=1)
    candidates.append(t)
target = min(candidates)
print(max(60, int((target - now).total_seconds())))
")
    echo "[entrypoint] Sleeping ${SLEEP_SECS}s until next refresh window"
    sleep "$SLEEP_SECS"
    run_refresh
done
