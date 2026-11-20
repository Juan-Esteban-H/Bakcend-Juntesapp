<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\DB;

class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly int $junteId,
        public readonly int $messageId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("junte.{$this->junteId}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    public function broadcastWith(): array
    {
        $message = DB::table('mensajes_chat')
            ->join('users', 'users.id', '=', 'mensajes_chat.usuario_id')
            ->where('mensajes_chat.id', $this->messageId)
            ->select([
                'mensajes_chat.id',
                'mensajes_chat.junte_id',
                'mensajes_chat.mensaje',
                'mensajes_chat.archivo_url',
                'mensajes_chat.archivo_nombre',
                'mensajes_chat.archivo_tipo',
                'mensajes_chat.archivo_tamano',
                'mensajes_chat.created_at',
                'users.id as usuario_id',
                'users.nombre as usuario_nombre',
                'users.avatar_url as usuario_avatar_url',
            ])
            ->first();

        if ($message === null) {
            return [
                'junte_id' => $this->junteId,
                'message_id' => $this->messageId,
            ];
        }

        return [
            'junte_id' => $this->junteId,
            'mensaje' => [
                'id' => (int) $message->id,
                'mensaje' => $message->mensaje,
                'archivo_url' => $message->archivo_url,
                'archivo_nombre' => $message->archivo_nombre,
                'archivo_tipo' => $message->archivo_tipo,
                'archivo_tamano' => $message->archivo_tamano === null ? null : (int) $message->archivo_tamano,
                'created_at' => $message->created_at,
                'usuario' => [
                    'id' => (int) $message->usuario_id,
                    'nombre' => $message->usuario_nombre,
                    'avatar_url' => $message->usuario_avatar_url,
                ],
            ],
        ];
    }
}
