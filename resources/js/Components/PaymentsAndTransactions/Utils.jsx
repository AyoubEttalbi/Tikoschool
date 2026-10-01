import { format } from "date-fns";
import { parseDateOnly } from "@/utils/dateOnly";

/**
 * Money, always as "1 234,50 DH".
 *
 * The old body was `${amount.toLocaleString()} DH`, which threw a TypeError on null or
 * undefined — and blanked the whole table, because an exception during render takes the
 * component down, not just the cell. It also relied on the browser's default locale, and
 * `amount` arrives from Laravel as a STRING for every `decimal:2` column, so
 * String.prototype.toLocaleString ran instead and returned "1800.00" unformatted. Two
 * different renderings of the same figure depending on which column it came from.
 */
export const formatCurrency = (amount) => {
    const value = Number(amount);

    if (!Number.isFinite(value)) return "— DH";

    return `${value.toLocaleString("fr-FR", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })} DH`;
};

/**
 * DATE-only values only ("YYYY-MM-DD", optionally with a time suffix from a
 * DATE column). Renders the calendar date identically on every device.
 * Never pass created_at or any true instant here — those keep instant
 * semantics (see @/utils/dateOnly).
 */
export const formatDate = (dateString) => {
    if (!dateString) return "—";
    try {
        // DATE-only values: local construction, immune to device timezone
        // (see @/utils/dateOnly). True datetimes keep the legacy path.
        const local = parseDateOnly(dateString);
        return format(local ?? new Date(dateString), "dd MMM yyyy");
    } catch {
        return "—";
    }
};

export const getInitials = (name) => {
    if (!name) return "?";
    return name
        .split(" ")
        .map((n) => n[0])
        .join("")
        .toUpperCase();
};

export const getRoleBadgeColor = (role) => {
    switch (role?.toLowerCase()) {
        case "teacher":
            return "bg-purple-100 text-purple-800";
        case "admin":
            return "bg-red-100 text-red-800";
        case "staff":
            return "bg-orange-100 text-orange-800";
        default:
            return "bg-gray-100 text-gray-800";
    }
};

export const getTransactionTypeColor = (type) => {
    switch (type) {
        case "salary":
            return "bg-blue-100 text-blue-800";
        case "payment":
            return "bg-emerald-100 text-emerald-800";
        case "wallet":
            return "bg-violet-100 text-violet-800";
        case "expense":
            return "bg-orange-100 text-orange-800";
        default:
            return "bg-gray-100 text-gray-800";
    }
};

/**
 * The four types, in French.
 *
 * `payment` — every teacher payout in the system — had no case here, so it fell to the
 * default branch and rendered as the raw English column value, "Payment", on a screen
 * where every other label is French. `wallet` was mislabelled "Paiement", which is the
 * opposite of what it does: it ADDS money to a wallet rather than paying it out, so the
 * two movements that go in opposite directions read as the same word.
 */
export const getTransactionTypeLabel = (type) => {
    switch (type) {
        case "salary":
            return "Salaire";
        case "payment":
            return "Paiement";
        case "wallet":
            return "Ajout au portefeuille";
        case "expense":
            return "Dépense";
        default:
            return type ? type.charAt(0).toUpperCase() + type.slice(1) : "—";
    }
};

export const calculateChange = (current, previous) => {
    if (!previous) return { percentage: 0, direction: "neutral" };

    const change = ((current - previous) / previous) * 100;
    return {
        percentage: Math.abs(change).toFixed(1),
        direction: change > 0 ? "up" : change < 0 ? "down" : "neutral",
    };
};
