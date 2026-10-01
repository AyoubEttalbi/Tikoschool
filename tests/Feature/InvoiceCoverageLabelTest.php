<?php

use App\Models\Invoice;
use App\Support\InvoiceCoverage;

/*
 * THE "DATE DE FACTURATION" CELL SHOWS COVERAGE, NOT THE BILLING EVENT.
 *
 * An invoice billed September 30 for October must read 2026-10, not 2026-09.
 * Single month → that month; consecutive multi → range; gapped/legacy →
 * list; no months (partial-only, legacy) → billDate month; nothing at all →
 * em dash. Pure text tokens: identical on every device and timezone.
 */

function coverageInvoice(array $overrides = []): Invoice
{
    return Invoice::factory()->create(array_merge([
        'selected_months' => ['2026-10'],
        'billDate' => '2026-09-30',
    ], $overrides));
}

test('a single selected month labels that month, not the bill date', function () {
    expect(InvoiceCoverage::label(coverageInvoice()))->toBe('2026-10');
});

test('consecutive months label as a range', function () {
    $invoice = coverageInvoice(['selected_months' => ['2026-09', '2026-10', '2026-11']]);

    expect(InvoiceCoverage::label($invoice))->toBe('2026-09 → 2026-11');
});

test('gapped months label as a list', function () {
    $invoice = coverageInvoice(['selected_months' => ['2026-09', '2026-12']]);

    expect(InvoiceCoverage::label($invoice))->toBe('2026-09, 2026-12');
});

test('no selected months falls back to the bill date month', function () {
    $invoice = coverageInvoice(['selected_months' => [], 'billDate' => '2026-09-15']);

    expect(InvoiceCoverage::label($invoice))->toBe('2026-09');
});

test('garbage months are ignored, valid ones kept', function () {
    $invoice = coverageInvoice(['selected_months' => ['2026-10', 'garbage', ' 2026-11 ']]);

    expect(InvoiceCoverage::label($invoice))->toBe('2026-10 → 2026-11');
});
