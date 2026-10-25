# M01 — Identity & Access (API notes for the frontend)

Base: `/api/v1`. All responses use the envelope in [conventions.md](../conventions.md). Full request/response schemas: [openapi.json](../openapi.json).

## Authentication model
- **Bearer tokens** (Laravel Sanctum). The frontend (a Laravel app) signs in server-side and keeps the token in its own session; it sends `Authorization: Bearer <token>` on every call.
- One token = one signed-in device. Tokens expire after `security.session_lifetime_minutes` (default 12 h) and after `security.idle_timeout_minutes` of inactivity (default 2 h). On `401 UNAUTHENTICATED` send the user to sign in again.
- Tokens look like `12|cos_xxxxxxxx…`.

## Sign-in flow
```
POST /auth/login { login, password, device_name, trusted_device_token? }
 ├─ 200 { data: { mfa_required: false, access_token, expires_at, user: <Me> } }
 └─ 200 { data: { mfa_required: true, challenge_token, challenge_expires_at, methods: ["totp","email"] } }
        ├─ (email) POST /auth/mfa/send-code { challenge_token, method: "email" }
        └─ POST /auth/mfa/verify { challenge_token, method: totp|email|recovery_code, code, remember_device? }
              → 200 same as successful login (+ trusted_device_token when remember_device=true)
```
- `login` accepts email, username or mobile.
- Store `trusted_device_token` (e.g. long-lived cookie on the frontend) and send it on the next login to skip MFA while it is valid.
- Error codes: `INVALID_CREDENTIALS` (422), `ACCOUNT_LOCKED` (423, `context.retry_after_minutes`), `ACCOUNT_INACTIVE` / `ACCOUNT_NOT_SET_UP` / `INSTITUTION_SUSPENDED` / `NO_INSTITUTION` (403), `MFA_INVALID_CODE` (422, `context.attempts_remaining`), `MFA_CHALLENGE_EXHAUSTED`, `MFA_CHALLENGE_EXPIRED`, `OTP_RESEND_TOO_SOON` (429, `context.retry_after_seconds`).

## Pending account requirements
After sign-in, check `user.pending_requirement`. While it is set, most endpoints answer `403` with the same code:

| Code | What the user must do | Endpoints still allowed |
|------|-----------------------|-------------------------|
| `EMAIL_VERIFICATION_REQUIRED` | Enter the emailed code | `POST /auth/verify-email`, `POST /auth/verify-email/resend`, `GET /me` |
| `PASSWORD_CHANGE_REQUIRED` | Set a new password | `PUT /me/password`, `GET /me` |
| `MFA_ENROLLMENT_REQUIRED` | Set up two-step verification | `/me/mfa/*`, `GET /me` |

## Other public endpoints
| Method | Path | Purpose |
|--------|------|---------|
| POST | `/auth/register` | Applicant self-registration (`institution` code, name, email, mobile, password, password_confirmation, accept_terms) |
| POST | `/auth/forgot-password` | Always 200 (does not reveal accounts) |
| POST | `/auth/reset-password` | `email, token, password, password_confirmation` (token from the email link `/reset-password?token=&email=`) |
| POST | `/auth/setup-account` | Same body; for invitation links `/setup-account?token=&email=` |
| POST | `/auth/logout` | Revokes the current token |

## `GET /me` (Me object)
`id, name, email, mobile, username, status, is_platform_admin, email_verified, mobile_verified, locale, timezone, avatar_file_id, mfa {enabled, required, methods[]}, pending_requirement, institution {…}, institutions[], roles[], permissions[]`.
Use `permissions` to show/hide UI; the API enforces them anyway.

## My account
| Method | Path | Notes |
|--------|------|-------|
| PUT | `/me` | name, locale, timezone, avatar_file_id (upload with purpose `avatar` first) |
| PUT | `/me/password` | current_password, password, password_confirmation; signs out other devices |
| GET | `/me/institutions` · PUT `/me/default-institution` | Multi-institution users switch with header `X-Institution: <id or code>` |
| GET/DELETE | `/me/sessions`, `/me/sessions/{id}`, `DELETE /me/sessions` (all others) | Signed-in devices |
| GET/DELETE | `/me/trusted-devices`, `/me/trusted-devices/{id}` | Devices that skip MFA |
| GET | `/me/security-events` | Own sign-in history (cursor pagination) |

## Two-step verification (`/me/mfa`)
| Method | Path | Body |
|--------|------|------|
| GET | `/me/mfa` | → enabled, required, recovery_codes_remaining, methods[] |
| POST | `/me/mfa/totp` | current_password → secret, otpauth_uri, qr_svg (show the SVG) |
| POST | `/me/mfa/totp/confirm` | code → method, recovery_codes (show once, ask user to save) |
| POST | `/me/mfa/email` | current_password → sent_to |\|sms, mobile (sms), current_password → sent_to |
| POST | `/me/mfa/email/confirm` | code |
| POST | `/me/mfa/{id}/primary` | — |
| DELETE | `/me/mfa/{id}` | current_password (blocked with `MFA_REQUIRED_BY_ROLE` for the last method) |
| POST | `/me/mfa/recovery-codes` | current_password → new codes |

## Users (institution admin) — permission in brackets
| Method | Path | |
|--------|------|-|
| GET | `/users` [identity.users.view] | `filter[search]`, `filter[status]`, `filter[account_status]`, `filter[role]`, `filter[locked]`, `sort=name\|-created_at\|last_login_at`, `page`, `per_page` |
| POST | `/users` [identity.users.create] | name, email, mobile, username, roles[] (role ids), send_invite (default true), password. `meta.temporary_password` is returned once when `send_invite=false`. Existing people (same email) are linked, `meta.linked_existing_account=true` |
| GET/PATCH | `/users/{id}` | |
| POST | `/users/{id}/deactivate` (reason) · `/activate` [identity.users.deactivate] | Per-institution membership |
| POST | `/users/{id}/unlock` [identity.users.unlock] | |
| POST | `/users/{id}/reset-password` (mode link\|temporary_password) · `/reset-mfa` [identity.users.reset_password] | |
| POST | `/users/{id}/resend-invitation` | |
| GET | `/users/{id}/security-events` [identity.security_events.view] | |
| POST | `/users/{id}/role-assignments` [identity.role_assignments.manage] | role_id, department_id, valid_from, valid_until, reason |

## Roles & permissions
| Method | Path | |
|--------|------|-|
| GET | `/roles`, `/roles/{id}` | 12 built-in roles (read-only) + custom roles |
| POST/PATCH/DELETE | `/roles`, `/roles/{id}` [identity.roles.manage] | display_name, name, description, is_department_scoped, is_active, permissions[] |
| GET | `/permissions` | Grouped by module, with `is_sensitive` |
| GET | `/role-assignments` | `filter[role]`, `filter[user]`, `filter[status]=active\|revoked\|expired\|scheduled` |
| DELETE | `/role-assignments/{id}` | reason |

Escalation guard: you can only grant/revoke roles and permissions you hold yourself (`PRIVILEGE_ESCALATION`, `context.missing_permissions`), and never change your own roles (`SELF_ROLE_CHANGE`).

## Imports
Upload the CSV with `POST /files` (`purpose=import`), then `POST /imports { type: "users", file_id, options: { send_invite } }` → 202. Poll `GET /imports/{id}` until `validated` (or `invalid` with `errors[{row, errors[]}]`), then `POST /imports/{id}/commit`. Columns: `GET /imports/templates/users`.
