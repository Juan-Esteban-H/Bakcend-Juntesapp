<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class JunteChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly int $junteId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $message,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("junte.{$this->junteId}")];
    }

    public function broadcastAs(): string
    {
        return 'junte.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'junte_id' => $this->junteId,
            'tipo' => $this->type,
            'titulo' => $this->title,
            'mensaje' => $this->message,
        ];
    }
}
