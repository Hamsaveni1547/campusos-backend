# M10 Question bank & secure papers — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`. `GET qbank/options` → `types[]` (mcq, short, long, numerical), `difficulties[]`, `bloom_levels[]`, `paper_statuses[]` (draft, submitted, approved, sealed), `release_minutes_before`.

## Security

- Question bodies (text, options, answer) are encrypted at rest with AES-256-GCM (Laravel encrypter, no extra library). The master key is `CAMPUSOS_QBANK_KEY` (base64, 32 bytes) or, when empty, derived from `APP_KEY`. Changing it makes existing content unreadable.
- Sealing a paper encrypts its full content with a new random data key; the data key is wrapped with the master key and a SHA-256 digest detects tampering (`CONTENT_TAMPERED`).
- Every view, seal, release and refused release is logged with user and IP.

## Who can do what

| Role | Permissions |
|---|---|
| Institution admin, Examiner | `qbank.*` |
| Head of department | contribute and manage questions, set and review papers |
| Faculty | contribute questions (edit own), set papers |
| Institution head | access logs |

## Questions

- `GET courses/{course}/questions?filter[type]=&filter[unit]=&filter[difficulty]=&filter[status]=` (default active) → `{id, unit, type, marks, co_code, bloom_level, difficulty, status, text, options, answer, can_edit}`.
- `POST courses/{course}/questions`, `PATCH questions/{id}` `{unit?, type, marks, co_code?, bloom_level?, difficulty, text, options?[], answer?}` — `DUPLICATE_QUESTION` (same text, ignoring case and spacing), `OPTIONS_REQUIRED` (MCQ needs 2+), `QUESTION_IN_USE` (text or marks of a question in a sealed paper).
- `POST questions/{id}/retire` `{retired}`; `GET courses/{course}/question-coverage` → counts `by_unit`, `by_type`, `by_bloom`.

## Blueprints and generation

- `GET|POST courses/{course}/paper-blueprints`, `PATCH|DELETE paper-blueprints/{id}` `{name, duration_minutes, avoid_recent_papers, sections[] {name, type, marks, count, attempt?, units?[], bloom_levels?[], difficulty?}}` → adds `total_marks` (attempted × marks).
- `GET courses/{course}/upcoming-exams` → exams of unpublished cycles `{id, cycle, date, starts_at}` to link papers to.
- `POST paper-blueprints/{id}/generate` `{sets 1–4, exam_schedule_id?}` → sets A, B…; questions from the last N approved/sealed papers and other sets are used only when nothing else fits. `NOT_ENOUGH_QUESTIONS` (`context.section`).

## Papers

`draft → submitted → approved → sealed`

- `GET question-papers?filter[course]=&filter[status]=`, `GET question-papers/{id}` (metadata, `exam`, `release_window`, `can {view, submit, review, seal, delete, logs}`), `DELETE` (drafts).
- `POST question-papers/{id}/submit`, `…/review` `{approve, remarks?}` (not the setter: `SAME_PERSON`; returning needs remarks), `…/seal`.
- `GET question-papers/{id}/content` — before sealing: setters/reviewers, with answers. Sealed: `qbank.papers.release` only, from `release_minutes_before` minutes before the linked exam until it ends, without answers (`OUTSIDE_RELEASE_WINDOW`).
- `GET question-papers/{id}/access-logs` → `{action, user, ip, at}`.
