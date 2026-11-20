<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json([
            'user' => UserResource::make($user)->resolve($request),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'avatar' => ['sometimes', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        if (array_key_exists('name', $validated) || array_key_exists('nombre', $validated)) {
            $user->nombre = $validated['name'] ?? $validated['nombre'];
        }

        if (array_key_exists('phone', $validated)) {
            $phone = trim((string) $validated['phone']);
            $user->phone = $phone === '' ? null : $phone;
        }

        if (array_key_exists('bio', $validated)) {
            $bio = trim((string) $validated['bio']);
            $user->bio = $bio === '' ? null : $bio;
        }

        $previousAvatar = $user->getRawOriginal('avatar_url');
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('avatars', 'public');

            if ($avatarPath === false) {
                throw new RuntimeException('No se pudo guardar el archivo de avatar.');
            }

            $user->avatar_url = $avatarPath;
        }

        $user->save();

        if (
            $request->hasFile('avatar')
            && is_string($previousAvatar)
            && str_starts_with($previousAvatar, 'avatars/')
            && $previousAvatar !== $user->avatar_url
        ) {
            Storage::disk('public')->delete($previousAvatar);
        }

        return response()->json([
            'user' => UserResource::make($user)->resolve($request),
        ]);
    }
}
