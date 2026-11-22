# Privacy

Everything is sanitized **before** it leaves the application.

| Data | Default | Option |
|---|---|---|
| Request body | not sent | `privacy.send_request_body` |
| Headers | sent, `Authorization`/`Cookie`/sensitive names redacted | `privacy.send_headers` |
| Cookies | not sent | `privacy.send_cookies` |
| Client IP | not sent | `privacy.send_ip` |
| Authenticated user | id only | `privacy.send_user`, `privacy.send_user_email` |
| Source code around in-app frames | sent | `privacy.send_source_context` |
| Function arguments in stack traces | never sent | — |

Redaction is recursive over request data, query strings, contexts, tags, breadcrumbs and user data.
A key is redacted when its normalized name (case-insensitive, ignoring `-`, `_`, `.`, spaces) contains
any entry of `privacy.sensitive_fields` (e.g. `password`, `token`, `api_key`, `secret`, `authorization`,
`cookie`, `credit_card`). `privacy.allowed_fields` exempts specific keys. Bearer/basic credentials and
Luhn-valid card numbers inside string values are redacted too.
