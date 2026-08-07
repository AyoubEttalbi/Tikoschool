<?php

namespace App\Support;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The rules for paying a member of staff, in one place.
 *
 * WHY THIS EXISTS
 * ---------------
 * These rules were written four times — in TransactionController::store(), again lower
 * down in the same method, in update(), and in processBatchPayment() — and the four copies
 * did not agree:
 *
 *   - store() refused a teacher whose wallet was exactly 0 in one block, then refused
 *     wallet <= 0 in the next, then checked wallet < amount. Three overlapping guards for
 *     one question.
 *   - update() checked the wallet only when the amount went UP, and never checked an
 *     assistant's salary cap at all. So an edit could pay an assistant twice their salary
 *     when a create could not.
 *   - processBatchPayment() had the assistant cap but not the "must be a payable role"
 *     check, so an admin user in the batch produced a salary row nobody could explain.
 *
 * Every one of those paths now asks this class. A rule fixed here is fixed everywhere.
 *
 * WHY IT THROWS ValidationException
 * ---------------------------------
 * These are messages about a specific field — the amount is too high, the person cannot be
 * paid this way — and the person reading them is looking at that field. Returned as
 * `->with('error', ...)` they landed in a flash key the payments page never rendered, so
 * the form simply appeared to do nothing. As validation errors they arrive attached to the
 * input that caused them.
 */
class TransactionRules
{
    /** Roles that can be paid at all. Everyone else has no balance to draw on. */
    public const PAYABLE_ROLES = ['teacher', 'assistant'];

    /**
     * Which payment type applies to this person.
     *
     * Not a choice the user gets to make: a teacher is paid out of their wallet, an
     * assistant is paid a salary. The form used to offer the type as a dropdown and then
     * silently override it on submit, which is why the screen showed one thing and the
     * saved row said another.
     */
    public static function typeFor(User $user): ?string
    {
        return match ($user->role) {
            'teacher' => Transaction::TYPE_PAYMENT,
            'assistant' => Transaction::TYPE_SALARY,
            default => null,
        };
    }

    /**
     * How much this person can be paid right now, in DH.
     *
     * Teachers: the wallet balance. Assistants: their salary less whatever they have
     * already been paid in the month of $date.
     *
     * $excludeTransactionId is the row being edited. Without it an edit measures itself:
     * changing a 500 DH salary row to 600 reads "already paid 500 of 1000, 500 available"
     * and refuses a payment that is really only 100 DH more.
     */
    public static function availableFor(User $user, Carbon $date, ?int $excludeTransactionId = null): float
    {
        if ($user->role === 'teacher') {
            $teacher = $user->teacher;

            if (! $teacher) {
                return 0.0;
            }

            $available = (float) $teacher->wallet;

            // A teacher payout has already left the wallet, so editing it upward has that
            // much more headroom than the current balance suggests.
            if ($excludeTransactionId) {
                $available += (float) Transaction::where('id', $excludeTransactionId)
                    ->where('type', Transaction::TYPE_PAYMENT)
                    ->where('user_id', $user->id)
                    ->value('amount');
            }

            return round($available, 2);
        }

        if ($user->role === 'assistant') {
            $assistant = $user->assistant;

            if (! $assistant) {
                return 0.0;
            }

            $alreadyPaid = (float) Transaction::where('user_id', $user->id)
                ->where('type', Transaction::TYPE_SALARY)
                ->when($excludeTransactionId, fn ($q) => $q->where('id', '!=', $excludeTransactionId))
                ->inMonth($date->year, $date->month)
                ->sum('amount');

            return round(max(0, (float) $assistant->salary - $alreadyPaid), 2);
        }

        return 0.0;
    }

    /**
     * Refuse the payment, in French, on the field the user is looking at.
     *
     * @throws ValidationException
     */
    public static function assertPayable(User $user, float $amount, Carbon $date, ?int $excludeTransactionId = null): void
    {
        $type = self::typeFor($user);

        if ($type === null) {
            throw ValidationException::withMessages([
                'user_id' => "Seuls les enseignants et les assistants peuvent être payés ici. « {$user->name} » est ".self::roleLabel($user->role).'.',
            ]);
        }

        // The staff record is matched to the user account BY EMAIL, not by a foreign key,
        // so it goes missing whenever somebody changes one of the two addresses. Worth
        // saying out loud rather than reporting a 0 DH balance the admin cannot explain.
        $profile = $user->role === 'teacher' ? $user->teacher : $user->assistant;

        if (! $profile) {
            throw ValidationException::withMessages([
                'user_id' => 'Aucune fiche '.self::roleLabel($user->role)." n'est rattachée à « {$user->name} » ({$user->email}). "
                    .'La fiche et le compte doivent avoir la même adresse e-mail.',
            ]);
        }

        $available = self::availableFor($user, $date, $excludeTransactionId);
        $month = self::monthLabel($date);

        if ($available <= 0) {
            throw ValidationException::withMessages([
                'amount' => $user->role === 'teacher'
                    ? "Le portefeuille de « {$user->name} » est vide. Il n'y a rien à payer."
                    : "« {$user->name} » a déjà reçu la totalité de son salaire pour {$month}.",
            ]);
        }

        if (round($amount, 2) > $available) {
            throw ValidationException::withMessages([
                'amount' => $user->role === 'teacher'
                    ? 'Montant supérieur au solde du portefeuille : '.self::money($available).' disponible, '.self::money($amount).' demandé.'
                    : "Montant supérieur au reste dû pour {$month} : ".self::money($available).' restant, '.self::money($amount).' demandé.',
            ]);
        }
    }

    /**
     * What the transaction's `rest` column should hold after this payment.
     *
     * Always computed here, never taken from the request. The form posted a `rest` it had
     * calculated itself, update() saved that value verbatim, and the form only recalculated
     * it for salaries — so every edited teacher payout stored the full wallet balance as
     * its remainder instead of what was actually left.
     */
    public static function restAfter(User $user, float $amount, Carbon $date, ?int $excludeTransactionId = null): float
    {
        return round(max(0, self::availableFor($user, $date, $excludeTransactionId) - $amount), 2);
    }

    /** Everything the form needs to explain one member of staff, without a second request. */
    public static function summarise(User $user, Carbon $date): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'type' => self::typeFor($user),
            'available' => self::availableFor($user, $date),
            'has_profile' => (bool) ($user->role === 'teacher' ? $user->teacher : $user->assistant),
        ];
    }

    public static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ').' DH';
    }

    private static function roleLabel(?string $role): string
    {
        return match ($role) {
            'teacher' => 'enseignant',
            'assistant' => 'assistant',
            'admin' => 'administrateur',
            default => 'sans rôle payable',
        };
    }

    private static function monthLabel(Carbon $date): string
    {
        $months = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
            5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
            9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
        ];

        return $months[$date->month].' '.$date->year;
    }
}
