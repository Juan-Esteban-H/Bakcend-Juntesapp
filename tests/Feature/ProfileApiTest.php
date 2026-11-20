<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->postJson('/api/profile', ['name' => 'Valeria'])->assertUnauthorized();
    }

    public function test_profile_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create([
            'nombre' => 'Valeria',
            'correo' => 'valeria@example.com',
            'phone' => '555-0101',
            'bio' => 'Me gusta organizar encuentros.',
        ]);

        $this->actingAs($user)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'phone', 'bio', 'avatar_url'],
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Valeria')
            ->assertJsonPath('user.email', 'valeria@example.com')
            ->assertJsonPath('user.phone', '555-0101')
            ->assertJsonPath('user.bio', 'Me gusta organizar encuentros.');
    }

    public function test_profile_can_be_updated_using_multipart_method_override_and_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'nombre' => 'Valeria',
            'correo' => 'valeria@example.com',
        ]);
        $avatar = UploadedFile::fake()->image('avatar.png');

        $response = $this->actingAs($user)
            ->post('/api/profile', [
                '_method' => 'PUT',
                'nombre' => 'Valeria Nueva',
                'phone' => '555-0102',
                'bio' => 'Me encanta conocer lugares nuevos.',
                'avatar' => $avatar,
            ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('user.name', 'Valeria Nueva')
            ->assertJsonPath('user.phone', '555-0102')
            ->assertJsonPath('user.bio', 'Me encanta conocer lugares nuevos.');

        $user->refresh();
        Storage::disk('public')->assertExists($user->avatar_url);
        $this->assertSame(
            Storage::disk('public')->url($user->avatar_url),
            $response->json('user.avatar_url'),
        );
    }

    public function test_profile_accepts_name_as_an_alias_for_nombre(): void
    {
        $user = User::factory()->create([
            'nombre' => 'Valeria',
            'correo' => 'valeria@example.com',
        ]);

        $this->actingAs($user)
            ->putJson('/api/profile', ['name' => 'Vale'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Vale');

        $this->assertSame('Vale', $user->fresh()->nombre);
    }

    public function test_profile_clears_blank_phone_and_bio_to_null(): void
    {
        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
            'phone' => '555-0101',
            'bio' => 'Una biografía.',
        ]);

        $this->actingAs($user)
            ->putJson('/api/profile', [
                'phone' => '   ',
                'bio' => '',
            ])
            ->assertOk()
            ->assertJsonPath('user.phone', null)
            ->assertJsonPath('user.bio', null);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone' => null,
            'bio' => null,
        ]);
    }

    public function test_profile_rejects_biography_over_five_hundred_characters(): void
    {
        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
        ]);

        $this->actingAs($user)
            ->putJson('/api/profile', ['bio' => str_repeat('a', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bio');
    }

    public function test_profile_rejects_avatars_over_five_megabytes(): void
    {
        Storage::fake('public');
        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
        ]);

        $this->actingAs($user)
            ->post('/api/profile', [
                '_method' => 'PUT',
                'avatar' => UploadedFile::fake()->image('large.png')->size(5121),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    public function test_profile_accepts_an_avatar_at_the_five_megabyte_limit(): void
    {
        Storage::fake('public');
        $user = User::factory()->create([
            'correo' => 'valeria@example.com',
        ]);

        $response = $this->actingAs($user)
            ->post('/api/profile', [
                '_method' => 'PUT',
                'avatar' => UploadedFile::fake()->image('limit.png')->size(5120),
            ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('user.name', $user->nombre);
        Storage::disk('public')->assertExists($user->fresh()->avatar_url);
    }
}
