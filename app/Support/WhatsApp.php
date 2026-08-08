<?php

namespace App\Support;

use App\Notifications\SendResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one place this app talks to WhatsApp.
 *
 * Before this class there were exactly two send sites and both called the paid vendor's
 * facade directly — WasenderApi::sendText(). That made the vendor un-swappable without
 * editing a controller, and un-fakeable in a test, so the only way to exercise the
 * notification path was to message a real parent.
 *
 * Everything here is send-only on purpose. Receiving, webhooks, groups and media are all
 * out of scope: the product sends absence notices to guardians and nothing else, and every
 * capability a gateway is granted is one more thing that can be abused if it leaks.
 */
class WhatsApp
{
    /**
     * Send one text message and say what happened.
     *
     * Returns a SendResult rather than a bool because the retry logic depends on a
     * distinction a bool cannot carry: a wrong number will never work, a gateway that is
     * restarting will work in thirty seconds. See App\Notifications\SendResult.
     *
     * @param  string  $phone  Any of 0612…, +212612…, 212612…, with or without spaces.
     */
    public static function send(string $phone, string $message): SendResult
    {
        $to = self::normalise($phone);

        if ($to === null) {
            // Permanent: no number of retries turns "0612" into a reachable handset.
            Log::warning('WhatsApp: unusable phone number, nothing sent', [
                'raw' => self::redact($phone),
            ]);

            return SendResult::permanent('numéro invalide');
        }

        return match (config('whatsapp.driver')) {
            'evolution' => self::viaEvolution($to, $message),
            'wasender' => self::viaWasender($to, $message),
            'null' => SendResult::sent(),
            default => self::viaLog($to, $message),
        };
    }

    /**
     * Back-compatible boolean wrapper, for call sites that genuinely only care whether
     * the message went out.
     */
    public static function sendText(string $phone, string $message): bool
    {
        return self::send($phone, $message)->success;
    }

    /**
     * Normalise to the digits-with-country-code form every WhatsApp gateway expects.
     *
     * Guardian numbers in this database are a mess — 0612345678, +212 612 345 678,
     * 212612345678, and a few with dashes. Sending an unnormalised number does not error,
     * it silently delivers to nobody, which is the worst possible failure for a
     * notification system: the school believes the parent was told.
     *
     * Returns null when the result could not be a real mobile number, so the caller can
     * log it instead of posting rubbish to the gateway.
     */
    public static function normalise(string $phone): ?string
    {
        $cc = (string) config('whatsapp.country_code', '212');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        // 00212… → 212…
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // National form 0612345678 → 212612345678. Only strip the leading 0 when what
        // follows is not already the country code, so 0212… is not mangled.
        if (str_starts_with($digits, '0')) {
            $digits = $cc.substr($digits, 1);
        } elseif (! str_starts_with($digits, $cc)) {
            // Bare 612345678 with no prefix at all.
            $digits = $cc.$digits;
        }

        // A Moroccan mobile is 212 + 9 digits = 12. Allow 10-15 overall so the same code
        // keeps working if the school ever messages a foreign number.
        $length = strlen($digits);

        return ($length >= 10 && $length <= 15) ? $digits : null;
    }

    /**
     * Evolution API — self-hosted, free, Baileys under the hood.
     *
     * POST /message/sendText/{instance} with the key in an `apikey` header. A 2xx means
     * the gateway accepted it, NOT that WhatsApp delivered it; delivery is asynchronous
     * and only observable through a webhook this app deliberately does not implement.
     */
    private static function viaEvolution(string $to, string $message): SendResult
    {
        $config = config('whatsapp.evolution');

        if (empty($config['api_key'])) {
            // Permanent on purpose: retrying a misconfiguration for hours buries the one
            // line an operator needs to see.
            Log::error('WhatsApp: EVOLUTION_API_KEY is not set, refusing to send');

            return SendResult::permanent('passerelle non configurée');
        }

        try {
            $response = Http::withHeaders(['apikey' => $config['api_key']])
                ->timeout($config['timeout'])
                ->acceptJson()
                ->post(
                    rtrim($config['base_url'], '/').'/message/sendText/'.$config['instance'],
                    ['number' => $to, 'text' => $message]
                );
        } catch (\Throwable $e) {
            // The gateway being down must not surface as a 500 on the page that asked for
            // the notification — and it is exactly the case worth retrying.
            Log::error('WhatsApp: gateway unreachable', [
                'to' => self::redact($to),
                'error' => $e->getMessage(),
            ]);

            return SendResult::transient('passerelle injoignable');
        }

        if ($response->failed()) {
            Log::error('WhatsApp: gateway rejected the message', [
                'to' => self::redact($to),
                'status' => $response->status(),
                // Scrubbed: gateways routinely echo the submitted payload back in a
                // validation error, which would put the guardian's number and the child's
                // name in the log on the same line whose `to` was carefully redacted.
                'body' => self::scrub(mb_substr($response->body(), 0, 500)),
            ]);

            /*
             * 401/403 is a bad key and 404 a wrong instance — both stay broken until a
             * human changes something, so retrying only delays the alarm. 5xx and 408 are
             * the gateway having a moment. 400 is the status the gateway returns for a
             * number WhatsApp will not accept, so it counts as permanent: repeatedly
             * retrying a rejected recipient is itself a way to get an account flagged.
             */
            $status = $response->status();
            $permanent = in_array($status, [400, 401, 403, 404, 422], true);
            $error = 'passerelle: HTTP '.$status;

            return $permanent ? SendResult::permanent($error) : SendResult::transient($error);
        }

        // A 2xx means the gateway ACCEPTED it, not that WhatsApp delivered it. Delivery is
        // asynchronous and only observable through a webhook this app does not yet
        // implement — which is why the row carries provider_message_id.
        $id = $response->json('key.id');

        Log::info('WhatsApp: sent', ['to' => self::redact($to), 'driver' => 'evolution']);

        return SendResult::sent(is_string($id) ? $id : null);
    }

    /** The original paid vendor, kept so switching back needs no deploy. */
    private static function viaWasender(string $to, string $message): SendResult
    {
        try {
            \WasenderApi\Facades\WasenderApi::sendText($to, $message);
            Log::info('WhatsApp: sent', ['to' => self::redact($to), 'driver' => 'wasender']);

            return SendResult::sent();
        } catch (\Throwable $e) {
            Log::error('WhatsApp: wasender rejected the message', [
                'to' => self::redact($to),
                'error' => self::scrub(mb_substr($e->getMessage(), 0, 500)),
            ]);

            // The vendor SDK does not distinguish, so assume retryable and let the
            // attempt ceiling stop it.
            return SendResult::transient('vendor: '.mb_substr($e->getMessage(), 0, 200));
        }
    }

    /** Local default: prove the pipeline works without messaging a real parent. */
    private static function viaLog(string $to, string $message): SendResult
    {
        Log::info('WhatsApp [log driver, not sent]', [
            'to' => self::redact($to),
            'preview' => mb_substr($message, 0, 120),
            'length' => mb_strlen($message),
        ]);

        return SendResult::sent('log-driver');
    }

    /**
     * Guardian phone numbers are personal data and these logs are read by whoever can
     * reach the server. Keep enough to debug a delivery, not enough to call the parent.
     */
    /**
     * Remove long digit runs from anything third-party before it is logged.
     *
     * A gateway's error body is not ours and frequently contains the payload it rejected
     * — which is the guardian's phone number. Seven digits is short enough to catch a
     * local-format number and long enough to leave HTTP statuses and ids readable.
     */
    private static function scrub(string $text): string
    {
        return preg_replace('/\d{7,}/', '[number]', $text) ?? '';
    }

    private static function redact(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) <= 4
            ? '****'
            : substr($digits, 0, 4).str_repeat('*', max(0, strlen($digits) - 6)).substr($digits, -2);
    }
}
