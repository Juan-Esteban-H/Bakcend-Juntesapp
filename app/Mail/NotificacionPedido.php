<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificacionPedido extends Mailable
{
    use Queueable, SerializesModels;

    public array $datosApi;

    /**
     * Recibe los datos validados desde el controlador.
     */
    public function __construct(array $datosApi)
    {
        $this->datosApi = $datosApi;
    }

    /**
     * Define el asunto del correo.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Actualización del Pedido #'.$this->datosApi['pedido_id'],
        );
    }

    /**
     * Forzamos a Laravel a usar una cadena de texto directa (html string)
     * en lugar de buscar un archivo .blade.php en las carpetas.
     */
    public function content(): Content
    {
        $textoPlano = 'Hola '.$this->datosApi['usuario'].",\n\n".
                      'Te informamos que tu pedido #'.$this->datosApi['pedido_id']." ha cambiado de estado.\n".
                      'Estado actual: '.$this->datosApi['estado']."\n\n".
                      "Gracias por confiar en nosotros.\n".
                      'Soporte API.';

        return new Content(
            htmlString: nl2br(e($textoPlano)) // Convierte los saltos de línea para que se vean bien en cualquier gestor de correo
        );
    }
}
