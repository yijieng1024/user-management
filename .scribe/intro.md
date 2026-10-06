# Introduction

REST API for the admin-only User Management system: JWT authentication and user management (list, create, show, update, soft delete, bulk delete).

<aside>
    <strong>Base URL</strong>: <code>http://localhost:8000</code>
</aside>

This documentation is public, but calling the API requires an authenticated admin. Only admins whose status is `active` can get a token, and every request checks again that the user is still an active admin.

## Getting a token

1. Call `POST /api/login` with the admin's `email` and `password`. The response contains an `access_token`.
2. Send it on every other request in the `Authorization` header: `Bearer {access_token}`.
3. The token is valid for **60 minutes**. Before or after it expires, call `POST /api/refresh` with it to get a new one. A token can be refreshed for up to **1 day** after it was first issued; after that, log in again.
4. `POST /api/logout` blacklists the token so it can no longer be used.

## Rate limits

- `POST /api/login`: 5 requests per minute per email + IP address (shared with the web login form).
- All other endpoints: 60 requests per minute per user.

## Downloads

- [OpenAPI spec (Swagger-compatible)](/docs.openapi)
- [Postman collection](/docs.postman)

<aside>Example requests are shown in the dark area on the right. Use <b>Try It Out</b> on any endpoint to send a real request: log in first, then paste the token into the auth field.</aside>

