<?php

namespace App\Exceptions;

/**
 * An invoice could not be turned into teacher payout records.
 *
 * Extends \Exception on purpose: InvoiceController::store() and ::update() wrap their bodies
 * in `catch (\Exception)` to roll the transaction back, and that rollback is exactly what
 * should happen here. The point of the subclass is not to escape the catch — it is to carry
 * ALL the reasons rather than just one.
 *
 * The previous code did `throw new \Exception($userFriendlyErrors[0])`, so a membership whose
 * offer left two teachers on 0% reported one of them, the clerk fixed it, resubmitted, and
 * was told about the second. Every round trip cost a form submission to learn one more fact
 * the server already knew.
 */
class PaymentProcessingException extends \Exception
{
    /** @param array<int, string> $errors user-facing, already translated */
    public function __construct(private readonly array $errors)
    {
        parent::__construct($errors[0] ?? 'Le traitement des paiements enseignants a échoué.');
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
