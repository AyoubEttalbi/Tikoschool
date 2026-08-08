<?php

namespace App\Notifications\Contracts;

use App\Notifications\SendResult;

/**
 * One way of getting a message to a parent.
 *
 * The point of this interface is the rule that the rest of the app obeys because of it:
 * the attendance code must never know whether a notice travels by WhatsApp, SMS or
 * email. OutboundMessageService resolves a channel by name and calls send(); nothing
 * upstream of that names a provider.
 *
 * It is deliberately one method. WhatsApp today is delivered through App\Support\WhatsApp,
 * which already switches between the self-hosted gateway, the old paid vendor and a
 * log-only driver on config — so provider swapping lives there, one level down, and does
 * not need a class per provider up here. This seam exists for the CHANNEL (whatsapp vs
 * sms), not for the provider behind it.
 */
interface MessageChannel
{
    /**
     * @param  string  $recipient  Already normalised by the caller — a channel does not guess.
     */
    public function send(string $recipient, string $message): SendResult;

    /** Recorded on the row so a failure can be traced to what actually delivered it. */
    public function name(): string;
}
