<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\MessageChannel;
use App\Notifications\SendResult;
use App\Support\WhatsApp;

/** WhatsApp delivery. The provider behind it is chosen by config in App\Support\WhatsApp. */
class WhatsAppChannel implements MessageChannel
{
    public function send(string $recipient, string $message): SendResult
    {
        return WhatsApp::send($recipient, $message);
    }

    public function name(): string
    {
        return (string) config('whatsapp.driver', 'log');
    }
}
