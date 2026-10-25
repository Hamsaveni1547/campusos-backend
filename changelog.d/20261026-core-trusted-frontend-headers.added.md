Accept the real browser IP and user agent from trusted CampusOS frontend servers.

- Config: `CAMPUSOS_TRUSTED_FRONTENDS` (comma-separated IPs, default `127.0.0.1,::1`).
- API: requests from those IPs may send `X-Client-IP` and `X-Client-User-Agent`; they are used for sign-in history, new-device alerts, rate limits and audit logs. Ignored from any other caller.
