<?php

namespace App\Support;

use App\Models\Offer;
use Illuminate\Support\Facades\Log;

/**
 * Looks up the share an Offer allocates to a subject.
 *
 * WHY THIS EXISTS
 * ---------------
 * `offers.percentage` is a JSON object keyed by subject name, e.g. {"Math": 60}. Five
 * places in the codebase read it as `$offer->percentage[$subject] ?? 0`, and PHP array
 * keys are CASE SENSITIVE — so a membership storing the subject as "math" misses a
 * percentage recorded under "Math" entirely and silently resolves to 0%.
 *
 * That is not hypothetical. Found in real data: membership 222 on offer "2 BAC MATH",
 * percentages {"Math": 60}, teacher assigned to subject "math". The teacher was set to
 * earn 0% of that student's payments while the offer plainly says 60%.
 *
 * Subject names are typed by humans into two different forms (the offer editor and the
 * membership editor). Treating "math", "Math" and " Math " as different subjects is a
 * data-entry trap, not a feature.
 *
 * Every percentage lookup goes through here so the payment path and the reporting paths
 * can never disagree about what a teacher is owed.
 */
class OfferPercentages
{
    /**
     * The percentage this offer allocates to $subject, or NULL when the offer genuinely
     * says nothing about it.
     *
     * Null and 0.0 are deliberately different answers: "not mentioned" triggers the
     * equal-distribution fallback in TeacherMembershipPaymentService, while an explicit
     * 0 means the offer really does allocate nothing.
     */
    public static function forSubject(?Offer $offer, ?string $subject): ?float
    {
        if (! $offer || ! $subject || ! is_array($offer->percentage)) {
            return null;
        }

        $percentages = $offer->percentage;

        // Exact match always wins, so existing correctly-spelled data behaves identically.
        if (array_key_exists($subject, $percentages)) {
            return (float) $percentages[$subject];
        }

        $needle = self::normalise($subject);

        if ($needle === '') {
            return null;
        }

        $matches = [];
        foreach ($percentages as $key => $value) {
            if (self::normalise((string) $key) === $needle) {
                $matches[(string) $key] = (float) $value;
            }
        }

        if ($matches === []) {
            return null;
        }

        if (count($matches) === 1) {
            return (float) reset($matches);
        }

        // The offer lists the same subject twice under different spellings with different
        // values ({"Math": 60, "math": 40}). There is no correct answer, so pick
        // deterministically and say so loudly rather than letting the result depend on
        // JSON key order.
        ksort($matches);
        $chosen = (float) reset($matches);

        Log::warning('Offer lists one subject under several spellings with different percentages', [
            'offer_id' => $offer->id,
            'subject_requested' => $subject,
            'candidates' => $matches,
            'chosen' => $chosen,
        ]);

        return $chosen;
    }

    /** Trimmed, lowercased, internal whitespace collapsed. */
    public static function normalise(string $subject): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $subject)));
    }
}
