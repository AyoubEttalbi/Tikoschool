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
     * @param  array<int, string>  $messages
     * @param  array<int, array{label: string, value: string, note?: string}>  $details
     */
    private function __construct(
        private readonly string $tone,
        private readonly string $title,
        private readonly array $messages,
        private readonly array $details = [],
    ) {}

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

        $details = [];

        foreach ($applied as $row) {
            $details[] = [
                'label' => trim(($row['teacher_name'] ?? '').' — '.($row['subject'] ?? '')),
                'value' => number_format((float) $row['amount'], 2, ',', ' ').' DH',
                'note' => 'Repris du portefeuille',
            ];
        }

        foreach ($blocked as $row) {
            $details[] = [
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

        // Anything the teacher keeps is the headline; a clean claw-back is just confirmation.
        $tone = $blocked === [] ? self::TONE_SUCCESS : self::TONE_WARNING;

        return new self(
            $tone,
            $blocked === [] ? 'Facture supprimée' : 'Facture supprimée — action requise',
            $outcome['messages'] ?? [],
            $details,
        );
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
        ];
    }
}
