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

    /**
     * Shown to the ADMIN, under the per-teacher table.
     *
     * Addressed to the person who can see the payroll, not the person who cannot. Telling an
     * assistant that a detail exists but is hidden from them is noise: it is not something
     * they can act on, and it invites them to go asking for it. Telling the admin that the
     * figures on their screen are theirs alone is a fact they need before they read them out
     * to somebody standing next to them.
     */
    public const ADMIN_ONLY_NOTE = 'Ce détail n\'est visible que par vous.';

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
        private readonly ?string $adminOnlyNote = null,
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

        $details = $admin ? self::breakdown($applied, $blocked) : [];

        return new self(
            $tone,
            $blocked === [] ? 'Facture supprimée' : 'Facture supprimée — action requise',
            self::reversalSummary($outcome),
            $details,
            [],
            $details === [] ? null : self::ADMIN_ONLY_NOTE,
        );
    }

    /**
     * The outcome in at most three short lines.
     *
     * Carries no per-teacher figures at any role — those live in the table, which only
     * admins receive. Two separate versions of this prose used to exist, one splicing names
     * and amounts mid-sentence and one without, and the detailed one restated in words every
     * figure the table underneath it was already showing.
     *
     * @param  array<string, mixed>  $outcome
     * @return array<int, string>
     */
    private static function reversalSummary(array $outcome): array
    {
        $messages = [];
        $blocked = $outcome['blocked'] ?? [];

        if (array_filter($blocked, fn ($r) => ($r['reason'] ?? '') === 'deadline_passed') !== []) {
            $messages[] = 'Dernier paiement de plus de '.($outcome['deadline_days'] ?? 7)
                .' jours : les enseignants gardent ce qui leur a été versé.';
        }

        if (array_filter($blocked, fn ($r) => in_array($r['reason'] ?? '', ['wallet_empty', 'wallet_insufficient'], true)) !== []) {
            $messages[] = 'Un solde n\'a pas permis de tout reprendre.';
        }

        if (($outcome['total_reversed'] ?? 0) > 0) {
            // The figure is withheld from non-admins on purpose. It is a total, but on a
            // single-teacher invoice a total IS that teacher's commission.
            $messages[] = self::canSeeTeacherAmounts()
                ? number_format((float) $outcome['total_reversed'], 2, ',', ' ').' DH repris des portefeuilles.'
                : 'Les montants ont été repris des portefeuilles.';
        }

        return $messages;
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
        $deadline = $preview['deadline_days'] ?? 7;
        $details = self::canSeeTeacherAmounts() ? self::breakdown([], $preview['blocked'] ?? []) : [];

        return new self(
            self::TONE_WARNING,
            'Supprimer cette facture ?',
            [
                "Dernier paiement de plus de {$deadline} jours : les enseignants gardent ce qui leur a été versé.",
                'Aucun portefeuille ne change, que vous supprimiez ou non.',
            ],
            $details,
            [[
                'label' => 'Supprimer quand même',
                'url' => "/invoices/{$invoiceId}",
                'method' => 'delete',
                'style' => 'danger',
                // Echoed back so the server knows the warning was shown and accepted. Without
                // it the same request would just re-open this dialog for ever.
                'data' => ['confirm_keep_wallet' => true],
            ]],
            $details === [] ? null : self::ADMIN_ONLY_NOTE,
        );
    }

    /**
     * Build the notice for a settled membership delete.
     *
     * Nothing moved: the paid invoices were all past the reversal window, so the
     * teachers keep what they earned and the invoices stay as history. Keeping
     * money is irreversible and dialog-worthy, hence warning whenever anything
     * was kept — success only when the membership never paid a dirham.
     *
     * @param  array{deadline_days: int, invoices: array<int, array{id: int, amount_paid: float, days_since_payment: int|null}>, rows: array<int, array{teacher_name: string, subject: string|null, kept: float}>, kept_total: float}  $settled
     */
    public static function fromSettledMembershipDelete(array $settled): self
    {
        $deadline = $settled['deadline_days'] ?? 7;
        $keptTotal = round((float) ($settled['kept_total'] ?? 0), 2);
        $admin = self::canSeeTeacherAmounts();

        $messages = $keptTotal > 0
            ? [
                "Dernier paiement de plus de {$deadline} jours : les enseignants gardent ce qui leur a été versé.",
                "Les factures sont conservées comme historique financier ; aucun portefeuille n'a changé.",
            ]
            : [
                'Aucun versement enseignant à reprendre : les portefeuilles ne changent pas.',
                'Les factures sont conservées comme historique financier.',
            ];

        // Student-paid totals are cashier history, visible to every role — exactly
        // like the delete-block details. Per-teacher kept amounts are payroll.
        $details = [];
        foreach ($settled['invoices'] ?? [] as $invoice) {
            $details[] = [
                'label' => 'Facture n°'.$invoice['id'],
                'value' => number_format((float) $invoice['amount_paid'], 2, ',', ' ').' DH payés',
                'note' => 'Conservée (délai dépassé)',
            ];
        }

        if ($admin) {
            foreach ($settled['rows'] ?? [] as $row) {
                $details[] = [
                    'label' => trim(($row['teacher_name'] ?? '').' — '.($row['subject'] ?? '')),
                    'value' => number_format((float) $row['kept'], 2, ',', ' ').' DH',
                    'note' => self::blockNote('deadline_passed'),
                ];
            }
        }

        return new self(
            $keptTotal > 0 ? self::TONE_WARNING : self::TONE_SUCCESS,
            $keptTotal > 0 ? 'Adhésion supprimée — versements conservés' : 'Adhésion supprimée',
            $messages,
            $details,
            [],
            $admin && ($settled['rows'] ?? []) !== [] ? self::ADMIN_ONLY_NOTE : null,
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
                'note' => self::blockNote((string) ($row['reason'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * Ask before a membership teacher-change moves wallet money.
     *
     * The first save attempt writes NOTHING: this dialog shows what confirming WOULD
     * do — per teacher taken back (or why it is blocked), what kept teachers get
     * taken and re-credited, and what new teachers are estimated to receive — and the
     * confirm action resubmits the full payload with the flag, so the server
     * re-validates everything instead of trusting the preview.
     *
     * Per-teacher figures are admin-only (payroll), exactly like the deletion
     * dialog: assistants see counts and the blocked warnings, which are the
     * actionable part for them.
     *
     * @param  array<string, mixed>  $preview  from TeacherMembershipPaymentService::previewMembershipTeacherChange()
     * @param  array<string, mixed>  $payload  validated student_id / offer_id / teachers to resubmit on confirm
     */
    public static function confirmMembershipChange(array $preview, int $membershipId, array $payload): self
    {
        $reversal = $preview['reversal'] ?? [];
        $removed = $preview['removed'] ?? [];
        $added = $preview['added'] ?? [];
        $kept = $preview['kept_take_back'] ?? [];
        $admin = self::canSeeTeacherAmounts();

        $messages = [];

        if (($reversal['total_reversed'] ?? 0) > 0) {
            $messages[] = $admin
                ? number_format((float) $reversal['total_reversed'], 2, ',', ' ').' DH seront repris des portefeuilles, puis recrédités aux enseignants conservés et versés aux nouveaux.'
                : 'Les portefeuilles des enseignants seront ajustés : reprise puis reversement.';
        }

        foreach ($reversal['messages'] ?? [] as $message) {
            $messages[] = $message;
        }

        if ($messages === []) {
            $messages[] = 'Aucun portefeuille ne change.';
        }

        $details = [];

        if ($admin) {
            foreach ($removed as $row) {
                $details[] = [
                    'label' => trim(($row['teacher_name'] ?? '').' — '.($row['subject'] ?? '')).' (retiré)',
                    'value' => number_format((float) $row['amount'], 2, ',', ' ').' DH',
                    'note' => $row['blocked_reason'] === null
                        ? 'Sera repris du portefeuille'
                        : self::blockNote((string) $row['blocked_reason']),
                ];
            }

            foreach ($kept as $row) {
                $details[] = [
                    'label' => trim(($row['teacher_name'] ?? '').' — '.($row['subject'] ?? '')).' (conservé)',
                    'value' => number_format((float) $row['amount'], 2, ',', ' ').' DH',
                    'note' => $row['blocked_reason'] === null
                        ? 'Repris puis recrédité (solde inchangé)'
                        : self::blockNote((string) $row['blocked_reason']),
                ];
            }

            foreach ($added as $row) {
                $details[] = [
                    'label' => trim(($row['teacher_name'] ?? '').' — '.($row['subject'] ?? '')).' (ajouté)',
                    'value' => number_format((float) $row['est_amount'], 2, ',', ' ').' DH',
                    'note' => 'Sera versé (estimation)',
                ];
            }
        }

        return new self(
            self::TONE_WARNING,
            'Modifier les enseignants ?',
            $messages,
            $details,
            [[
                'label' => 'Confirmer la modification',
                'url' => "/memberships/{$membershipId}",
                'method' => 'put',
                'style' => 'primary',
                // The first request saved nothing, so the confirm resubmits the full
                // payload plus the flag — the server re-validates from scratch.
                'data' => [
                    'confirm_teachers_change' => true,
                    'payload' => $payload,
                ],
            ]],
            $details === [] ? null : self::ADMIN_ONLY_NOTE,
        );
    }

    private static function blockNote(string $reason): string
    {
        return match ($reason) {
            'deadline_passed' => 'Conservé par l\'enseignant (délai dépassé)',
            'wallet_empty' => 'Non repris : portefeuille déjà vide',
            'wallet_insufficient' => 'Non repris : solde insuffisant',
            default => 'Non repris',
        };
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
            'admin_note' => $this->adminOnlyNote,
        ];
    }
}
