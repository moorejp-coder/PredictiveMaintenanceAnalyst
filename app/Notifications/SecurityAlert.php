<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email for a security alert (App\Support\Alerts). It goes to the
 * addresses in ALERT_EMAIL, not to a user account, so it is sent to an
 * anonymous notifiable. It holds counts, keyed codes and an IP address, never
 * an email address, a password or a token.
 */
class SecurityAlert extends Notification
{
    /** @param  list<string>  $lines */
    public function __construct(
        public string $title,
        public string $check,
        public array $lines,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[PRISM alert] '.$this->title)
            ->greeting($this->title);

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail
            ->line('What to check: '.$this->check)
            ->salutation('PRISM');
    }
}
