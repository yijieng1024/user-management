# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_ACCESS_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Get a token from <code>POST /api/login</code> (admin email and password), then send it as <code>Authorization: Bearer {token}</code>. Tokens last 60 minutes and can be refreshed with <code>POST /api/refresh</code> for up to 1 day.
