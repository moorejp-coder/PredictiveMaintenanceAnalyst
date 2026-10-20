<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invite email for a contest judge. It holds a link, never a password.
 * The link opens the set-password page and works once. The token comes from
 * the "invites" password broker (config/auth.php).
 */
class JudgeInvite extends Notification
{
    public function __construct(#[\SensitiveParameter] public string $token)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('invite.show', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
        $days = max(1, (int) round(config('auth.passwords.invites.expire') / 1440));

        return (new MailMessage)
            ->subject('Set your password for the PRISM contest demo')
            ->greeting('Hello,')
            ->line('You are invited to review PRISM, a failure-risk dashboard for a milling machine. It is a student entry in the CUA Busch School AI Vibe Coding Contest.')
            ->line('Use the button below to choose your password. Then sign in with this email address and your password.')
            ->action('Set your password', $url)
            ->line("This link works once and expires in {$days} days.")
            ->line('If you did not expect this email, ignore it. The account cannot be used until a password is set.')
            ->salutation('PRISM contest demo');
    }
}
