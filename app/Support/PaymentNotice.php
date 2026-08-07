<?php

namespace App\Support;

/**
 * A message about the MONEY side of an action, on its way to a dialog the user must read.
 *
 * WHY THIS IS NOT JUST `with('error', ...)`
 * -----------------------------------------
 * The existing flash channel carries one string and the pages render it as a thin banner —
 * easy to miss, and gone on the next navigation. These notices say things like "the invoice
 * was deleted but Majid keeps 240 DH, because it is 12 days old": a consequence the user
 * cannot undo and has to act on. That needs a dialog they dismiss, and a per-teacher
 * breakdown a single string cannot carry.
 *
 * Flashed under `payment_notice` and shared to Inertia as `flash.payment`, where
 * PaymentNoticeDialog renders it. One channel, so every controller that touches teacher
 * money reports the same way.
 */
class PaymentNotice
{
    public const TONE_SUCCESS = 'success';

    public const TONE_WARNING = 'warning';

    public const TONE_ERROR = 'error';

    /** Shown in place of the per-teacher table when the viewer is not an admin. */
    public const RESTRICTED_NOTE = 'Le détail par enseignant (montants et pourcentages) est visible uniquement par l\'administrateur.';

    /**
     * @param  array<int, string>  $messages
     * @param  array<int, array{label: string, value: string, note?: string}>  $details
     * @param  array<int, array{label: string, url: string, method: string, style: string, data?: array<string, mixed>}>  $actions
     */
    private function __construct(
        private readonly string $tone,
        private readonly string $title,
        private readonly array $messages,
        private readonly array $details = [],
        private readonly array $actions = [],
        private readonly ?string $restrictedNote = null,
    ) {}

    /**
     * May the current user see what each individual teacher earns?
     *
     * Per-teacher commissions are payroll. An assistant needs to know that a deletion could
     * not claw money back — that changes what they do next — but not who is paid how much,
     * which is none of their business and is visible on no other screen they can reach.
     */
    private static function canSeeTeacherAmounts(): bool
    {
        return auth()->user()?->role === 'admin';
    }

    /** @param array<int, string> $messages */
    public static function warning(string $title, array $messages, array $details = []): self
    {
        return new self(self::TONE_WARNING, $title, $messages, $details);
    }

    /** @param array<int, string> $messages */
    public static function error(string $title, array $messages, array $details = []): self
    {
        return new self(self::TONE_ERROR, $title, $messages, $details);
    }

    /** @param array<int, string> $messages */
    public static function success(string $title, array $messages, array $details = []): self
    {
        return new self(self::TONE_SUCCESS, $title, $messages, $details);
    }

    /**
     * Build the notice for a completed invoice deletion.
     *
     * Takes the outcome array from TeacherMembershipPaymentService::reverseInvoicePayments().
     * Returns null when the deletion had no effect worth interrupting the user for — an
     * unpaid invoice claws nothing back and needs no dialog.
     *
     * @param  array<string, mixed>  $outcome
     */
    public static function fromReversal(array $outcome): ?self
    {
        $blocked = $outcome['blocked'] ?? [];
        $applied = $outcome['applied'] ?? [];

        if ($blocked === [] && $applied === []) {
            return null;
        }

        // Anything the teacher keeps is the headline; a clean claw-back is just confirmation.
        $tone = $blocked === [] ? self::TONE_SUCCESS : self::TONE_WARNING;
        $admin = self::canSeeTeacherAmounts();

        return new self(
            $tone,
            $blocked === [] ? 'Facture supprimée' : 'Facture supprimée — action requise',
            $admin ? ($outcome['messages'] ?? []) : self::redactedMessages($outcome),
            $admin ? self::breakdown($applied, $blocked) : [],
            [],
            $admin ? null : self::RESTRICTED_NOTE,
        );
    }

    /**
     * Ask before deleting an invoice whose claw-back window has closed.
     *
     * Past the deadline the deletion is not reversible in any sense that helps: the invoice
     * disappears and the teachers keep the money either way. So the user is shown what will
     * happen and given the choice, rather than being told about it afterwards.
     *
     * @param  array<string, mixed>  $preview  from previewInvoiceReversal()
     */
    public static function confirmDeletion(array $preview, int $invoiceId): self
    {
        $blocked = $preview['blocked'] ?? [];
        $admin = self::canSeeTeacherAmounts();
        $deadline = $preview['deadline_days'] ?? 7;

        $messages = [
            "Cette facture date de plus de {$deadline} jours. Le montant déjà versé aux enseignants "
                .'ne peut plus être retiré de leur portefeuille.',
            'Vous pouvez fermer cette fenêtre et conserver la facture, ou la supprimer quand même. '
                .'Dans les deux cas, les portefeuilles des enseignants ne changent pas.',
        ];

        if (! $admin) {
            $messages[] = 'Le détail par enseignant est réservé à l\'administrateur.';
        }

        return new self(
            self::TONE_WARNING,
            'Supprimer cette facture ?',
            $messages,
            $admin ? self::breakdown([], $blocked) : [],
            [[
                'label' => 'Supprimer quand même',
                'url' => "/invoices/{$invoiceId}",
                'method' => 'delete',
                'style' => 'danger',
                // Echoed back so the server knows the warning was shown and accepted. Without
                // it the same request would just re-open this dialog for ever.
                'data' => ['confirm_keep_wallet' => true],
            ]],
            $admin ? null : self::RESTRICTED_NOTE,
        );
    }

    /**
     * Per-teacher rows. Admin only — this is payroll.
     *
     * @param  array<int, array<string, mixed>>  $applied
     * @param  array<int, array<string, mixed>>  $blocked
     * @return array<int, array{label: string, value: string, note: string}>
     */
    private static function breakdown(array $applied, array $blocked): array
    {
        $rows = [];

        foreach ($applied as $row) {
            $rows[] = [
                'label' => trim(($row['teacher_name'] ?? '').' — '.($row['subject'] ?? '')),
                'value' => number_format((float) $row['amount'], 2, ',', ' ').' DH',
                'note' => 'Repris du portefeuille',
            ];
        }

        foreach ($blocked as $row) {
            $rows[] = [
                'label' => trim(($row['teacher_name'] ?? '').' — '.($row['subject'] ?? '')),
                'value' => number_format((float) $row['amount'], 2, ',', ' ').' DH',
                'note' => match ($row['reason'] ?? '') {
                    'deadline_passed' => 'Conservé par l\'enseignant (délai dépassé)',
                    'wallet_empty' => 'Non repris : portefeuille déjà vide',
                    'wallet_insufficient' => 'Non repris : solde insuffisant',
                    default => 'Non repris',
                },
            ];
        }

        return $rows;
    }

    /**
     * The same facts without the payroll.
     *
     * Deliberately rebuilt from the structured outcome rather than filtered out of
     * $outcome['messages'], which embed names and amounts mid-sentence. Stripping those with
     * a regex would eventually leak one; not generating them cannot.
     *
     * @param  array<string, mixed>  $outcome
     * @return array<int, string>
     */
    private static function redactedMessages(array $outcome): array
    {
        $messages = [];
        $blocked = $outcome['blocked'] ?? [];

        $expired = array_filter($blocked, fn ($r) => ($r['reason'] ?? '') === 'deadline_passed');
        if ($expired !== []) {
            $count = count($expired);
            $messages[] = 'Cette facture date de plus de '.($outcome['deadline_days'] ?? 7)
                .' jours : le montant déjà versé ne peut plus être retiré du portefeuille des enseignants. '
                .$count.' enseignant'.($count > 1 ? 's' : '').' concerné'.($count > 1 ? 's' : '').'.';
        }

        $short = array_filter(
            $blocked,
            fn ($r) => in_array($r['reason'] ?? '', ['wallet_empty', 'wallet_insufficient'], true)
        );
        if ($short !== []) {
            $messages[] = 'Le solde d\'un ou plusieurs enseignants n\'a pas permis de reprendre '
                .'la totalité du montant. Signalez-le à l\'administrateur.';
        }

        if (($outcome['total_reversed'] ?? 0) > 0) {
            $messages[] = 'Les montants versés aux enseignants pour cette facture ont été repris.';
        }

        return $messages;
    }

    /**
     * Build the notice for an invoice that could not be processed.
     *
     * @param  array<int, string>  $errors
     */
    public static function fromProcessingErrors(array $errors): ?self
    {
        $errors = array_values(array_filter($errors));

        if ($errors === []) {
            return null;
        }

        return self::error(
            'La facture n\'a pas pu être enregistrée',
            $errors,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tone' => $this->tone,
            'title' => $this->title,
            'messages' => array_values($this->messages),
            'details' => array_values($this->details),
            'actions' => array_values($this->actions),
            'restricted_note' => $this->restrictedNote,
        ];
    }
}
