<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetCode extends Notification
{
    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Código de recuperación de contraseña')
            ->greeting('Recupera tu contraseña')
            ->line('Ingresa este código en la aplicación para continuar:')
            ->line($this->code)
            ->line('El código vence en '.(int) config('auth.passwords.users.expire').' minutos.')
            ->line('Si no solicitaste recuperar tu contraseña, puedes ignorar este mensaje.');
    }
}
