<?php

namespace App\Models;

use App\Support\OfferPercentages;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_name',
        'price',
        'levelId',
        'subjects',
        'percentage',
    ];

    protected $casts = [
        'subjects' => 'array',
        'percentage' => 'array',
    ];

    /**
     * Offer percentages drive every teacher commission, live: TeacherEarnings
     * recomputes gains from the CURRENT row while wallets froze the creation-era
     * share. A silent % edit therefore moves every gains display with no invoice
     * touched and no trace — the Sept 2026 Zakaria investigation burned hours on
     * exactly that. Percentage changes are audit-logged with old/new values;
     * renames and price edits are not.
     */
    protected static function booted(): void
    {
        static::updating(function (Offer $offer) {
            // Compare on normalised keys (the lookup the payout path uses), but
            // log the original spellings — that is the audit trail.
            $normalise = fn (mixed $value): array => collect(self::decodedPercentages($value))
                ->mapWithKeys(fn ($share, $subject) => [OfferPercentages::normalise((string) $subject) => (float) $share])
                ->sortKeys()
                ->all();

            if ($normalise($offer->getOriginal('percentage')) === $normalise($offer->percentage)) {
                return;
            }

            $log = activity()
                ->performedOn($offer)
                ->withProperties(['percentage' => [
                    'old' => self::decodedPercentages($offer->getOriginal('percentage')),
                    'new' => self::decodedPercentages($offer->percentage),
                ]]);

            if ($user = auth()->user()) {
                $log->causedBy($user);
            }

            $log->log('Offer percentages updated');
        });
    }

    /** Decoded, so a key reorder alone does not log noise. */
    private static function decodedPercentages(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        return is_array($value) ? $value : [];
    }

    // Define the relationship to Level
    public function level()
    {
        return $this->belongsTo(Level::class, 'levelId');
    }
}
