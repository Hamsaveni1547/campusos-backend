# changelog.d — changelog fragments

Every change that affects behaviour, the API, the database or configuration adds **one fragment file** here in the same commit/PR. Fragments are compiled into `CHANGELOG.md` at release time and then deleted.

## File name
`YYYYMMDD-<module>-<short-slug>.<type>.md`

- `module`: `core`, `m01-identity`, `m02-institution`, … `m22-sysadmin` (see `_claude/plans/00-index.md`)
- `type`: one of
  - `added` — new feature or endpoint
  - `changed` — behaviour change to existing feature
  - `deprecated` — still works, will be removed
  - `removed` — removed feature/endpoint
  - `fixed` — bug fix
  - `security` — security fix or hardening
  - `breaking` — breaking API change (requires a new API version or a migration note)

Example: `20261101-m01-identity-login-mfa.added.md`

## Content
```markdown
Add MFA login flow with TOTP and email OTP.

- API: `POST /api/v1/auth/login`, `POST /api/v1/auth/mfa/verify`
- DB: new tables `mfa_methods`, `otp_challenges`
- Config: `MFA_OTP_TTL` env var
```
One short summary line, then optional bullets for **API**, **DB**, **Config**, **Notes for frontend**.

## Rules for agents
- Never edit `CHANGELOG.md` directly between releases; add a fragment.
- If an endpoint changes, also update `docs/api/`.
- Do not mention AI tooling or add attribution trailers.
