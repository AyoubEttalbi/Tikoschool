<?php

namespace App\Notifications;

/**
 * What a channel gives back after trying to deliver one message.
 *
 * `sendText(): bool` could not express the one distinction the retry logic depends on:
 * a wrong phone number will never succeed no matter how many times it is retried, while
 * a gateway that is restarting will succeed in thirty seconds. Retrying the first wastes
 * six hours and hides the real problem from the school; giving up on the second loses a
 * message that would have gone through.
 */
final readonly class SendResult
{
    private function __construct(
        public bool $success,
        public ?string $messageId = null,
        public ?string $error = null,
        public bool $permanent = false,
    ) {}

    public static function sent(?string $messageId = null): self
    {
        return new self(success: true, messageId: $messageId);
    }

    /** Worth another try: gateway down, timeout, 5xx, rate limited. */
    public static function transient(string $error): self
    {
        return new self(success: false, error: $error, permanent: false);
    }

    /** Never going to work: unusable number, rejected recipient, bad credentials. */
    public static function permanent(string $error): self
    {
        return new self(success: false, error: $error, permanent: true);
    }
}
