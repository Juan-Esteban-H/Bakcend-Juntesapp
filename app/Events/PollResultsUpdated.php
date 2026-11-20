<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PollResultsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $junteId,
        public readonly int $pollId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("junte.{$this->junteId}")];
    }

    public function broadcastAs(): string
    {
        return 'poll.results.updated';
    }

    public function broadcastWith(): array
    {
        $poll = DB::table('encuestas')->where('id', $this->pollId)->first();
        $options = DB::table('opciones_encuesta')
            ->leftJoin('votos', 'votos.opcion_id', '=', 'opciones_encuesta.id')
            ->where('opciones_encuesta.encuesta_id', $this->pollId)
            ->groupBy(
                'opciones_encuesta.id',
                'opciones_encuesta.valor',
                'opciones_encuesta.fecha_inicio',
                'opciones_encuesta.fecha_fin',
                'opciones_encuesta.latitud',
                'opciones_encuesta.longitud',
            )
            ->select([
                'opciones_encuesta.id',
                'opciones_encuesta.valor',
                'opciones_encuesta.fecha_inicio',
                'opciones_encuesta.fecha_fin',
                'opciones_encuesta.latitud',
                'opciones_encuesta.longitud',
            ])
            ->selectRaw('COUNT(votos.usuario_id) as votos')
            ->orderBy('opciones_encuesta.id')
            ->get();

        return [
            'junte_id' => $this->junteId,
            'encuesta_id' => $this->pollId,
            'estado' => $poll?->estado,
            'opciones' => $options->map(fn (object $option): array => [
                'id' => (int) $option->id,
                'valor' => $option->valor,
                'fecha_inicio' => $option->fecha_inicio,
                'fecha_fin' => $option->fecha_fin,
                'latitud' => $option->latitud === null ? null : (float) $option->latitud,
                'longitud' => $option->longitud === null ? null : (float) $option->longitud,
                'votos' => (int) $option->votos,
            ])->values()->all(),
        ];
    }
}
