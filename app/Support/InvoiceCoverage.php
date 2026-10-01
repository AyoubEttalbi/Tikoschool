<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * What the "Date de facturation" cell shows: the COVERED months, not the
 * billing-event date. An invoice billed September 30 for October reads
 * 2026-10 — billDate only answers "when was it written".
 *
 * Single month → that month; consecutive multi → "2026-09 → 2026-11";
 * gapped → comma list; no months (partial-only, legacy) → billDate month;
 * nothing parseable → em dash. Pure text tokens: identical on every device
 * and timezone. The JSX mirror is coverageLabel() in resources/js/utils/dateOnly.js —
 * keep the two in sync (InvoiceCoverageLabelTest pins this side).
 */
class InvoiceCoverage
{
    public static function label(Invoice $invoice): string
    {
        $list = self::months($invoice);

        if (count($list) === 0) {
            $bill = $invoice->billDate;
            $day = $bill instanceof \DateTimeInterface
                ? $bill->format('Y-m-d')
                : (is_string($bill) ? substr($bill, 0, 10) : '');
            if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])-\d{2}$/', $day, $m) === 1) {
                return $m[1].'-'.$m[2];
            }

            return '—';
        }

        if (count($list) === 1) {
            return $list[0];
        }

        $consecutive = true;
        for ($i = 1; $i < count($list); $i++) {
            [$prevY, $prevM] = array_map('intval', explode('-', $list[$i - 1]));
            [$currY, $currM] = array_map('intval', explode('-', $list[$i]));
            $expectedY = $prevM === 12 ? $prevY + 1 : $prevY;
            $expectedM = $prevM === 12 ? 1 : $prevM + 1;
            if ($currY !== $expectedY || $currM !== $expectedM) {
                $consecutive = false;
                break;
            }
        }

        return $consecutive
            ? $list[0].' → '.$list[count($list) - 1]
            : implode(', ', $list);
    }

    /** Coverage months of an invoice, normalised. Shared by the guard-adjacent readers. */
    public static function months(Invoice $invoice): array
    {
        $months = $invoice->selected_months;
        if (is_string($months)) {
            $months = json_decode($months, true) ?: [];
        }

        $list = [];
        if (is_array($months)) {
            foreach ($months as $token) {
                if (! is_string($token)) {
                    continue;
                }
                $token = trim($token);
                if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $token) === 1 && ! in_array($token, $list, true)) {
                    $list[] = $token;
                }
            }
            sort($list);
        }

        return $list;
    }
}
