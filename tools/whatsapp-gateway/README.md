# WhatsApp gateway

Send-only WhatsApp gateway for parent notifications. Speaks the same HTTP contract as
Evolution API — `POST /message/sendText/{instance}` with an `apikey` header — so
`App\Support\WhatsApp` works against either without a code change. Swap by pointing
`EVOLUTION_API_URL` somewhere else.

## Why this and not Evolution API

Nothing stops you running Evolution API instead; the app cannot tell the difference. This
exists because Evolution needs Postgres and Docker, WAHA is Docker-only, and WPPConnect
runs a full Chromium per session — about 500 MB, which does not fit beside MySQL and PHP
on a 3.9 GB VPS. This uses Baileys directly: no browser, no database, ~150 MB.

The trade is maintenance. WhatsApp changes its protocol every few months. Evolution has a
community that reacts to that; this has you.

## Run it

```bash
cd tools/whatsapp-gateway
npm install
GATEWAY_API_KEY=<same value as EVOLUTION_API_KEY in .env> node gateway.mjs
```

Then open `http://127.0.0.1:8080/qr` (or the Notifications screen in the app) and scan
with the school phone: **WhatsApp → Linked devices → Link a device**.

| Env | Default | |
|---|---|---|
| `PORT` | `8080` | |
| `GATEWAY_API_KEY` | `local-dev-key` | must match `EVOLUTION_API_KEY` |
| `INSTANCE` | `tikoschool` | must match `EVOLUTION_INSTANCE` |
| `AUTH_DIR` | `./auth` | **mount this**, see below |
| `DRY_RUN` | off | accept and discard, for testing without a phone |

## Two things that will bite you

**`AUTH_DIR` must survive a restart.** It holds the linked-device credentials. Lose it and
someone has to physically scan a QR code again — which means the notifications are down
until a person with the school phone is standing at a screen. In Docker that means a named
volume, not a container path.

**It listens on loopback only, and that is deliberate.** This process can message every
parent in the school from the school's own number, and `/qr.json` hands out the credential
that links a new device to that account. Do not bind it to a public interface; reach it
from the app over localhost or a private compose network.

## Endpoints

| | |
|---|---|
| `GET /health` | `{state, instance, hasQr}` — no auth, no secrets |
| `GET /qr` | HTML page with the QR, self-refreshing |
| `GET /qr.json` | `{state, qr}` — **requires `apikey`**, used by the admin screen |
| `POST /message/sendText/{instance}` | `{number, text}` — requires `apikey` |

`state` is one of `connecting`, `qr` (waiting to be scanned), `open` (working), or
`logged_out` (the link was removed from the phone — delete `AUTH_DIR` and re-scan).

## What it does not do

Receiving, groups, media, webhooks, multiple numbers. The product sends absence notices to
guardians; every capability the gateway does not have is one that cannot be abused if the
key leaks. Delivery receipts would need webhooks — `outbound_messages.provider_message_id`
is stored ready for that, but nothing consumes it yet, so "sent" means the gateway accepted
it, not that a parent's phone showed it.
