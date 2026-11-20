<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JunteApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_junte_list_contains_created_and_participated_juntes_only(): void
    {
        $user = User::factory()->create(['correo' => 'user@example.com']);
        $otherUser = User::factory()->create(['correo' => 'other@example.com']);

        $createdJunteId = $this->createJunte($user, 'Creado');
        $participatedJunteId = $this->createJunte($otherUser, 'Participante');
        $this->createJunte($otherUser, 'Privado');

        DB::table('junte_usuario')->insert([
            'junte_id' => $participatedJunteId,
            'usuario_id' => $user->id,
            'rol' => 'invitado',
            'estado_rsvp' => 'confirmado',
            'cuota_pagada' => false,
        ]);

        $this->actingAs($user)
            ->getJson('/api/juntes')
            ->assertOk()
            ->assertJsonCount(2, 'juntes')
            ->assertJsonFragment(['id' => $createdJunteId, 'titulo' => 'Creado'])
            ->assertJsonFragment(['id' => $participatedJunteId, 'titulo' => 'Participante'])
            ->assertJsonMissing(['titulo' => 'Privado']);
    }

    public function test_junte_detail_contains_surveys_location_and_participants(): void
    {
        $user = User::factory()->create(['correo' => 'user@example.com']);
        $organizer = User::factory()->create([
            'nombre' => 'Organizadora',
            'correo' => 'organizer@example.com',
        ]);
        $junteId = $this->createJunte($organizer, 'Cena', [
            'ubicacion_final' => 'Plaza Central',
            'latitud' => -34.6037,
            'longitud' => -58.3816,
        ]);

        DB::table('junte_usuario')->insert([
            'junte_id' => $junteId,
            'usuario_id' => $user->id,
            'rol' => 'invitado',
            'estado_rsvp' => 'confirmado',
            'cuota_pagada' => true,
        ]);

        $encuestaId = DB::table('encuestas')->insertGetId([
            'junte_id' => $junteId,
            'tipo' => 'ubicacion',
            'titulo' => '¿Dónde nos juntamos?',
            'estado' => 'abierta',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $opcionId = DB::table('opciones_encuesta')->insertGetId([
            'encuesta_id' => $encuestaId,
            'valor' => 'Plaza Central',
        ]);
        DB::table('votos')->insert([
            'usuario_id' => $user->id,
            'opcion_id' => $opcionId,
        ]);

        $this->actingAs($user)
            ->getJson("/api/juntes/{$junteId}")
            ->assertOk()
            ->assertJsonPath('junte.ubicacion.nombre', 'Plaza Central')
            ->assertJsonPath('junte.organizador.nombre', 'Organizadora')
            ->assertJsonPath('junte.encuestas.0.titulo', '¿Dónde nos juntamos?')
            ->assertJsonPath('junte.encuestas.0.opciones.0.id', $opcionId)
            ->assertJsonPath('junte.encuestas.0.opciones.0.votos', 1)
            ->assertJsonPath('junte.participantes.0.nombre', $user->nombre)
            ->assertJsonPath('junte.participantes.0.cuota_pagada', true);
    }

    public function test_junte_detail_is_not_available_to_non_participants(): void
    {
        $user = User::factory()->create(['correo' => 'user@example.com']);
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $junteId = $this->createJunte($organizer, 'Privado');

        $this->actingAs($user)
            ->getJson("/api/juntes/{$junteId}")
            ->assertNotFound();
    }

    public function test_invited_guest_can_confirm_and_change_their_rsvp(): void
    {
        $guest = User::factory()->create(['correo' => 'guest@example.com']);
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $junteId = $this->createJunte($organizer, 'Cena');
        DB::table('junte_usuario')->insert([
            'junte_id' => $junteId,
            'usuario_id' => $guest->id,
            'rol' => 'invitado',
            'estado_rsvp' => 'pendiente',
            'cuota_pagada' => false,
        ]);

        $this->actingAs($guest)
            ->putJson("/api/juntes/{$junteId}/rsvp", ['estado_rsvp' => 'confirmado'])
            ->assertOk()
            ->assertJsonPath('junte.mi_estado_rsvp', 'confirmado')
            ->assertJsonPath('junte.can_rsvp', true)
            ->assertJsonPath('junte.participantes.0.estado_rsvp', 'confirmado');

        $this->assertDatabaseHas('junte_usuario', [
            'junte_id' => $junteId,
            'usuario_id' => $guest->id,
            'estado_rsvp' => 'confirmado',
        ]);

        $this->putJson("/api/juntes/{$junteId}/rsvp", ['estado_rsvp' => 'rechazado'])
            ->assertOk()
            ->assertJsonPath('junte.mi_estado_rsvp', 'rechazado')
            ->assertJsonPath('junte.participantes.0.estado_rsvp', 'rechazado');

        $this->assertDatabaseHas('junte_usuario', [
            'junte_id' => $junteId,
            'usuario_id' => $guest->id,
            'estado_rsvp' => 'rechazado',
        ]);
    }

    public function test_rsvp_requires_an_invitation_and_an_active_junte(): void
    {
        $guest = User::factory()->create(['correo' => 'guest@example.com']);
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $junteId = $this->createJunte($organizer, 'Cena');

        $this->actingAs($guest)
            ->putJson("/api/juntes/{$junteId}/rsvp", ['estado_rsvp' => 'confirmado'])
            ->assertForbidden();

        DB::table('junte_usuario')->insert([
            'junte_id' => $junteId,
            'usuario_id' => $guest->id,
            'rol' => 'invitado',
            'estado_rsvp' => 'pendiente',
            'cuota_pagada' => false,
        ]);
        DB::table('juntes')->where('id', $junteId)->update(['estado' => 'cancelado']);

        $this->putJson("/api/juntes/{$junteId}/rsvp", ['estado_rsvp' => 'confirmado'])
            ->assertStatus(409);

        $this->assertDatabaseHas('junte_usuario', [
            'junte_id' => $junteId,
            'usuario_id' => $guest->id,
            'estado_rsvp' => 'pendiente',
        ]);
    }

    public function test_rsvp_rejects_organizers_and_invalid_statuses(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $junteId = $this->createJunte($organizer, 'Cena');

        $this->actingAs($organizer)
            ->putJson("/api/juntes/{$junteId}/rsvp", ['estado_rsvp' => 'rechazado'])
            ->assertForbidden();

        $guest = User::factory()->create(['correo' => 'guest@example.com']);
        DB::table('junte_usuario')->insert([
            'junte_id' => $junteId,
            'usuario_id' => $guest->id,
            'rol' => 'invitado',
            'estado_rsvp' => 'pendiente',
            'cuota_pagada' => false,
        ]);

        $this->actingAs($guest)
            ->putJson("/api/juntes/{$junteId}/rsvp", ['estado_rsvp' => 'pendiente'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado_rsvp');
    }

    public function test_junte_routes_require_authentication(): void
    {
        $this->getJson('/api/juntes')->assertUnauthorized();
        $this->getJson('/api/juntes/1')->assertUnauthorized();
        $this->postJson('/api/juntes/1/cancelar')->assertUnauthorized();
        $this->putJson('/api/juntes/1/rsvp', ['estado_rsvp' => 'confirmado'])->assertUnauthorized();
        $this->getJson('/api/alertas')->assertUnauthorized();
    }

    public function test_organizer_can_create_junte_without_pivot_timestamps(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);

        $response = $this->actingAs($organizer)
            ->postJson('/api/juntes', [
                'titulo' => 'Cena',
                'descripcion' => 'Una cena entre amigos',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Junte creado exitosamente.');

        $junteId = $response->json('junte_id');

        $this->assertDatabaseHas('juntes', [
            'id' => $junteId,
            'creador_id' => $organizer->id,
            'titulo' => 'Cena',
        ]);
        $this->assertDatabaseHas('junte_usuario', [
            'junte_id' => $junteId,
            'usuario_id' => $organizer->id,
            'rol' => 'organizador',
            'estado_rsvp' => 'confirmado',
        ]);
    }

    public function test_organizer_can_update_junte_location_with_openstreetmap_coordinates(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $junteId = $this->createJunte($organizer, 'Cena');

        $this->actingAs($organizer)
            ->putJson("/api/juntes/{$junteId}", [
                'ubicacion_final' => 'Parque Central',
                'latitud' => -12.0464,
                'longitud' => -77.0428,
            ])
            ->assertOk()
            ->assertJsonPath('junte.ubicacion_final', 'Parque Central');

        $this->assertDatabaseHas('juntes', [
            'id' => $junteId,
            'ubicacion_final' => 'Parque Central',
            'latitud' => -12.0464,
            'longitud' => -77.0428,
        ]);
    }

    public function test_organizer_can_cancel_junte_and_notify_all_members(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $member = User::factory()->create(['correo' => 'member@example.com']);
        $outsider = User::factory()->create(['correo' => 'outsider@example.com']);
        $junteId = $this->createJunte($organizer, 'Cena');

        DB::table('junte_usuario')->insert([
            [
                'junte_id' => $junteId,
                'usuario_id' => $organizer->id,
                'rol' => 'organizador',
                'estado_rsvp' => 'confirmado',
                'cuota_pagada' => false,
            ],
            [
                'junte_id' => $junteId,
                'usuario_id' => $member->id,
                'rol' => 'invitado',
                'estado_rsvp' => 'confirmado',
                'cuota_pagada' => false,
            ],
        ]);
        $surveyId = DB::table('encuestas')->insertGetId([
            'junte_id' => $junteId,
            'tipo' => 'fecha',
            'titulo' => '¿Cuándo?',
            'estado' => 'abierta',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($organizer)
            ->postJson("/api/juntes/{$junteId}/cancelar")
            ->assertOk()
            ->assertJsonPath('estado', 'cancelado');

        $this->assertDatabaseHas('juntes', [
            'id' => $junteId,
            'estado' => 'cancelado',
        ]);
        $this->assertDatabaseHas('encuestas', [
            'id' => $surveyId,
            'estado' => 'cerrada',
        ]);
        $this->assertDatabaseCount('junte_alertas', 2);
        $this->assertDatabaseHas('junte_alertas', [
            'junte_id' => $junteId,
            'usuario_id' => $member->id,
            'tipo' => 'cancelacion',
        ]);
        $this->assertDatabaseHas('junte_alertas', [
            'junte_id' => $junteId,
            'usuario_id' => $organizer->id,
            'tipo' => 'cancelacion',
        ]);
        $this->assertDatabaseMissing('junte_alertas', [
            'junte_id' => $junteId,
            'usuario_id' => $outsider->id,
        ]);

        $this->actingAs($member)
            ->getJson('/api/alertas')
            ->assertOk()
            ->assertJsonCount(1, 'alertas')
            ->assertJsonPath('alertas.0.junte_id', $junteId)
            ->assertJsonPath('alertas.0.tipo', 'cancelacion')
            ->assertJsonPath('alertas.0.junte_titulo', 'Cena');
    }

    public function test_non_organizer_cannot_cancel_junte(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $member = User::factory()->create(['correo' => 'member@example.com']);
        $junteId = $this->createJunte($organizer, 'Cena');
        DB::table('junte_usuario')->insert([
            'junte_id' => $junteId,
            'usuario_id' => $member->id,
            'rol' => 'invitado',
            'estado_rsvp' => 'confirmado',
            'cuota_pagada' => false,
        ]);

        $this->actingAs($member)
            ->postJson("/api/juntes/{$junteId}/cancelar")
            ->assertForbidden();

        $this->assertDatabaseHas('juntes', [
            'id' => $junteId,
            'estado' => 'activo',
        ]);
        $this->assertDatabaseCount('junte_alertas', 0);
    }

    public function test_cancelation_is_idempotent_and_canceled_junte_cannot_be_edited(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $junteId = $this->createJunte($organizer, 'Cena');

        $this->actingAs($organizer)
            ->postJson("/api/juntes/{$junteId}/cancelar")
            ->assertOk();
        $this->postJson("/api/juntes/{$junteId}/cancelar")
            ->assertOk()
            ->assertJsonPath('estado', 'cancelado');
        $this->putJson("/api/juntes/{$junteId}", ['titulo' => 'Cambio'])
            ->assertStatus(409);

        $this->assertDatabaseCount('junte_alertas', 1);
        $this->assertDatabaseHas('juntes', [
            'id' => $junteId,
            'titulo' => 'Cena',
            'estado' => 'cancelado',
        ]);
    }

    private function createJunte(User $organizer, string $title, array $attributes = []): int
    {
        return (int) DB::table('juntes')->insertGetId(array_merge([
            'creador_id' => $organizer->id,
            'titulo' => $title,
            'descripcion' => 'Descripción',
            'estado' => 'activo',
            'fecha_final' => null,
            'ubicacion_final' => null,
            'latitud' => null,
            'longitud' => null,
            'gasto_total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
