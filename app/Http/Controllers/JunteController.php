<?php

namespace App\Http\Controllers;

use App\Events\PollResultsUpdated;
use App\Events\JunteChanged;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class JunteController extends Controller
{
    public function explorar(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::table('juntes')
            ->where('estado', 'activo')
            ->whereNotNull('fecha_final')
            ->where('fecha_final', '<', now())
            ->update(['estado' => 'finalizado', 'updated_at' => now()]);

        $categoria = $request->query('categoria');
        $allowedCategories = ['Todos', 'Anime', 'K-pop', 'Japón', 'Música', 'Gaming', 'Cine', 'Deportes', 'Otros'];

        $juntes = DB::table('juntes')
            ->join('users as organizador', 'organizador.id', '=', 'juntes.creador_id')
            ->where('juntes.estado', 'activo')
            ->when(
                is_string($categoria) && in_array($categoria, $allowedCategories, true) && $categoria !== 'Todos',
                fn (Builder $query): Builder => $query->where('juntes.categoria', $categoria),
            )
            ->select([
                'juntes.id',
                'juntes.titulo',
                'juntes.descripcion',
                'juntes.categoria',
                'juntes.estado',
                'juntes.fecha_final',
                'juntes.ubicacion_final',
                'juntes.latitud',
                'juntes.longitud',
                'organizador.nombre as organizador_nombre',
                'organizador.avatar_url as organizador_avatar_url',
            ])
            ->orderByDesc('juntes.created_at')
            ->limit(100)
            ->get()
            ->map(fn (object $junte): array => [
                'id' => $junte->id,
                'titulo' => $junte->titulo,
                'descripcion' => $junte->descripcion,
                'categoria' => $junte->categoria ?: 'Otros',
                'estado' => $junte->estado,
                'fecha_final' => $junte->fecha_final,
                'ubicacion' => [
                    'nombre' => $junte->ubicacion_final,
                    'latitud' => $junte->latitud,
                    'longitud' => $junte->longitud,
                ],
                'organizador' => [
                    'nombre' => $junte->organizador_nombre,
                    'avatar_url' => UserResource::avatarUrl($junte->organizador_avatar_url),
                ],
            ]);

        return response()->json([
            'juntes' => $juntes->values(),
            'categorias' => array_values(array_slice($allowedCategories, 1)),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::table('juntes')
            ->where('estado', 'activo')
            ->whereNotNull('fecha_final')
            ->where('fecha_final', '<', now())
            ->update(['estado' => 'finalizado', 'updated_at' => now()]);

        $juntes = DB::table('juntes')
            ->join('users as organizador', 'organizador.id', '=', 'juntes.creador_id')
            ->where(function (Builder $query) use ($user): void {
                $query->where('juntes.creador_id', $user->id)
                    ->orWhereExists(function (Builder $participationQuery) use ($user): void {
                        $participationQuery->selectRaw('1')
                            ->from('junte_usuario')
                            ->whereColumn('junte_usuario.junte_id', 'juntes.id')
                            ->where('junte_usuario.usuario_id', $user->id);
                    });
            })
            ->select([
                'juntes.id',
                'juntes.titulo',
                'juntes.descripcion',
                'juntes.categoria',
                'juntes.estado',
                'organizador.nombre as organizador_nombre',
                'organizador.avatar_url as organizador_avatar_url',
            ])
            ->orderByDesc('juntes.created_at')
            ->get()
            ->map(fn (object $junte): array => [
                'id' => $junte->id,
                'titulo' => $junte->titulo,
                'descripcion' => $junte->descripcion,
                'categoria' => $junte->categoria ?: 'Otros',
                'estado' => $junte->estado,
                'organizador' => [
                    'nombre' => $junte->organizador_nombre,
                    'avatar_url' => UserResource::avatarUrl($junte->organizador_avatar_url),
                ],
            ]);

        return response()->json([
            'juntes' => $juntes->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'titulo' => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'categoria' => 'sometimes|string|max:50',
        ]);

        $junteId = DB::transaction(function () use ($user, $validated): int {
            $junteId = DB::table('juntes')->insertGetId([
                'creador_id' => $user->id,
                'titulo' => $validated['titulo'],
                'descripcion' => $validated['descripcion'] ?? null,
                'categoria' => $validated['categoria'] ?? 'Otros',
                'estado' => 'activo',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('junte_usuario')->insert([
                'junte_id' => $junteId,
                'usuario_id' => $user->id,
                'rol' => 'organizador',
                'estado_rsvp' => 'confirmado',
                'cuota_pagada' => false,
            ]);

            return $junteId;
        });

        return response()->json([
            'message' => 'Junte creado exitosamente.',
            'junte_id' => $junteId,
            'redirect_url' => route('juntes.show', $junteId),
        ], 201);
    }

    public function storeDatePoll(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:150'],
            'cierra_en' => ['nullable', 'date', 'after:now'],
            'opciones' => ['required', 'array', 'min:2', 'max:12'],
            'opciones.*.valor' => ['required', 'string', 'max:150'],
            'opciones.*.fecha_inicio' => ['required', 'date', 'after:now'],
            'opciones.*.fecha_fin' => ['required', 'date'],
        ]);

        foreach ($validated['opciones'] as $index => $option) {
            if (strtotime($option['fecha_fin']) <= strtotime($option['fecha_inicio'])) {
                throw ValidationException::withMessages([
                    "opciones.$index.fecha_fin" => ['La hora de fin debe ser posterior al inicio.'],
                ]);
            }
        }

        $pollId = DB::transaction(function () use ($id, $user, $validated): int {
            $junte = DB::table('juntes')->where('id', $id)->lockForUpdate()->first();
            abort_if($junte === null, 404);
            abort_unless($this->isJunteMember($id, $user, (int) $junte->creador_id), 404);
            abort_unless($junte->estado === 'activo', 409, 'El junte no acepta nuevas encuestas.');
            abort_if(
                DB::table('encuestas')->where('junte_id', $id)->where('tipo', 'fecha')->exists(),
                409,
                'Este junte ya tiene una encuesta de fechas.',
            );

            $now = now();
            $pollId = DB::table('encuestas')->insertGetId([
                'junte_id' => $id,
                'tipo' => 'fecha',
                'titulo' => $validated['titulo'],
                'estado' => 'abierta',
                'cierra_en' => $validated['cierra_en'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('opciones_encuesta')->insert(
                collect($validated['opciones'])->map(fn (array $option): array => [
                    'encuesta_id' => $pollId,
                    'valor' => trim($option['valor']),
                    'fecha_inicio' => $option['fecha_inicio'],
                    'fecha_fin' => $option['fecha_fin'],
                ])->all(),
            );

            return (int) $pollId;
        });

        event(new PollResultsUpdated($id, $pollId));

        return $this->show($request, $id);
    }

    public function storeLocationPoll(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'titulo' => ['required', 'string', 'max:150'],
            'opciones' => ['required', 'array', 'min:2', 'max:12'],
            'opciones.*.valor' => ['required', 'string', 'max:255'],
            'opciones.*.latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'opciones.*.longitud' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $uniqueValues = [];
        $uniqueCoordinates = [];
        foreach ($validated['opciones'] as $index => $option) {
            $value = mb_strtolower(trim($option['valor']));
            if (in_array($value, $uniqueValues, true)) {
                throw ValidationException::withMessages([
                    "opciones.$index.valor" => ['Cada opción debe ser una ubicación distinta.'],
                ]);
            }
            $uniqueValues[] = $value;

            $hasLatitude = array_key_exists('latitud', $option) && $option['latitud'] !== null;
            $hasLongitude = array_key_exists('longitud', $option) && $option['longitud'] !== null;
            if ($hasLatitude !== $hasLongitude) {
                throw ValidationException::withMessages([
                    "opciones.$index" => ['La latitud y longitud deben enviarse juntas.'],
                ]);
            }
            if ($hasLatitude) {
                $coordinates = round((float) $option['latitud'], 6).','.round((float) $option['longitud'], 6);
                if (in_array($coordinates, $uniqueCoordinates, true)) {
                    throw ValidationException::withMessages([
                        "opciones.$index" => ['Cada opción debe ser una ubicación distinta.'],
                    ]);
                }
                $uniqueCoordinates[] = $coordinates;
            }
        }

        $pollId = DB::transaction(function () use ($id, $user, $validated): int {
            $junte = DB::table('juntes')->where('id', $id)->lockForUpdate()->first();
            abort_if($junte === null, 404);
            abort_unless((int) $junte->creador_id === (int) $user->id, 403, 'Solo el creador puede crear la encuesta de ubicaciones.');
            abort_unless($junte->estado === 'activo', 409, 'El junte no acepta nuevas encuestas.');
            abort_if(
                DB::table('encuestas')->where('junte_id', $id)->where('tipo', 'ubicacion')->exists(),
                409,
                'Este junte ya tiene una encuesta de ubicaciones.',
            );

            $now = now();
            $pollId = DB::table('encuestas')->insertGetId([
                'junte_id' => $id,
                'tipo' => 'ubicacion',
                'titulo' => $validated['titulo'],
                'estado' => 'abierta',
                'cierra_en' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('opciones_encuesta')->insert(
                collect($validated['opciones'])->map(fn (array $option): array => [
                    'encuesta_id' => $pollId,
                    'valor' => trim($option['valor']),
                    'fecha_inicio' => null,
                    'fecha_fin' => null,
                    'latitud' => $option['latitud'] ?? null,
                    'longitud' => $option['longitud'] ?? null,
                ])->all(),
            );

            return (int) $pollId;
        });

        event(new PollResultsUpdated($id, $pollId));

        return $this->show($request, $id);
    }

    public function storePollOption(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'valor' => ['required', 'string', 'max:150'],
            'fecha_inicio' => ['required', 'date', 'after:now'],
            'fecha_fin' => ['required', 'date'],
        ]);

        if (strtotime($validated['fecha_fin']) <= strtotime($validated['fecha_inicio'])) {
            throw ValidationException::withMessages([
                'fecha_fin' => ['La hora de fin debe ser posterior al inicio.'],
            ]);
        }

        $junteId = DB::transaction(function () use ($id, $user, $validated): int {
            $poll = DB::table('encuestas')
                ->join('juntes', 'juntes.id', '=', 'encuestas.junte_id')
                ->where('encuestas.id', $id)
                ->select('encuestas.*', 'juntes.id as junte_id', 'juntes.estado as junte_estado', 'juntes.creador_id')
                ->lockForUpdate()
                ->first();

            abort_if($poll === null, 404);
            abort_unless($this->isJunteMember((int) $poll->junte_id, $user, (int) $poll->creador_id), 404);
            abort_unless($poll->tipo === 'fecha', 409, 'Solo se pueden proponer fechas en encuestas de fechas.');
            $this->assertPollOpen($poll);
            abort_unless($poll->junte_estado === 'activo', 409, 'El junte no acepta nuevas opciones.');

            DB::table('opciones_encuesta')->insert([
                'encuesta_id' => $id,
                'valor' => trim($validated['valor']),
                'fecha_inicio' => $validated['fecha_inicio'],
                'fecha_fin' => $validated['fecha_fin'],
            ]);

            return (int) $poll->junte_id;
        });

        event(new PollResultsUpdated($junteId, $id));

        return $this->show($request, $junteId);
    }

    public function votePoll(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'opcion_id' => ['required', 'integer', 'exists:opciones_encuesta,id'],
        ]);

        $junteId = DB::transaction(function () use ($id, $user, $validated): int {
            $poll = DB::table('encuestas')
                ->join('juntes', 'juntes.id', '=', 'encuestas.junte_id')
                ->where('encuestas.id', $id)
                ->select('encuestas.*', 'juntes.id as junte_id', 'juntes.estado as junte_estado', 'juntes.creador_id')
                ->lockForUpdate()
                ->first();

            abort_if($poll === null, 404);
            abort_unless($this->isJunteMember((int) $poll->junte_id, $user, (int) $poll->creador_id), 404);
            $this->assertPollOpen($poll);
            abort_unless($poll->junte_estado === 'activo', 409, 'El junte ya no acepta votos.');
            abort_unless(
                DB::table('opciones_encuesta')
                    ->where('id', $validated['opcion_id'])
                    ->where('encuesta_id', $id)
                    ->exists(),
                422,
                'La opción no pertenece a esta encuesta.',
            );

            $optionIds = DB::table('opciones_encuesta')->where('encuesta_id', $id)->pluck('id');
            DB::table('votos')->where('usuario_id', $user->id)->whereIn('opcion_id', $optionIds)->delete();
            DB::table('votos')->insert([
                'usuario_id' => $user->id,
                'opcion_id' => $validated['opcion_id'],
            ]);

            return (int) $poll->junte_id;
        });

        event(new PollResultsUpdated($junteId, $id));

        return $this->show($request, $junteId);
    }

    public function closePoll(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $junteId = DB::transaction(function () use ($id, $user): int {
            $poll = DB::table('encuestas')
                ->join('juntes', 'juntes.id', '=', 'encuestas.junte_id')
                ->where('encuestas.id', $id)
                ->select('encuestas.id', 'encuestas.junte_id', 'juntes.creador_id')
                ->lockForUpdate()
                ->first();

            abort_if($poll === null, 404);
            $isOrganizer = (int) $poll->creador_id === (int) $user->id
                || DB::table('junte_usuario')
                    ->where('junte_id', $poll->junte_id)
                    ->where('usuario_id', $user->id)
                    ->where('rol', 'organizador')
                    ->exists();
            abort_unless($isOrganizer, 403, 'Solo el organizador puede cerrar la encuesta.');

            DB::table('encuestas')->where('id', $id)->update([
                'estado' => 'cerrada',
                'updated_at' => now(),
            ]);

            return (int) $poll->junte_id;
        });

        event(new PollResultsUpdated($junteId, $id));

        return $this->show($request, $junteId);
    }

    public function setOfficialPollLocation(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'opcion_id' => ['required', 'integer', 'exists:opciones_encuesta,id'],
        ]);

        $junteId = DB::transaction(function () use ($id, $user, $validated): int {
            $poll = DB::table('encuestas')
                ->join('juntes', 'juntes.id', '=', 'encuestas.junte_id')
                ->where('encuestas.id', $id)
                ->select(
                    'encuestas.*',
                    'juntes.id as junte_id',
                    'juntes.estado as junte_estado',
                    'juntes.creador_id',
                    'juntes.ubicacion_final',
                    'juntes.latitud as junte_latitud',
                    'juntes.longitud as junte_longitud',
                )
                ->lockForUpdate()
                ->first();

            abort_if($poll === null, 404);
            abort_unless((int) $poll->creador_id === (int) $user->id, 403, 'Solo el creador puede establecer la ubicación oficial.');
            abort_unless($poll->tipo === 'ubicacion', 409, 'La encuesta no es de ubicaciones.');
            abort_unless($poll->estado === 'cerrada', 409, 'Cierra la encuesta antes de establecer el lugar oficial.');
            abort_unless($poll->junte_estado === 'activo', 409, 'El junte ya no acepta cambios.');

            $options = DB::table('opciones_encuesta')
                ->leftJoin('votos', 'votos.opcion_id', '=', 'opciones_encuesta.id')
                ->where('opciones_encuesta.encuesta_id', $id)
                ->groupBy(
                    'opciones_encuesta.id',
                    'opciones_encuesta.valor',
                    'opciones_encuesta.latitud',
                    'opciones_encuesta.longitud',
                )
                ->select(
                    'opciones_encuesta.id',
                    'opciones_encuesta.valor',
                    'opciones_encuesta.latitud',
                    'opciones_encuesta.longitud',
                )
                ->selectRaw('COUNT(votos.usuario_id) as votos')
                ->get();

            $selectedOption = $options->firstWhere('id', (int) $validated['opcion_id']);
            abort_if($selectedOption === null, 422, 'La opción no pertenece a esta encuesta.');
            $winningVoteCount = (int) $options->max('votos');
            abort_if($winningVoteCount === 0, 409, 'La encuesta no tiene votos todavía.');
            abort_unless((int) $selectedOption->votos === $winningVoteCount, 409, 'Solo se puede establecer una opción con la mayor cantidad de votos.');

            $alreadyEstablished = $options->contains(fn (object $option): bool =>
                $option->valor === $poll->ubicacion_final
                && (string) $option->latitud === (string) $poll->junte_latitud
                && (string) $option->longitud === (string) $poll->junte_longitud
            );
            abort_unless(! $alreadyEstablished, 409, 'La ubicación ganadora ya está establecida.');

            DB::table('juntes')->where('id', $poll->junte_id)->update([
                'ubicacion_final' => $selectedOption->valor,
                'latitud' => $selectedOption->latitud,
                'longitud' => $selectedOption->longitud,
                'updated_at' => now(),
            ]);

            event(new JunteChanged(
                (int) $poll->junte_id,
                'ubicacion',
                'Ubicación oficial definida',
                'La ubicación oficial del junte ya está disponible.',
            ));

            return (int) $poll->junte_id;
        });

        event(new PollResultsUpdated($junteId, $id));

        return $this->show($request, $junteId);
    }

    public function updateRsvp(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'estado_rsvp' => ['required', 'in:confirmado,rechazado'],
        ]);

        DB::transaction(function () use ($id, $user, $validated): void {
            $junte = DB::table('juntes')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            abort_if($junte === null, 404);
            abort_unless($junte->estado === 'activo', 409, 'Este junte ya no acepta cambios de asistencia.');

            $invitacion = DB::table('junte_usuario')
                ->where('junte_id', $id)
                ->where('usuario_id', $user->id)
                ->where('rol', 'invitado')
                ->lockForUpdate()
                ->first();

            abort_unless($invitacion !== null, 403, 'Solo los invitados pueden responder a la asistencia.');

            DB::table('junte_usuario')
                ->where('junte_id', $id)
                ->where('usuario_id', $user->id)
                ->update(['estado_rsvp' => $validated['estado_rsvp']]);
        });

        return $this->show($request, $id);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // Criterio 1: Solo organizadores pueden editar
        $esOrganizador = DB::table('juntes')
            ->where('id', $id)
            ->where('creador_id', $user->id)
            ->exists()
            || DB::table('junte_usuario')
                ->where('junte_id', $id)
                ->where('usuario_id', $user->id)
                ->where('rol', 'organizador')
                ->exists();

        abort_unless($esOrganizador, 403);

        $junteExistente = DB::table('juntes')->where('id', $id)->first();
        abort_if($junteExistente === null, 404);
        abort_unless($junteExistente->estado === 'activo', 409, 'No se puede editar un junte cancelado o finalizado.');

        $validated = $request->validate([
            'titulo' => 'sometimes|string|max:150',
            'descripcion' => 'sometimes|nullable|string',
            'categoria' => 'sometimes|string|max:50',
            'fecha_final' => 'sometimes|nullable|date',
            'ubicacion_final' => 'sometimes|nullable|string|max:255',
            'latitud' => 'sometimes|nullable|numeric|between:-90,90',
            'longitud' => 'sometimes|nullable|numeric|between:-180,180',
        ]);

        $datosAnteriores = [
            'titulo' => $junteExistente->titulo,
            'descripcion' => $junteExistente->descripcion,
            'fecha_final' => $junteExistente->fecha_final,
            'ubicacion_final' => $junteExistente->ubicacion_final,
            'latitud' => $junteExistente->latitud,
            'longitud' => $junteExistente->longitud,
        ];

        $datosNuevos = [];
        $fechaCambio = false;
        $locationChanged = false;

        if (isset($validated['titulo'])) {
            $datosNuevos['titulo'] = $validated['titulo'];
        }
        if (isset($validated['descripcion'])) {
            $datosNuevos['descripcion'] = $validated['descripcion'];
        }
        if (isset($validated['categoria'])) {
            $datosNuevos['categoria'] = $validated['categoria'];
        }
        if (isset($validated['fecha_final'])) {
            $datosNuevos['fecha_final'] = $validated['fecha_final'];
            if ($junteExistente->fecha_final != $validated['fecha_final']) {
                $fechaCambio = true;
            }
        }
        if (isset($validated['ubicacion_final'])) {
            $datosNuevos['ubicacion_final'] = $validated['ubicacion_final'];
            $locationChanged = $junteExistente->ubicacion_final != $validated['ubicacion_final'];
        }
        if (array_key_exists('latitud', $validated)) {
            $datosNuevos['latitud'] = $validated['latitud'];
        }
        if (array_key_exists('longitud', $validated)) {
            $datosNuevos['longitud'] = $validated['longitud'];
        }

        $datosNuevos['updated_at'] = now();

        DB::table('juntes')->where('id', $id)->update($datosNuevos);

        if ($fechaCambio || $locationChanged) {
            $type = $fechaCambio ? 'reprogramacion' : 'ubicacion';
            $title = $fechaCambio ? 'Junte reprogramado' : 'Ubicación actualizada';
            $message = $fechaCambio
                ? 'La fecha/hora del junte fue modificada.'
                : 'Se actualizó la ubicación oficial del junte.';

            $memberIds = DB::table('junte_usuario')
                ->where('junte_id', $id)
                ->pluck('usuario_id')
                ->push($junteExistente->creador_id)
                ->unique();

            DB::table('junte_alertas')->insert(
                $memberIds->map(fn ($memberId): array => [
                    'junte_id' => $id,
                    'usuario_id' => $memberId,
                    'tipo' => $type,
                    'titulo' => $title,
                    'mensaje' => $message,
                    'datos_anteriores' => json_encode($datosAnteriores),
                    'datos_nuevos' => json_encode($datosNuevos),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all(),
            );

            event(new JunteChanged(
                $id,
                'cancelacion',
                'Junte cancelado',
                $message,
            ));

            event(new JunteChanged($id, $type, $title, $message));
        }


        $junteActualizado = DB::table('juntes')->where('id', $id)->first();

        return response()->json([
            'message' => 'Junte actualizado exitosamente.',
            'junte' => [
                'id' => $junteActualizado->id,
                'titulo' => $junteActualizado->titulo,
                'descripcion' => $junteActualizado->descripcion,
                'categoria' => $junteActualizado->categoria ?: 'Otros',
                'estado' => $junteActualizado->estado,
                'fecha_final' => $junteActualizado->fecha_final,
                'ubicacion_final' => $junteActualizado->ubicacion_final,
                'latitud' => $junteActualizado->latitud,
                'longitud' => $junteActualizado->longitud,
            ],
            'alerta_generada' => $fechaCambio,
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return DB::transaction(function () use ($id, $user): JsonResponse {
            $junte = DB::table('juntes')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            abort_if($junte === null, 404);

            $esOrganizador = (int) $junte->creador_id === (int) $user->id
                || DB::table('junte_usuario')
                    ->where('junte_id', $id)
                    ->where('usuario_id', $user->id)
                    ->where('rol', 'organizador')
                    ->exists();
            abort_unless($esOrganizador, 403);

            if ($junte->estado === 'cancelado') {
                return response()->json([
                    'message' => 'El junte ya estaba cancelado.',
                    'estado' => 'cancelado',
                ]);
            }

            abort_unless($junte->estado === 'activo', 409, 'Solo se pueden cancelar juntes activos.');

            $now = now();
            DB::table('juntes')->where('id', $id)->update([
                'estado' => 'cancelado',
                'updated_at' => $now,
            ]);
            DB::table('encuestas')
                ->where('junte_id', $id)
                ->where('estado', 'abierta')
                ->update(['estado' => 'cerrada', 'updated_at' => $now]);

            $memberIds = DB::table('junte_usuario')
                ->where('junte_id', $id)
                ->pluck('usuario_id')
                ->push($junte->creador_id)
                ->unique()
                ->values();
            $message = 'El junte "'.$junte->titulo.'" fue cancelado por su organizador.';

            DB::table('junte_alertas')->insert(
                $memberIds->map(fn ($memberId): array => [
                    'junte_id' => $id,
                    'usuario_id' => $memberId,
                    'tipo' => 'cancelacion',
                    'titulo' => 'Junte cancelado',
                    'mensaje' => $message,
                    'datos_anteriores' => json_encode(['estado' => 'activo'], JSON_THROW_ON_ERROR),
                    'datos_nuevos' => json_encode(['estado' => 'cancelado'], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );

            return response()->json([
                'message' => 'Junte cancelado y miembros notificados.',
                'estado' => 'cancelado',
            ]);
        });
    }

    public function alerts(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $alerts = DB::table('junte_alertas')
            ->join('juntes', 'juntes.id', '=', 'junte_alertas.junte_id')
            ->where('junte_alertas.usuario_id', $user->id)
            ->select([
                'junte_alertas.id',
                'junte_alertas.junte_id',
                'juntes.titulo as junte_titulo',
                'junte_alertas.tipo',
                'junte_alertas.titulo',
                'junte_alertas.mensaje',
                'junte_alertas.created_at',
            ])
            ->orderByDesc('junte_alertas.created_at')
            ->limit(100)
            ->get();

        return response()->json(['alertas' => $alerts]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        DB::table('juntes')
            ->where('id', $id)
            ->where('estado', 'activo')
            ->whereNotNull('fecha_final')
            ->where('fecha_final', '<', now())
            ->update(['estado' => 'finalizado', 'updated_at' => now()]);

        $junte = DB::table('juntes')
            ->join('users as organizador', 'organizador.id', '=', 'juntes.creador_id')
            ->where('juntes.id', $id)
            ->select([
                'juntes.*',
                'organizador.nombre as organizador_nombre',
                'organizador.avatar_url as organizador_avatar_url',
            ])
            ->first();

        abort_if($junte === null, 404);

        $membership = DB::table('junte_usuario')
            ->where('junte_id', $id)
            ->where('usuario_id', $user->id)
            ->first();
        $isParticipant = $membership !== null;
        $isJunteMember = $isParticipant || (int) $junte->creador_id === (int) $user->id;

        abort_unless(
            (int) $junte->creador_id === (int) $user->id
                || $isParticipant
                || $junte->estado === 'activo',
            404,
        );

        // Determinar si el usuario autenticado puede editar este junte
        $puedeEditar = (int) $junte->creador_id === (int) $user->id
            || DB::table('junte_usuario')
                ->where('junte_id', $id)
                ->where('usuario_id', $user->id)
                ->where('rol', 'organizador')
                ->exists();

        $participants = DB::table('junte_usuario')
            ->join('users', 'users.id', '=', 'junte_usuario.usuario_id')
            ->where('junte_usuario.junte_id', $id)
            ->select([
                'users.id',
                'users.nombre',
                'users.avatar_url',
                'junte_usuario.rol',
                'junte_usuario.estado_rsvp',
                'junte_usuario.cuota_pagada',
            ])
            ->get()
            ->map(fn (object $participant): array => [
                'id' => $participant->id,
                'nombre' => $participant->nombre,
                'avatar_url' => UserResource::avatarUrl($participant->avatar_url),
                'rol' => $participant->rol,
                'estado_rsvp' => $participant->estado_rsvp,
                'cuota_pagada' => (bool) $participant->cuota_pagada,
            ]);

        $encuestas = DB::table('encuestas')
            ->where('junte_id', $id)
            ->orderBy('id')
            ->get();

        $encuestaIds = $encuestas->pluck('id');
        $opciones = $encuestaIds->isEmpty()
            ? collect()
            : DB::table('opciones_encuesta')->whereIn('encuesta_id', $encuestaIds)->get()->groupBy('encuesta_id');
        $votos = $encuestaIds->isEmpty()
            ? collect()
            : DB::table('votos')
                ->join('opciones_encuesta', 'opciones_encuesta.id', '=', 'votos.opcion_id')
                ->whereIn('opciones_encuesta.encuesta_id', $encuestaIds)
                ->select('votos.opcion_id')
                ->selectRaw('COUNT(*) as total')
                ->groupBy('votos.opcion_id')
                ->get()
                ->mapWithKeys(fn (object $vote): array => [$vote->opcion_id => (int) $vote->total]);
        $myVoteOptionIds = $encuestaIds->isEmpty()
            ? collect()
            : DB::table('votos')
                ->join('opciones_encuesta', 'opciones_encuesta.id', '=', 'votos.opcion_id')
                ->whereIn('opciones_encuesta.encuesta_id', $encuestaIds)
                ->where('votos.usuario_id', $user->id)
                ->pluck('votos.opcion_id')
                ->flip();

        $encuestas = $encuestas->map(function (object $encuesta) use ($opciones, $votos, $myVoteOptionIds, $junte, $isJunteMember, $puedeEditar): array {
            $isClosed = $encuesta->estado === 'cerrada'
                || $junte->estado !== 'activo'
                || ($encuesta->cierra_en !== null && now()->greaterThanOrEqualTo($encuesta->cierra_en));
            $rawOptions = $opciones->get($encuesta->id, collect());
            $maxVotes = $rawOptions->max(fn (object $option): int => (int) $votos->get($option->id, 0)) ?? 0;
            $opcionesDeEncuesta = $rawOptions
                ->map(fn (object $opcion): array => [
                    'id' => $opcion->id,
                    'valor' => $opcion->valor,
                    'fecha_inicio' => $opcion->fecha_inicio ?? null,
                    'fecha_fin' => $opcion->fecha_fin ?? null,
                    'latitud' => isset($opcion->latitud) ? (float) $opcion->latitud : null,
                    'longitud' => isset($opcion->longitud) ? (float) $opcion->longitud : null,
                    'votos' => (int) $votos->get($opcion->id, 0),
                    'mi_voto' => $myVoteOptionIds->has($opcion->id),
                    'es_ubicacion_oficial' => $encuesta->tipo === 'ubicacion'
                        && $opcion->valor === $junte->ubicacion_final
                        && (string) ($opcion->latitud ?? '') === (string) ($junte->latitud ?? '')
                        && (string) ($opcion->longitud ?? '') === (string) ($junte->longitud ?? ''),
                ])
                ->values();
            $locationEstablished = $encuesta->tipo === 'ubicacion'
                && $opcionesDeEncuesta->contains(fn (array $option): bool => $option['es_ubicacion_oficial']);

            return [
                'id' => $encuesta->id,
                'tipo' => $encuesta->tipo,
                'titulo' => $encuesta->titulo,
                'estado' => $isClosed ? 'cerrada' : 'abierta',
                'cierra_en' => $encuesta->cierra_en,
                'puede_votar' => $isJunteMember && ! $isClosed,
                'puede_proponer' => $encuesta->tipo === 'fecha' && $isJunteMember && ! $isClosed,
                'puede_cerrar' => $puedeEditar && ! $isClosed,
                'puede_establecer_ubicacion' => $encuesta->tipo === 'ubicacion'
                    && $puedeEditar
                    && $isClosed
                    && $maxVotes > 0
                    && ! $locationEstablished,
                'ubicacion_establecida' => $locationEstablished,
                'opciones' => $opcionesDeEncuesta,
            ];
        });

        return response()->json([
            'junte' => [
                'id' => $junte->id,
                'creador_id' => $junte->creador_id,
                'titulo' => $junte->titulo,
                'descripcion' => $junte->descripcion,
                'categoria' => $junte->categoria ?: 'Otros',
                'estado' => $junte->estado,
                'fecha_final' => $junte->fecha_final,
                'ubicacion_final' => $junte->ubicacion_final,
                'latitud' => $junte->latitud,
                'longitud' => $junte->longitud,
                'ubicacion' => [
                    'nombre' => $junte->ubicacion_final,
                    'latitud' => $junte->latitud,
                    'longitud' => $junte->longitud,
                ],
                'gasto_total' => $junte->gasto_total,
                'organizador' => [
                    'id' => $junte->creador_id,
                    'nombre' => $junte->organizador_nombre,
                    'avatar_url' => UserResource::avatarUrl($junte->organizador_avatar_url),
                ],
                'encuestas' => $encuestas->values(),
                'participantes' => $participants->values(),
                'can_edit' => $puedeEditar,
                'can_rsvp' => $junte->estado === 'activo'
                    && $membership !== null
                    && $membership->rol === 'invitado',
                'mi_estado_rsvp' => $membership?->estado_rsvp,
            ],
        ]);
    }

    private function isJunteMember(int $junteId, User $user, int $creatorId): bool
    {
        return $creatorId === (int) $user->id
            || DB::table('junte_usuario')
                ->where('junte_id', $junteId)
                ->where('usuario_id', $user->id)
                ->exists();
    }

    private function assertPollOpen(object $poll): void
    {
        abort_unless($poll->estado === 'abierta', 409, 'La encuesta está cerrada.');
        abort_if(
            $poll->cierra_en !== null && now()->greaterThanOrEqualTo($poll->cierra_en),
            409,
            'El plazo de votación terminó.',
        );
    }
}
