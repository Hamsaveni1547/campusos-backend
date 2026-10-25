# Institution, settings, files, notifications, audit (P0)

## Institution (M02 foundation)
| Method | Path | Permission |
|--------|------|------------|
| GET | `/institution` | any member |
| PATCH | `/institution` | institution.profile.manage (code/type are platform-managed; logo via `logo_file_id`, upload purpose `institution_logo`) |

## Platform administration (`is_platform_admin` only, MFA always required)
| Method | Path | |
|--------|------|-|
| GET/POST | `/platform/institutions` | POST provisions the institution and invites its first admin: `{ code, name, type, parent_id?, …, admin: { name, email, mobile? } }` |
| GET/PATCH | `/platform/institutions/{id}` | |
| POST | `/platform/institutions/{id}/suspend` (reason) · `/reactivate` | Suspension signs every member out |
| GET/PUT | `/platform/settings`, `/platform/settings/{group}` | Defaults inherited by every institution |
| GET | `/platform/monitoring/health`, `/queues`, `/failed-jobs` · POST `/failed-jobs/{id}/retry` · DELETE `/failed-jobs/{id}` | |

## Settings
`GET /settings`, `GET /settings/{group}` [admin.settings.view], `PUT /settings/{group}` [admin.settings.manage]. Groups: `security`, `files`, `general`. Each key comes with `label`, `type`, `default`, `value`. Send only the keys you change; unknown keys are ignored; invalid values → 422.

## Files
- `POST /files` (multipart: `file`, `purpose` = avatar | institution_logo | document | attachment | import) → file `id`.
- `GET /files/{id}` metadata, `GET /files/{id}/download-url` → short-lived signed `url` (5 min) to stream the file. Confidential downloads are logged.
- `DELETE /files/{id}` only for your own, unused uploads.
- Errors: `FILE_TYPE_NOT_ALLOWED`, `FILE_TOO_LARGE` (limit in settings `files.max_upload_kb`).

## Notifications
| Method | Path | |
|--------|------|-|
| GET | `/me/notifications` | `filter[unread]=1`, `filter[category]`; cursor pagination (`meta.next_cursor`, pass `?cursor=`) |
| GET | `/me/notifications/unread-count` | for the bell badge |
| POST | `/me/notifications/{id}/read`, `/me/notifications/read-all` · DELETE `/me/notifications/{id}` | |
| GET/PUT | `/me/notification-preferences` | `{ preferences: { general: { mail: false } } }` — security/account are mandatory |
| GET | `/notification-templates`, `/notification-templates/{key}` [communication.templates.manage] | default + custom per channel, allowed `placeholders` |
| PUT/DELETE | `/notification-templates/{key}/{channel}` | customise / reset; placeholders `{{ name }}` |
| GET | `/notification-deliveries` [communication.deliveries.view] | email delivery log |

## Audit
- `GET /audit/activities` [admin.audit.view] — record changes with before/after (`changes.old`, `changes.attributes`), causer, IP, `trace_id`. Filters: `log, event, causer, subject_type, subject_id, from, to`.
- `GET /audit/activities/export` [admin.audit.export] — CSV, max 50k rows.
- `GET /audit/security-events` [identity.security_events.view] — sign-ins, lockouts, role changes, settings changes…

## Health
- `GET /health` (public) → `{ "status": "ok" }` or 503.
- `GET /admin/health` [admin.monitoring.view] — database, cache, storage, queue, scheduler.

## Tracing
Send `X-Trace-Id` (8–64 chars `[A-Za-z0-9-]`) from the frontend to correlate logs; every response returns `X-Trace-Id` and errors include `trace_id`.
