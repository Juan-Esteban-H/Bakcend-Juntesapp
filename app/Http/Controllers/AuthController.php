<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\PasswordResetCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => ['nullable', 'required_without:name', 'string', 'max:255'],
            'name' => ['nullable', 'required_without:nombre', 'string', 'max:255'],
            'correo' => ['nullable', 'required_without:email', 'string', 'email', 'max:255', 'unique:users,correo'],
            'email' => ['nullable', 'required_without:correo', 'string', 'email', 'max:255', 'unique:users,correo'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user = User::create([
            'nombre' => $validated['nombre'] ?? $validated['name'],
            'correo' => $validated['correo'] ?? $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => filled($validated['phone'] ?? null)
                ? trim($validated['phone'])
                : null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user)->resolve($request),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'correo' => ['nullable', 'required_without:email', 'string', 'email', 'max:255'],
            'email' => ['nullable', 'required_without:correo', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $correo = $validated['correo'] ?? $validated['email'];
        $user = User::where('correo', $correo)->first();

        if ($user === null || ! is_string($user->password) || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'correo' => ['Las credenciales proporcionadas no son válidas.'],
            ]);
        }

        return response()->json([
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => UserResource::make($user)->resolve($request),
        ]);
    }

    public function googleLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
            'correo' => ['required', 'email', 'max:255'],
            'nombre' => ['required', 'string', 'max:255'],
            'avatar_url' => ['nullable', 'string', 'url:https', 'max:2048'],
        ]);

        $clientId = config('services.google.client_id');
        if (! is_string($clientId) || $clientId === '') {
            abort(503, 'El inicio de sesión con Google no está configurado.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout(5)
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $validated['id_token'],
                ]);
        } catch (ConnectionException $exception) {
            report($exception);

            abort(503, 'No se pudo verificar la identidad con Google.');
        }

        if ($response->serverError()) {
            abort(503, 'No se pudo verificar la identidad con Google.');
        }

        $googleUser = $response->json();
        $isVerifiedGoogleAccount = $response->successful()
            && is_array($googleUser)
            && in_array($googleUser['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && ($googleUser['aud'] ?? null) === $clientId
            && in_array($googleUser['email_verified'] ?? null, [true, 'true'], true)
            && is_string($googleUser['email'] ?? null)
            && is_string($googleUser['sub'] ?? null)
            && $googleUser['sub'] !== ''
            && is_numeric($googleUser['exp'] ?? null)
            && (int) $googleUser['exp'] > now()->timestamp
            && Str::lower($googleUser['email']) === Str::lower($validated['correo']);

        if (! $isVerifiedGoogleAccount) {
            throw ValidationException::withMessages([
                'id_token' => ['La identidad de Google no es válida o no corresponde al correo indicado.'],
            ]);
        }

        $user = User::where('correo', $googleUser['email'])->first();

        if ($user === null) {
            $user = User::create([
                'nombre' => $validated['nombre'],
                'correo' => $googleUser['email'],
                'password' => Hash::make(Str::random(24)),
                'avatar_url' => $validated['avatar_url'] ?? null,
            ]);
        } elseif (blank($user->avatar_url) && filled($validated['avatar_url'] ?? null)) {
            $user->avatar_url = $validated['avatar_url'];
            $user->save();
        }

        return response()->json([
            'user' => UserResource::make($user)->resolve($request),
            'token' => $user->createToken('google_auth_token')->plainTextToken,
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'correo' => ['required', 'email'],
        ]);

        $user = User::where('correo', $validated['correo'])->first();

        if ($user !== null) {
            $tokenRepository = Password::broker()->getRepository();

            if (! $tokenRepository->recentlyCreatedToken($user)) {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                DB::table((string) config('auth.passwords.users.table'))->updateOrInsert(
                    ['email' => $user->getEmailForPasswordReset()],
                    [
                        'token' => Hash::make($code),
                        'created_at' => now(),
                    ],
                );

                try {
                    $user->notify(new PasswordResetCode($code));
                } catch (TransportExceptionInterface $exception) {
                    try {
                        $tokenRepository->delete($user);
                    } catch (Throwable $cleanupException) {
                        Log::error('Failed to clear password reset code after email delivery failure.', [
                            'exception' => $cleanupException::class,
                        ]);
                    }

                    Log::error('Password reset email delivery failed.', [
                        'exception' => $exception::class,
                        'mailer' => (string) config('mail.default'),
                    ]);
                }
            }
        }

        return response()->json([
            'message' => 'Si el correo está registrado, recibirás un código para restablecer tu contraseña.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigo' => ['required', 'string', 'digits:6'],
            'correo' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset([
            'token' => $validated['codigo'],
            'correo' => $validated['correo'],
            'password' => $validated['password'],
        ], function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
            ])->save();

            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'codigo' => ['El código de recuperación es inválido o expiró.'],
            ]);
        }

        return response()->json([
            'message' => 'La contraseña se restableció correctamente.',
        ]);
    }
}
