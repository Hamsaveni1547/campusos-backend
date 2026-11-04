# M16 Labs & assets and M17 Feedback & complaints — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`.

## Labs & assets

| Role | Permissions |
|---|---|
| Institution admin | `assets.*` |
| Admin staff | manage register and maintenance, book rooms, reports |
| HOD | reports, book rooms |
| Faculty | book rooms |
| Any staff | report faults |

- `GET assets?filter[search]=&filter[category]=&filter[status]=&filter[room]=&filter[warranty]=1`, `GET assets/summary`, `GET assets/{id}` (with `requests[]`), `POST`, `PATCH assets/{id}` `{tag, name, category, department_id?, room_id?, make?, model?, serial_no?, purchased_on?, cost?, vendor?, warranty_until?, status?}`.
- `GET maintenance-requests?filter[status]=` (others see only their own), `POST maintenance-requests` `{title, description?, priority, asset? (tag), room_id?}`, `PATCH maintenance-requests/{id}` `{status: in_progress|resolved|closed, assigned_to?, resolution?, cost?}` — the asset goes under repair while in progress and back to working when resolved.
- `GET room-bookings?filter[room]=&filter[mine]=1`, `POST room-bookings` `{room_id, date, starts_at, ends_at, purpose}` (`ROOM_BUSY` with another booking or a timetabled class), `POST room-bookings/{id}/cancel`.

## Feedback

Answers are stored without the person (`survey_responses` has no user and only the date); `survey_completions` records who has answered. Results are hidden below `feedback.min_responses` (default 5).

| Role | Permissions |
|---|---|
| Institution admin, Institution head | `feedback.*` |
| HOD | results, handle complaints |
| Admin staff | handle complaints |
| Faculty | own classes' results (`me/teaching-feedback`) |

- `GET|POST surveys`, `GET|PATCH surveys/{id}` `{title, description?, kind (course|general), term_id? (course), audience (students|staff|all), opens_on, closes_on, questions[] {text, type (rating|choice|text), options?[], required?}}` — draft only. `POST surveys/{id}/open|close`.
- `GET surveys/{id}/results` → `overall` and `classes[]` with `results {responses, hidden, minimum, questions[] {average, distribution, choices, comments}}`.
- `GET me/surveys` (pending; one per class for course feedback), `POST me/surveys/{id}/answers` `{class_id?, answers {question id: value}}` — `NOT_PENDING`, `ANSWER_REQUIRED`, `RATING_INVALID`, `CHOICE_INVALID`.

## Complaints

- `GET complaint-categories`, `POST|PATCH` `{name, sla_hours, is_restricted, status?}` (restricted permission).
- `GET complaints?filter[status]=&filter[category]=&filter[mine]=1&filter[overdue]=1` — restricted categories only with `feedback.complaints.restricted`; anonymous complaints hide `raised_by`.
- `GET complaints/{id}` (handler or raiser; raisers do not see internal notes), `POST complaints/{id}/assign` `{user_id?}`, `POST complaints/{id}/respond` `{body, internal?, resolve?}`.
- `GET|POST me/complaints` `{category_id, subject, description, anonymous?}`, `POST me/complaints/{id}/messages` (reopens a resolved complaint), `POST me/complaints/{id}/close` `{rating 1–5}` (resolved only).
