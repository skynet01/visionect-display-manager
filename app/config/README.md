# Config Notes

This directory is intentionally kept mostly empty in the public repo.

Generated at runtime:

- `admin_account.json`
- `secret_key.b64`
- `runtime_status.json`
- `remote_control.json`
- `ha_integration.json`
- `general_settings.json`

Committed on purpose:

- `PREFS.json`
- `ha_integration.example.json`
- `general_settings.example.json`

Setup note:

- the admin account is created from the first-run setup screen at `/admin`
- General frame settings and Home Assistant settings are entered from the admin UI
- if you use the frame's own sleep schedule, mirror the same wake and sleep times in the `General` settings so the dashboard state stays accurate
