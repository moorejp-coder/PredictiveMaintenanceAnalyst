<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

/**
 * The invite email for a contest judge. It holds a link, never a password.
 * The link opens the set-password page and works once. The token comes from
 * the "invites" password broker (config/auth.php).
 */
class JudgeInvite extends Notification
{
    public function __construct(#[\SensitiveParameter] public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (! $notifiable instanceof CanResetPassword) {
            throw new InvalidArgumentException('The invite goes to a user that can reset a password.');
        }

        $url = url(route('invite.show', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
        $minutes = max(1, (int) config('auth.passwords.invites.expire'));

        return (new MailMessage)
            ->subject('Set your password for the PRISM contest demo')
            ->greeting('Hello,')
            ->line('You are invited to review PRISM, a failure-risk dashboard for a milling machine. It is a student entry in the CUA Busch School AI Vibe Coding Contest.')
            ->line('Use the button below to choose your password. Then sign in with this email address and your password.')
            ->action('Set your password', $url)
            ->line("This link works once and expires in {$minutes} minutes.")
            ->line('If you did not expect this email, ignore it. The account cannot be used until a password is set.')
            ->salutation('PRISM contest demo');
    }
}
