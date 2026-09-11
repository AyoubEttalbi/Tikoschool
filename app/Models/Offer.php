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
     * exactly that. Percentage AND price changes are audit-logged with old/new
     * values (a price change reprices every future invoice the same silent way);
     * renames are not.
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

            $percentChanged = $normalise($offer->getOriginal('percentage')) !== $normalise($offer->percentage);
            $priceChanged = round((float) $offer->getOriginal('price'), 2) !== round((float) $offer->price, 2);

            if (! $percentChanged && ! $priceChanged) {
                return;
            }

            $properties = [];

            if ($percentChanged) {
                $properties['percentage'] = [
                    'old' => self::decodedPercentages($offer->getOriginal('percentage')),
                    'new' => self::decodedPercentages($offer->percentage),
                ];
            }

            if ($priceChanged) {
                $properties['price'] = [
                    'old' => $offer->getOriginal('price'),
                    'new' => $offer->price,
                ];
            }

            $log = activity()
                ->performedOn($offer)
                ->withProperties($properties);

            if ($user = auth()->user()) {
                $log->causedBy($user);
            }

            $log->log($percentChanged ? 'Offer percentages updated' : 'Offer price updated');
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
