Remove SMS support to keep CampusOS free of external services.

- API: two-step verification is authenticator app or email code. `POST /me/mfa/code-method[/confirm]` is replaced by `POST /me/mfa/email` and `POST /me/mfa/email/confirm`; `method: "sms"` is no longer accepted.
- DB: `one_time_codes.channel` column removed.
- Config: `CAMPUSOS_SMS_DRIVER` and `CAMPUSOS_SMS_SENDER_ID` removed. Notifications are in-app and email only.
