<?php

namespace App\Http\Controllers;

use App\Models\Amistad;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AmistadController extends Controller
{
    public function buscar(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $query = trim($validated['q']);

        $usuarios = User::query()
            ->whereKeyNot((int) $user->id)
            ->where(function ($builder) use ($query): void {
                $builder->where('nombre', 'like', '%'.$query.'%')
                    ->orWhere('correo', 'like', '%'.$query.'%');
            })
            ->limit(20)
            ->get(['id', 'nombre', 'correo', 'avatar_url'])
            ->map(fn (User $otro): array => [
                'id' => (int) $otro->id,
                'nombre' => $otro->nombre,
                'correo' => $otro->correo,
                'avatar_url' => $otro->avatar_url,
            ])
            ->values();

        return response()->json(['usuarios' => $usuarios]);
    }
    /**
     * Devuelve los amigos aceptados, las solicitudes recibidas y las enviadas
     * del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $userId = (int) $user->id;

        $amistades = Amistad::with(['usuario', 'amigo'])
            ->where(function ($q) use ($userId) {
                $q->where('usuario_id', $userId)
                    ->orWhere('amigo_id', $userId);
            })
            ->get();

        $amigos = [];
        $solicitudesRecibidas = [];
        $solicitudesEnviadas = [];

        foreach ($amistades as $amistad) {
            $otro = $amistad->usuario_id === $userId
                ? $amistad->amigo
                : $amistad->usuario;

            $data = [
                'id' => (int) $amistad->id,
                'estado' => $amistad->estado,
                'usuario' => [
                    'id' => (int) $otro->id,
                    'nombre' => $otro->nombre,
                    'correo' => $otro->correo,
                    'avatar_url' => $otro->avatar_url,
                ],
                'creada_en' => $amistad->created_at,
            ];

            if ($amistad->estado === 'aceptado') {
                $amigos[] = $data;
            } elseif ($amistad->estado === 'pendiente' && $amistad->amigo_id === $userId) {
                $solicitudesRecibidas[] = $data;
            } elseif ($amistad->estado === 'pendiente' && $amistad->usuario_id === $userId) {
                $solicitudesEnviadas[] = $data;
            }
        }

        usort($amigos, fn (array $a, array $b) => strcmp($a['usuario']['nombre'] ?? '', $b['usuario']['nombre'] ?? ''));

        return response()->json([
            'amigos' => array_values($amigos),
            'solicitudes_recibidas' => array_values($solicitudesRecibidas),
            'solicitudes_enviadas' => array_values($solicitudesEnviadas),
        ]);
    }

    /**
     * Envía una solicitud de amistad a otro usuario.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'amigo_id' => ['required', 'exists:users,id'],
        ]);

        $usuarioId = (int) $user->id;
        $amigoId = (int) $validated['amigo_id'];

        abort_if($usuarioId === $amigoId, 409, 'No puedes enviarte una solicitud a ti mismo.');

        $yaExiste = Amistad::where(function ($q) use ($usuarioId, $amigoId) {
            $q->where('usuario_id', $usuarioId)->where('amigo_id', $amigoId);
        })->orWhere(function ($q) use ($usuarioId, $amigoId) {
            $q->where('usuario_id', $amigoId)->where('amigo_id', $usuarioId);
        })->exists();

        abort_if($yaExiste, 409, 'Ya existe una solicitud de amistad entre ustedes.');

        $amistad = Amistad::create([
            'usuario_id' => $usuarioId,
            'amigo_id' => $amigoId,
            'estado' => 'pendiente',
        ]);

        return response()->json([
            'id' => (int) $amistad->id,
            'estado' => $amistad->estado,
            'usuario' => [
                'id' => (int) $amistad->amigo_id,
                'nombre' => $amistad->amigo->nombre,
                'correo' => $amistad->amigo->correo,
                'avatar_url' => $amistad->amigo->avatar_url,
            ],
            'mensaje' => 'Solicitud de amistad enviada.',
        ], 201);
    }

    /**
     * Acepta una solicitud de amistad recibida.
     */
    public function aceptar(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $amistad = Amistad::find($id);
        abort_unless($amistad, 404, 'Solicitud no encontrada.');
        abort_unless((int) $amistad->amigo_id === (int) $user->id, 403, 'No eres el destinatario de esta solicitud.');
        abort_unless($amistad->estado === 'pendiente', 409, 'La solicitud ya no está pendiente.');

        $amistad->update(['estado' => 'aceptado']);

        return response()->json([
            'id' => (int) $amistad->id,
            'estado' => $amistad->estado,
            'usuario' => [
                'id' => (int) $amistad->usuario_id,
                'nombre' => $amistad->usuario->nombre,
                'correo' => $amistad->usuario->correo,
                'avatar_url' => $amistad->usuario->avatar_url,
            ],
            'mensaje' => 'Solicitud de amistad aceptada.',
        ]);
    }

    /**
     * Rechaza (elimina) una solicitud de amistad recibida.
     */
    public function rechazar(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $amistad = Amistad::find($id);
        abort_unless($amistad, 404, 'Solicitud no encontrada.');
        abort_unless((int) $amistad->amigo_id === (int) $user->id, 403, 'No eres el destinatario de esta solicitud.');
        abort_unless($amistad->estado === 'pendiente', 409, 'La solicitud ya no está pendiente.');

        $amistad->delete();

        return response()->json([
            'mensaje' => 'Solicitud de amistad rechazada.',
        ]);
    }

    /**
     * Elimina una amistad aceptada o cancela una solicitud enviada.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $amistad = Amistad::find($id);
        abort_unless($amistad, 404, 'Solicitud no encontrada.');

        $usuarioId = (int) $user->id;
        abort_unless(
            (int) $amistad->usuario_id === $usuarioId || (int) $amistad->amigo_id === $usuarioId,
            403,
            'No tienes permiso sobre esta amistad.'
        );

        $amistad->delete();

        return response()->json([
            'mensaje' => 'Amistad eliminada.',
        ]);
    }
}
