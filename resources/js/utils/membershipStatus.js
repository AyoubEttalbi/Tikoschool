/**
 * Single home of the membership display rule (frontend mirror of
 * StudentsController::calculateMembershipPaymentStatus + its SQL predicates).
 *
 * Status first: 'paid' reads paid ONLY with live money behind it (a paid row
 * whose invoices were all voided outside the writers reads unpaid). Expired
 * always reads unpaid. Pending otherwise falls back to money — including
 * lapsed fully-paid rows, which read unpaid: no current cover, no paid badge.
 *
 * Tri-state ("paid" | "not_paid" | "not_fully_paid") for counters and banners;
 * use isPaid() for booleans (row tints, renewal eligibility).
 */

export const getMembershipPaymentStatus = (membership) => {
    const invoices = membership?.invoices || [];
    const paidSum = invoices.reduce((sum, invoice) => sum + (parseFloat(invoice?.amountPaid) || 0), 0);

    if (membership?.payment_status === "paid" && paidSum > 0) return "paid";
    if (membership?.payment_status === "expired") return "not_paid";
    if (invoices.length === 0) return "not_paid";

    const totalAmount = invoices.reduce((sum, invoice) => sum + (parseFloat(invoice?.totalAmount) || 0), 0);

    if (paidSum === 0) return "not_paid";
    if (paidSum < totalAmount) return "not_fully_paid";

    // Fully-paid money on a non-paid status = lapsed coverage: unpaid.
    return "not_paid";
};

export const isPaid = (membership) => getMembershipPaymentStatus(membership) === "paid";
