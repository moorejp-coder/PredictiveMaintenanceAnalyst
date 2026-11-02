<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\CanonicalUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Sent when an account is locked after 10 wrong passwords in a row. It gives
 * the time of the tries, the IP addresses they came from, and a link to
 * change the password. The link opens a page with a form and an Unlock
 * button, so a mail scanner that follows links changes nothing.
 * PRISM does not look up where an IP address is.
 */
class AccountLocked extends Notification
{
    public const LINK_MINUTES = 60;

    /** @param  list<string>  $ips */
    public function __construct(
        #[\SensitiveParameter] public string $token,
        public Carbon $firstAttempt,
        public Carbon $lockedAt,
        public array $ips,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (! $notifiable instanceof User) {
            throw new InvalidArgumentException('The lock email goes to a user account.');
        }

        // The host comes from APP_URL, never from the request.
        $url = CanonicalUrl::for(route('unlock.show', [
            'token' => $this->token,
            'email' => $notifiable->email,
        ], false));

        $first = $this->firstAttempt->copy()->utc();
        $last = $this->lockedAt->copy()->utc();
        $when = $first->isSameDay($last)
            ? $first->format('j F Y').', between '.$first->format('H:i').' and '.$last->format('H:i').' UTC'
            : 'between '.$first->format('j F Y, H:i').' and '.$last->format('j F Y, H:i').' UTC';
        $unlocks = $last->copy()->addMinutes(User::LOCK_MINUTES)->format('H:i');
        $minutes = self::LINK_MINUTES;

        return (new MailMessage)
            ->subject('Someone tried to log into your PRISM account')
            ->greeting('Hello,')
            ->line("Someone tried to log into your account multiple times. If this wasn't you, please change your password.")
            ->line('When: 10 wrong passwords in a row, '.$when.'.')
            ->line('From IP address'.(count($this->ips) === 1 ? '' : 'es').': '.implode(', ', $this->ips).'.')
            ->line('Your account is locked for '.User::LOCK_MINUTES." minutes. It unlocks on its own at {$unlocks} UTC, or right away when you use the link below.")
            ->action('Change your password', $url)
            ->line("The link works once and expires in {$minutes} minutes. The page also lets you unlock the account without changing the password.")
            ->salutation('PRISM');
    }
}
