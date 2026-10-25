# CampusOS API Documentation

The backend exposes a versioned REST API consumed by the separate frontend repository.

- Base URL: `{APP_URL}/api/v1`
- Format: JSON (`Accept: application/json`)
- Auth: Laravel Sanctum — SPA cookie auth for the first-party frontend, bearer tokens for other clients

## Contents
| Path | Purpose |
|------|---------|
| [conventions.md](conventions.md) | Request/response envelope, errors, pagination, filtering, auth, versioning rules |
| `openapi.json` | Generated OpenAPI spec (added once the API skeleton exists) |
| `modules/` | One file per module (`M01-identity.md`, …) with endpoint notes, flows and examples for frontend developers |

## Rules for agents
1. Any new or changed endpoint must be documented in `modules/<module>.md` in the same change.
2. Regenerate `openapi.json` when routes, requests or resources change.
3. Breaking changes go to a new version prefix (`/api/v2`) and need a `breaking` changelog fragment.
