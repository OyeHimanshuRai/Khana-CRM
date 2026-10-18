# Restro One Partner app - API

The staff app (captain and owner) talks to CRM at `https://<domain>/api/v1`.
Every call after sign-in sends `Authorization: Bearer <token>` and
`Accept: application/json`.

## Signing in

1. The app runs Accounts' sign-in with PKCE (`flutter_appauth`):
   - authorize: `https://<domain>/accounts/oauth/authorize`
   - token: `https://<domain>/accounts/oauth/token`
   - client id: printed by `php artisan khana:clients` on Accounts (section "PARTNER APP")
   - redirect: `in.restroone.partner://oauth/callback`
   - no client secret, no scopes
2. `POST /auth/exchange` with `{ "access_token": "<Accounts token>", "device_name": "Ravi's phone" }`
   returns `{ token, token_type, expires_at, abilities, user, shop }`. The token lasts 30 days.
3. `POST /auth/logout` ends this phone's token only.

On an install with no Accounts (a developer's machine), `POST /auth/login` with
`{ email, password, device_name }` gives the same answer instead.

`abilities` comes from the person's role; show only the screens they allow.

## Endpoints

| Method | Path | Ability | What |
|---|---|---|---|
| GET | `/me` | any | who the token belongs to |
| GET | `/outlets` | any | outlets the person works at, `current` marked |
| PUT | `/outlet` | any | switch outlet: `{ "shop_id": 2 }` |
| POST | `/devices` | any | save the FCM token: `{ "token": "...", "label": "..." }` |
| DELETE | `/devices` | any | stop notifications: `{ "token": "..." }` |
| GET | `/dashboard` | `dashboard:read` | home screen figures; a tile the role may not see is `null` |
| GET | `/tables` | `tables:read` | the floor, status and next booking |
| GET | `/tables/{id}` | `tables:read` | one table: session, lines ordered, unbilled, due |
| POST | `/tables/{id}/orders` | `orders:write` | captain's order: `{ "items": [{ "product_id": 5, "variant_id": null, "quantity": 2, "options": [], "note": "less spicy" }] }`; seats a free table, all or nothing |
| GET | `/menu`, `/menu/{id}` | `menu:read` | the menu with sizes and add-ons |
| PUT | `/menu/{id}/availability` | `menu:write` | mark sold out |
| GET | `/orders`, `/orders/{id}` | `orders:read` | orders and their status |
| POST | `/orders` | `orders:write` | place an order (prices come from the menu) |
| PUT | `/orders/{id}/status` | `orders:write` | move an order along |

## Errors

- `401` no or expired token: sign in again.
- `403` with a `message`: the role or the outlet's plan does not allow it; show the message.
- `422` validation: `errors` per field.
- `429` too many calls: slow down (60 a minute per token, sign-in 10 a minute).

## Notes

- The current outlet is shared with the web back office: switching in the app switches it there too.
- Push notifications are stored now; sending them needs a Firebase project (later).
