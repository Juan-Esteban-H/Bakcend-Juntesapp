<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordResetCode;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_accepts_the_frontend_name_and_email_fields(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Brajhan',
            'email' => 'brajhanpro@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
            ])
            ->assertJsonPath('user.name', 'Brajhan')
            ->assertJsonPath('user.email', 'brajhanpro@gmail.com');

        $this->assertDatabaseHas('users', [
            'nombre' => 'Brajhan',
            'correo' => 'brajhanpro@gmail.com',
        ]);
    }

    public function test_register_saves_an_optional_phone_without_accepting_blank_values(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Nakamura',
            'email' => 'nakamura@example.com',
            'phone' => ' +591 71234567 ',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.phone', '+591 71234567');

        $this->assertDatabaseHas('users', [
            'correo' => 'nakamura@example.com',
            'phone' => '+591 71234567',
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Sin teléfono',
            'email' => 'sin-telefono@example.com',
            'phone' => '   ',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('user.phone', null);

        $this->assertDatabaseHas('users', [
            'correo' => 'sin-telefono@example.com',
            'phone' => null,
        ]);
    }

    public function test_register_continues_to_accept_database_field_names(): void
    {
        $this->postJson('/api/auth/register', [
            'nombre' => 'Valeria',
            'correo' => 'valeria@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('user.name', 'Valeria')
            ->assertJsonPath('user.email', 'valeria@example.com');
    }

    public function test_login_returns_a_sanctum_token_and_api_user(): void
    {
        $user = User::factory()->create([
            'nombre' => 'Valeria',
            'correo' => 'valeria@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'correo' => $user->correo,
            'password' => 'correct-password',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'phone', 'avatar_url'],
            ])
            ->assertJsonPath('user.name', 'Valeria')
            ->assertJsonPath('user.email', 'valeria@example.com')
            ->assertJsonMissingPath('user.password');

        $this->getJson('/api/profile', [
            'Authorization' => 'Bearer '.$response->json('token'),
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_accepts_the_frontend_email_field(): void
    {
        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
            'password' => 'correct-password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->correo,
            'password' => 'correct-password',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email']])
            ->assertJsonPath('user.email', $user->correo);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'correo' => 'valeria@example.com',
            'password' => 'correct-password',
        ]);

        $this->postJson('/api/auth/login', [
            'correo' => 'valeria@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('correo');
    }

    public function test_google_login_creates_a_user_from_a_verified_google_identity(): void
    {
        config(['services.google.client_id' => 'google-client-id']);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response($this->googleClaims(), 200),
        ]);

        $response = $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-id-token',
            'correo' => 'google@example.com',
            'nombre' => 'Google User',
            'avatar_url' => 'https://example.com/avatar.png',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'avatar_url'],
                'token',
            ])
            ->assertJsonPath('user.name', 'Google User')
            ->assertJsonPath('user.email', 'google@example.com')
            ->assertJsonPath('user.avatar_url', 'https://example.com/avatar.png');

        $user = User::where('correo', 'google@example.com')->firstOrFail();
        $this->assertSame('Google User', $user->nombre);
        $this->assertNotEmpty($user->password);
        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'google_auth_token',
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_google_login_adds_an_avatar_to_an_existing_user_only_if_missing(): void
    {
        config(['services.google.client_id' => 'google-client-id']);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response($this->googleClaims(), 200),
        ]);

        $user = User::factory()->create([
            'nombre' => 'Existing User',
            'correo' => 'google@example.com',
            'avatar_url' => null,
        ]);

        $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-id-token',
            'correo' => 'google@example.com',
            'nombre' => 'Updated Name',
            'avatar_url' => 'https://example.com/avatar.png',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Existing User')
            ->assertJsonPath('user.avatar_url', 'https://example.com/avatar.png');

        $this->assertSame('https://example.com/avatar.png', $user->fresh()->avatar_url);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_login_does_not_replace_an_existing_avatar(): void
    {
        config(['services.google.client_id' => 'google-client-id']);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response($this->googleClaims(), 200),
        ]);

        $user = User::factory()->create([
            'correo' => 'google@example.com',
            'avatar_url' => 'https://example.com/current.png',
        ]);

        $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-id-token',
            'correo' => 'google@example.com',
            'nombre' => 'Google User',
            'avatar_url' => 'https://example.com/new.png',
        ])
            ->assertOk()
            ->assertJsonPath('user.avatar_url', 'https://example.com/current.png');
    }

    public function test_google_login_rejects_a_token_with_the_wrong_audience(): void
    {
        config(['services.google.client_id' => 'google-client-id']);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response(
                array_merge($this->googleClaims(), ['aud' => 'another-client-id']),
                200,
            ),
        ]);

        $this->postJson('/api/auth/google', [
            'id_token' => 'invalid-google-id-token',
            'correo' => 'google@example.com',
            'nombre' => 'Google User',
            'avatar_url' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertDatabaseMissing('users', ['correo' => 'google@example.com']);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_google_login_rejects_a_token_with_an_unverified_email(): void
    {
        config(['services.google.client_id' => 'google-client-id']);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response(
                array_merge($this->googleClaims(), ['email_verified' => 'false']),
                200,
            ),
        ]);

        $this->postJson('/api/auth/google', [
            'id_token' => 'unverified-google-id-token',
            'correo' => 'google@example.com',
            'nombre' => 'Google User',
            'avatar_url' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertDatabaseMissing('users', ['correo' => 'google@example.com']);
    }

    public function test_google_login_rejects_a_request_email_that_differs_from_the_verified_email(): void
    {
        config(['services.google.client_id' => 'google-client-id']);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response($this->googleClaims(), 200),
        ]);

        $this->postJson('/api/auth/google', [
            'id_token' => 'valid-google-id-token',
            'correo' => 'victim@example.com',
            'nombre' => 'Google User',
            'avatar_url' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');

        $this->assertDatabaseMissing('users', ['correo' => 'victim@example.com']);
    }

    public function test_google_login_returns_service_unavailable_when_google_cannot_be_reached(): void
    {
        config(['services.google.client_id' => 'google-client-id']);
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::failedConnection(),
        ]);

        $this->postJson('/api/auth/google', [
            'id_token' => 'google-id-token',
            'correo' => 'google@example.com',
            'nombre' => 'Google User',
            'avatar_url' => null,
        ])->assertServiceUnavailable();

        $this->assertDatabaseMissing('users', ['correo' => 'google@example.com']);
    }

    public function test_google_login_requires_an_id_token(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/api/auth/google', [
            'correo' => 'google@example.com',
            'nombre' => 'Google User',
            'avatar_url' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('id_token');
    }

    /**
     * @return array<string, bool|int|string>
     */
    private function googleClaims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => 'google-client-id',
            'email_verified' => 'true',
            'email' => 'google@example.com',
            'sub' => 'google-user-id',
            'exp' => now()->addHour()->timestamp,
        ];
    }

    public function test_forgot_password_always_returns_a_generic_response(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
        ]);

        $unknownEmailResponse = $this->postJson('/api/auth/forgot-password', [
            'correo' => 'unknown@example.com',
        ]);
        $knownEmailResponse = $this->postJson('/api/auth/forgot-password', [
            'correo' => $user->correo,
        ]);

        $unknownEmailResponse->assertOk()->assertExactJson($knownEmailResponse->json());
        $knownEmailResponse->assertExactJson([
            'message' => 'Si el correo está registrado, recibirás un código para restablecer tu contraseña.',
        ]);

        Notification::assertSentTo(
            $user,
            PasswordResetCode::class,
            function (PasswordResetCode $notification) use ($user): bool {
                $resetRecord = DB::table('password_reset_tokens')
                    ->where('email', $user->correo)
                    ->first();

                return preg_match('/^\d{6}$/', $notification->code) === 1
                    && $resetRecord !== null
                    && Hash::check($notification->code, $resetRecord->token);
            },
        );
    }

    public function test_reset_password_updates_the_password_and_consumes_the_code(): void
    {
        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
            'password' => 'old-password',
        ]);
        $code = '012345';

        DB::table('password_reset_tokens')->insert([
            'email' => $user->correo,
            'token' => Hash::make($code),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'codigo' => $code,
            'correo' => $user->correo,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'La contraseña se restableció correctamente.');

        $this->assertTrue(password_verify('new-password', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->correo]);

        $this->postJson('/api/auth/login', [
            'correo' => $user->correo,
            'password' => 'new-password',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email']])
            ->assertJsonPath('user.email', $user->correo);
    }

    public function test_reset_password_rejects_an_invalid_code(): void
    {
        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->correo,
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'codigo' => '12345',
            'correo' => $user->correo,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('codigo');

        $this->postJson('/api/auth/reset-password', [
            'codigo' => '654321',
            'correo' => 'valeria@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('codigo');

        $this->assertFalse(password_verify('new-password', $user->fresh()->password));
    }

    public function test_reset_password_rejects_an_expired_code(): void
    {
        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->correo,
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $this->travel(11)->minutes();

        $this->postJson('/api/auth/reset-password', [
            'codigo' => '123456',
            'correo' => $user->correo,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('codigo');
    }

    public function test_forgot_password_does_not_send_another_code_during_the_cooldown(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
        ]);

        $this->postJson('/api/auth/forgot-password', ['correo' => $user->correo])->assertOk();
        $firstCodeHash = DB::table('password_reset_tokens')->where('email', $user->correo)->value('token');

        $this->postJson('/api/auth/forgot-password', ['correo' => $user->correo])->assertOk();

        $this->assertSame(
            $firstCodeHash,
            DB::table('password_reset_tokens')->where('email', $user->correo)->value('token'),
        );
        Notification::assertSentToTimes($user, PasswordResetCode::class, 1);
    }

    public function test_forgot_password_logs_mail_delivery_failure_without_exposing_account_or_code(): void
    {
        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
        ]);
        $dispatcher = $this->mock(Dispatcher::class);
        $dispatcher->shouldReceive('send')
            ->once()
            ->withArgs(fn (User $notifiable, PasswordResetCode $notification): bool => $notifiable->is($user)
                && preg_match('/^\d{6}$/', $notification->code) === 1)
            ->andThrow(new TransportException('Do not write this sensitive error to logs.'));

        Log::shouldReceive('error')
            ->once()
            ->with('Password reset email delivery failed.', [
                'exception' => TransportException::class,
                'mailer' => (string) config('mail.default'),
            ]);

        $this->postJson('/api/auth/forgot-password', [
            'correo' => $user->correo,
        ])->assertOk()
            ->assertExactJson([
                'message' => 'Si el correo está registrado, recibirás un código para restablecer tu contraseña.',
            ]);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->correo]);
    }
}
