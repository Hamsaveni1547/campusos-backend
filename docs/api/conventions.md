# API Conventions (v1)

> Status: proposed — finalised when the API skeleton is built.

## Versioning
- All routes are under `/api/v1`. Additive changes stay in v1; breaking changes create `/api/v2`.
- Deprecated endpoints return `Deprecation` and `Sunset` headers.

## Resources & URLs
- Plural kebab-case nouns: `/api/v1/exam-registrations`.
- Public IDs are ULIDs: `/api/v1/students/01JBX6Q3K8...`.
- Non-CRUD actions are POST sub-resources: `/api/v1/timetables/{id}/publish`.
- Self-service endpoints use `/me`: `/api/v1/me/attendance`.

## Successful responses
```json
{ "data": { "id": "01JB...", "type": "student", "...": "..." } }
{ "data": [ ... ], "meta": { "current_page": 1, "per_page": 25, "total": 240 }, "links": { "next": "..." } }
```

## Errors
```json
{ "message": "The given data was invalid.", "code": "VALIDATION_FAILED",
  "errors": { "email": ["The email field is required."] }, "trace_id": "9f1c..." }
```
| Status | Meaning |
|--------|---------|
| 401 | Not authenticated |
| 403 | Authenticated but not allowed (permission, scope or MFA pending: `code: MFA_REQUIRED`) |
| 404 | Not found **or outside your institution/scope** |
| 409 | Conflict (stale version, invalid state transition) |
| 422 | Validation failed |
| 429 | Rate limited |

## Lists
- Pagination: `?page=2&per_page=50` (max 100). Feeds/logs use cursor pagination.
- Filters: `?filter[status]=active&filter[department]=01JB...`
- Sorting: `?sort=-created_at,name`
- Includes: `?include=programme,section` (whitelisted per endpoint)

## Headers
- `Accept: application/json` (required)
- `X-Institution: <ulid>` — only for users belonging to several institutions
- `Idempotency-Key: <uuid>` — required on payments, refunds, result publishing
- Response `X-Trace-Id` for support/debugging

## Auth flow (summary)
1. `GET /sanctum/csrf-cookie` (SPA)
2. `POST /api/v1/auth/login` → `{ "mfa_required": true, "challenge_id": "...", "methods": ["totp","email"] }` or session established
3. `POST /api/v1/auth/mfa/verify`
4. `GET /api/v1/me`

## Dates, money, enums
- Timestamps ISO-8601 with offset in the institution timezone; dates `YYYY-MM-DD`.
- Money as string decimals with currency: `{ "amount": "45000.00", "currency": "INR" }`.
- Enums as lowercase snake_case strings, each documented per module.
