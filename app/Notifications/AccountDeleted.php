<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once, after an account is deleted, to the address it used. The address
 * is held in memory for this send only. The account row is already gone.
 */
class AccountDeleted extends Notification
{
    /** @param  array{account: int, overrides: int, links: int, sessions: int, counters: int, log_lines: int}  $removed */
    public function __construct(public array $removed) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->removed;

        return (new MailMessage)
            ->subject('Your PRISM account is deleted')
            ->greeting('Hello,')
            ->line('Your PRISM account and the data tied to it are deleted for good. They cannot be restored.')
            ->line('Removed: the account (email address and password hash), '.$r['overrides'].' override '.($r['overrides'] === 1 ? 'entry' : 'entries').', '.$r['links'].' unused '.($r['links'] === 1 ? 'link' : 'links').', '.$r['sessions'].' signed-in '.($r['sessions'] === 1 ? 'session' : 'sessions').', '.$r['counters'].' rate-limit '.($r['counters'] === 1 ? 'counter' : 'counters').' and '.$r['log_lines'].' '.($r['log_lines'] === 1 ? 'line' : 'lines').' in PRISM\'s own log files.')
            ->line('The hosting platform (AWS) keeps its own server logs with IP addresses and request times. PRISM cannot edit them. They are removed when the platform rotates them or the server is replaced.')
            ->line('If you did not ask for this, reply to the contest entrant at the address in the Privacy Policy.')
            ->salutation('PRISM');
    }
}
