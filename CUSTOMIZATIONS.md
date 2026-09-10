# Client Customizations — Tikoschool

This file is **tracked** — commit per-deployment deltas. Keep `CLAUDE.md` generic — see pointer there.

| Feature | Tikoschool | Centre Red City v2 | Flag | Code |
|---|---|---|---|---|
| Partial-month rounded to nearest 5 DH (no coin change) | Yes — `InvoicePricingService::partialMonthAmount` + `InvoicesFrom.jsx` preview | No (exact DH) | — | `tests/Feature/InvoicePricingTest.php` § rounding |

## Notes
_None yet — single-tenant strings still via `config/school.php` + `SCHOOL_*` env. See `docs/WHATSAPP_NOTIFICATIONS.md`._
