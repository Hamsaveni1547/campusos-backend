# M14 Leave and M15 Library — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`.

## Leave (staff)

Days are working days: Sundays and calendar holidays (`is_holiday`) are not counted. Paid types need a balance; unpaid types become loss of pay when a payroll month is created.

| Role | Permissions |
|---|---|
| Institution admin, HR officer | `leave.*` |
| Institution head, HOD | approve, reports |
| Staff | own balances and applications |

- `GET leave-types`, `POST`, `PATCH leave-types/{id}` `{code, name, days_per_year, is_paid, allow_half_day, status?}`.
- `POST leave-balances/allot` `{year?}` → every working employee gets each paid type's days (existing kept). `GET|PUT employees/{id}/leave-balances` `{leave_type_id, year, allotted}`.
- `GET me/leave` → `balances[] {type, allotted, used, pending, available}`, `applications[]` (`can_cancel`).
- `POST me/leave-applications` `{leave_type_id, from_date, to_date, half_day?, reason}` — `INSUFFICIENT_BALANCE`, `LEAVE_OVERLAP`, `HALF_DAY_INVALID`, `NO_WORKING_DAYS`. `POST me/leave-applications/{id}/cancel` (pending, or approved and not started).
- `GET leave-applications?filter[status]=&filter[from]=&filter[to]=&filter[department]=` (`can_decide`); `POST leave-applications/{id}/decide` `{approve, remarks?}` — not your own.

## Library

Settings group `library`: `student_limit` 4, `staff_limit` 8, `student_days` 14, `staff_days` 30, `max_renewals` 2, `fine_per_day` 2.

| Role | Permissions |
|---|---|
| Institution admin, Librarian | `library.*` |
| Institution head | reports |
| Everyone signed in | catalogue search, own loans, renew |

- `GET library/books?filter[search]=&filter[subject]=` (title, author, ISBN or accession no.) → `copies`, `available`; `GET library/books/{id}` → `copy_list[]` with `due_on`.
- `POST library/books` `{title, authors, isbn?, publisher?, edition?, published_year?, subject?, call_number?, copies?[] {accession_no, shelf?}}`, `PATCH library/books/{id}`, `POST library/books/{id}/copies`, `PATCH library/copies/{id}` `{status: available|lost|withdrawn, shelf?}`.
- `GET library/member?member=` (email, register no. or employee code) → member, `dues`, `current[]`, `history[]`.
- `POST library/issue` `{accession_no, member}` — `COPY_UNAVAILABLE`, `LIMIT_REACHED`, `FINE_PENDING`, `MEMBER_NOT_FOUND`. `POST library/return` `{accession_no}` → `fine`.
- `POST library/loans/{id}/renew` (the member or desk; `OVERDUE`, `RENEWAL_LIMIT`), `POST library/loans/{id}/fine` `{how: paid|waived, note?}`.
- `GET library/loans?filter[state]=active|overdue|fines&filter[search]=`, `GET library/summary`, `GET users/{id}/library-dues`, `GET me/library`.
