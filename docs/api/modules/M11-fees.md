# M11 Fees — API notes for the frontend

All routes are under `/api/v1` with a bearer token and `X-Institution`. No payment gateway: payments are recorded at the counter. `GET finance/options` → `modes[]` (cash, cheque, dd, neft, upi, card), `demand_statuses[]`, `quotas[]`, `heads[]`.

Settings group `finance`: `receipt_no_format` (default `{INST}/RCPT/{YYYY}/{SEQ:6}`).

## Who can do what

| Role | Permissions |
|---|---|
| Institution admin, Finance officer | `finance.*` |
| Institution head | reports, approve concessions |
| Admin staff | collect payments, reports |
| Student | own fees and receipts |

## Setup

- `GET|POST fee-heads`, `PATCH fee-heads/{id}` `{code, name, description?, status?}`.
- `GET fee-structures?filter[programme]=`, `POST`, `PATCH fee-structures/{id}` `{name, programme_id, admission_year?, quota?, term_number?, due_on, items[] {fee_head_id, amount}}` → `total`, `demands` count.
- `POST fee-structures/{id}/generate` `{term_id?}` → `raised`: one demand per matching enrolled student (programme, and admission year, quota and current semester when set); students who already have it are skipped.

## Demands and concessions

- `GET fee-demands?filter[student]=&filter[status]=&filter[structure]=&filter[overdue]=1&filter[search]=` → `{amount, concession, paid, balance, status, overdue}`. Balance = amount − approved concession − paid.
- `POST fee-demands` `{student_id, title, due_on, items[]}` (one-off charge); `POST fee-demands/{id}/cancel` `{reason}` (not once paid: `DEMAND_PAID`).
- `POST fee-demands/{id}/concessions` `{amount, reason}` (≤ balance, one pending at a time); `GET fee-concessions?filter[status]=` (`can_decide`); `POST fee-concessions/{id}/decide` `{approve, remarks?}` — not by the requester (`SAME_PERSON`).

## Payments

- `POST fee-payments` `{student_id, amount, mode, reference?, paid_on, demand_ids?[]}` — applied to the earliest dues first; more than is due → `OVERPAYMENT`; non-cash needs `reference`. Returns the receipt: `receipt_no`, `paid_for[]`, `balance_after`.
- `GET fee-payments?filter[student]=&filter[mode]=&filter[status]=&filter[from]=&filter[to]=`, `GET fee-payments/{id}` (staff or the student who paid).
- `POST fee-payments/{id}/cancel` `{reason}` — the receipt stays (cancelled) and its amounts come off the demands.

## Accounts and reports

- `GET students/{id}/fee-account` and `GET me/fees` → `totals {billed, concession, paid, balance, overdue, no_dues}`, `demands[]` with `items[]`, `payments[]`.
- `GET students/{id}/no-dues` → `{no_dues, balance}`.
- `GET finance/reports/collections?from=&to=` → `total`, `receipts`, `by_mode`, `by_day`.
- `GET finance/reports/dues?filter[programme]=&filter[overdue]=1` → students with dues, largest first.
