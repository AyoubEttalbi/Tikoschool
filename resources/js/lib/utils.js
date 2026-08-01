import { clsx } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs) {
    return twMerge(clsx(inputs));
}

/**
 * Laravel's paginator emits labels containing HTML entities ("&laquo; Previous").
 * Decode them to plain text so they can be rendered as children instead of via
 * dangerouslySetInnerHTML — markup in a paginator label is never wanted, and
 * keeping the codebase free of dangerouslySetInnerHTML makes XSS review trivial.
 */
export function decodePaginationLabel(label) {
    if (typeof label !== "string") return "";
    return label
        .replace(/&laquo;/g, "«")
        .replace(/&raquo;/g, "»")
        .replace(/&nbsp;/g, " ")
        .replace(/&amp;/g, "&")
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">")
        .replace(/&quot;/g, '"')
        .replace(/&#0?39;/g, "'")
        .replace(/<[^>]*>/g, "")
        .trim();
}
