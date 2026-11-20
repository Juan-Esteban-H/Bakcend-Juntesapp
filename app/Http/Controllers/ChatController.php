<?php

namespace App\Http\Controllers;

use App\Events\ChatMessageSent;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChatController extends Controller
{
    public function index(Request $request, int $junteId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->isJunteMember($junteId, $user), 404);

        $messages = DB::table('mensajes_chat')
            ->join('users', 'users.id', '=', 'mensajes_chat.usuario_id')
            ->where('mensajes_chat.junte_id', $junteId)
            ->select([
                'mensajes_chat.id',
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
            ->orderBy('mensajes_chat.created_at')
            ->orderBy('mensajes_chat.id')
            ->limit(100)
            ->get()
            ->map(fn (object $message): array => [
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
                    'avatar_url' => UserResource::avatarUrl($message->usuario_avatar_url),
                ],
            ])
            ->values();

        return response()->json(['mensajes' => $messages]);
    }

    public function store(Request $request, int $junteId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->isJunteMember($junteId, $user), 404);

        $validated = $request->validate([
            'mensaje' => ['required', 'string', 'max:2000'],
        ]);

        $message = strip_tags(trim($validated['mensaje']));
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message) ?? '';
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');

        if ($message === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'mensaje' => ['El mensaje no puede estar vacío después de sanitizarlo.'],
            ]);
        }

        $messageId = DB::table('mensajes_chat')->insertGetId([
            'junte_id' => $junteId,
            'usuario_id' => $user->id,
            'mensaje' => $message,
            'archivo_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        event(new ChatMessageSent($junteId, $messageId));

        $stored = DB::table('mensajes_chat')
            ->join('users', 'users.id', '=', 'mensajes_chat.usuario_id')
            ->where('mensajes_chat.id', $messageId)
            ->select([
                'mensajes_chat.id',
                'mensajes_chat.mensaje',
                'mensajes_chat.created_at',
                'users.id as usuario_id',
                'users.nombre as usuario_nombre',
                'users.avatar_url as usuario_avatar_url',
            ])
            ->first();

        return response()->json([
            'mensaje' => [
                'id' => (int) $stored->id,
                'mensaje' => $stored->mensaje,
                'created_at' => $stored->created_at,
                'usuario' => [
                    'id' => (int) $stored->usuario_id,
                    'nombre' => $stored->usuario_nombre,
                    'avatar_url' => UserResource::avatarUrl($stored->usuario_avatar_url),
                ],
            ],
        ], 201);
    }

    public function storeAttachment(Request $request, int $junteId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($this->isJunteMember($junteId, $user), 404);

        $validated = $request->validate([
            'archivo' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,pdf,txt,doc,docx',
                'max:10240',
            ],
        ]);

        $file = $validated['archivo'];
        $extension = strtolower($file->getClientOriginalExtension());
        $storedName = str_replace(
            ['<', '>', '"', "'", '&'],
            '',
            $file->getClientOriginalName(),
        );
        $path = $file->store('chat', 'public');
        abort_if(! is_string($path), 500, 'No se pudo almacenar el adjunto.');

        $messageId = DB::table('mensajes_chat')->insertGetId([
            'junte_id' => $junteId,
            'usuario_id' => $user->id,
            'mensaje' => $extension === 'pdf' || in_array($extension, ['txt', 'doc', 'docx'], true)
                ? 'Adjunto: '.$storedName
                : 'Imagen compartida',
            'archivo_url' => Storage::disk('public')->url($path),
            'archivo_nombre' => $storedName,
            'archivo_tipo' => $file->getMimeType(),
            'archivo_tamano' => $file->getSize(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        event(new ChatMessageSent($junteId, $messageId));

        $stored = DB::table('mensajes_chat')
            ->join('users', 'users.id', '=', 'mensajes_chat.usuario_id')
            ->where('mensajes_chat.id', $messageId)
            ->select([
                'mensajes_chat.id',
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

        return response()->json([
            'mensaje' => [
                'id' => (int) $stored->id,
                'mensaje' => $stored->mensaje,
                'archivo_url' => $stored->archivo_url,
                'archivo_nombre' => $stored->archivo_nombre,
                'archivo_tipo' => $stored->archivo_tipo,
                'archivo_tamano' => (int) $stored->archivo_tamano,
                'created_at' => $stored->created_at,
                'usuario' => [
                    'id' => (int) $stored->usuario_id,
                    'nombre' => $stored->usuario_nombre,
                    'avatar_url' => UserResource::avatarUrl($stored->usuario_avatar_url),
                ],
            ],
        ], 201);
    }

    private function isJunteMember(int $junteId, User $user): bool
    {
        $junte = DB::table('juntes')->where('id', $junteId)->first();

        if ($junte === null) {
            return false;
        }

        if ((int) $junte->creador_id === (int) $user->id) {
            return $junte->estado === 'activo';
        }

        return $junte->estado === 'activo'
            && DB::table('junte_usuario')
                ->where('junte_id', $junteId)
                ->where('usuario_id', $user->id)
                ->exists();
    }
}
