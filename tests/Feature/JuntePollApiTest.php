<?php

namespace Tests\Feature;

use App\Events\PollResultsUpdated;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class JuntePollApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_member_can_create_a_date_poll_with_multiple_time_options(): void
    {
        Event::fake([PollResultsUpdated::class]);
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $junteId = $this->createJunte($organizer);
        $options = $this->futureOptions();

        $response = $this->actingAs($organizer)
            ->postJson("/api/juntes/{$junteId}/encuestas/fechas", [
                'titulo' => '¿Qué fecha les queda mejor?',
                'opciones' => $options,
            ]);

        $response->assertOk()
            ->assertJsonPath('junte.encuestas.0.tipo', 'fecha')
            ->assertJsonPath('junte.encuestas.0.estado', 'abierta')
            ->assertJsonCount(2, 'junte.encuestas.0.opciones')
            ->assertJsonPath('junte.encuestas.0.puede_votar', true)
            ->assertJsonPath('junte.encuestas.0.opciones.0.fecha_inicio', $options[0]['fecha_inicio']);

        $this->assertDatabaseCount('encuestas', 1);
        $this->assertDatabaseCount('opciones_encuesta', 2);
        Event::assertDispatched(
            PollResultsUpdated::class,
            fn (PollResultsUpdated $event): bool => $event->junteId === $junteId
                && $event->pollId === $response->json('junte.encuestas.0.id'),
        );
    }

    public function test_member_can_propose_an_option_and_change_their_single_vote(): void
    {
        Event::fake([PollResultsUpdated::class]);
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $member = User::factory()->create(['correo' => 'member@example.com']);
        $junteId = $this->createJunte($organizer);
        DB::table('junte_usuario')->insert([
            'junte_id' => $junteId,
            'usuario_id' => $member->id,
            'rol' => 'invitado',
            'estado_rsvp' => 'confirmado',
            'cuota_pagada' => false,
        ]);
        $options = $this->futureOptions();
        $surveyResponse = $this->actingAs($organizer)->postJson(
            "/api/juntes/{$junteId}/encuestas/fechas",
            ['titulo' => 'Fecha', 'opciones' => $options],
        );
        $surveyId = $surveyResponse->json('junte.encuestas.0.id');
        $optionIds = collect($surveyResponse->json('junte.encuestas.0.opciones'))
            ->pluck('id')
            ->all();

        $addedOption = [
            'valor' => 'Nueva propuesta',
            'fecha_inicio' => now()->addDays(4)->setTime(19, 0)->toDateTimeString(),
            'fecha_fin' => now()->addDays(4)->setTime(21, 0)->toDateTimeString(),
        ];
        $this->actingAs($member)
            ->postJson("/api/encuestas/{$surveyId}/opciones", $addedOption)
            ->assertOk()
            ->assertJsonCount(3, 'junte.encuestas.0.opciones');

        $this->putJson("/api/encuestas/{$surveyId}/voto", [
            'opcion_id' => $optionIds[0],
        ])->assertOk()
            ->assertJsonPath('junte.encuestas.0.opciones.0.mi_voto', true);
        $this->putJson("/api/encuestas/{$surveyId}/voto", [
            'opcion_id' => $optionIds[1],
        ])->assertOk()
            ->assertJsonPath('junte.encuestas.0.opciones.0.mi_voto', false)
            ->assertJsonPath('junte.encuestas.0.opciones.1.mi_voto', true)
            ->assertJsonPath('junte.encuestas.0.opciones.1.votos', 1);

        $this->assertDatabaseCount('votos', 1);
        $this->assertDatabaseHas('votos', [
            'usuario_id' => $member->id,
            'opcion_id' => $optionIds[1],
        ]);
        Event::assertDispatched(
            PollResultsUpdated::class,
            fn (PollResultsUpdated $event): bool => $event->junteId === $junteId
                && $event->pollId === $surveyId,
        );
        $broadcastData = (new PollResultsUpdated($junteId, $surveyId))->broadcastWith();
        $this->assertSame(
            [0, 1, 0],
            array_column($broadcastData['opciones'], 'votos'),
        );
    }

    public function test_voting_rejects_non_members_and_votes_after_closing(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $outsider = User::factory()->create(['correo' => 'outsider@example.com']);
        $junteId = $this->createJunte($organizer);
        $response = $this->actingAs($organizer)->postJson(
            "/api/juntes/{$junteId}/encuestas/fechas",
            ['titulo' => 'Fecha', 'opciones' => $this->futureOptions()],
        );
        $surveyId = $response->json('junte.encuestas.0.id');
        $optionId = $response->json('junte.encuestas.0.opciones.0.id');

        $this->actingAs($outsider)
            ->putJson("/api/encuestas/{$surveyId}/voto", ['opcion_id' => $optionId])
            ->assertNotFound();

        DB::table('encuestas')->where('id', $surveyId)->update([
            'cierra_en' => now()->subMinute(),
        ]);
        $this->actingAs($organizer)
            ->putJson("/api/encuestas/{$surveyId}/voto", ['opcion_id' => $optionId])
            ->assertStatus(409);
        $this->getJson("/api/juntes/{$junteId}")
            ->assertOk()
            ->assertJsonPath('junte.encuestas.0.estado', 'cerrada');
    }

    public function test_only_organizer_can_close_the_date_poll(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $member = User::factory()->create(['correo' => 'member@example.com']);
        $junteId = $this->createJunte($organizer);
        DB::table('junte_usuario')->insert([
            'junte_id' => $junteId,
            'usuario_id' => $member->id,
            'rol' => 'invitado',
            'estado_rsvp' => 'pendiente',
            'cuota_pagada' => false,
        ]);
        $response = $this->actingAs($organizer)->postJson(
            "/api/juntes/{$junteId}/encuestas/fechas",
            ['titulo' => 'Fecha', 'opciones' => $this->futureOptions()],
        );
        $surveyId = $response->json('junte.encuestas.0.id');

        $this->actingAs($member)
            ->putJson("/api/encuestas/{$surveyId}/cerrar")
            ->assertForbidden();
        $this->actingAs($organizer)
            ->putJson("/api/encuestas/{$surveyId}/cerrar")
            ->assertOk()
            ->assertJsonPath('junte.encuestas.0.estado', 'cerrada')
            ->assertJsonPath('junte.encuestas.0.puede_votar', false);
    }

    public function test_creator_can_run_a_location_poll_and_set_the_winning_place_official(): void
    {
        Event::fake([PollResultsUpdated::class]);
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $member = User::factory()->create(['correo' => 'member@example.com']);
        $junteId = $this->createJunte($organizer);
        DB::table('junte_usuario')->insert([
            'junte_id' => $junteId,
            'usuario_id' => $member->id,
            'rol' => 'invitado',
            'estado_rsvp' => 'confirmado',
            'cuota_pagada' => false,
        ]);
        $options = [
            [
                'valor' => 'Parque Central · La Paz',
                'latitud' => -16.5,
                'longitud' => -68.15,
            ],
            [
                'valor' => 'Plaza Abaroa · La Paz',
                'latitud' => -16.51,
                'longitud' => -68.12,
            ],
        ];

        $this->actingAs($member)
            ->postJson("/api/juntes/{$junteId}/encuestas/ubicaciones", [
                'titulo' => '¿Dónde nos juntamos?',
                'opciones' => $options,
            ])
            ->assertForbidden();

        $created = $this->actingAs($organizer)
            ->postJson("/api/juntes/{$junteId}/encuestas/ubicaciones", [
                'titulo' => '¿Dónde nos juntamos?',
                'opciones' => $options,
            ])
            ->assertOk()
            ->assertJsonPath('junte.encuestas.0.tipo', 'ubicacion')
            ->assertJsonPath('junte.encuestas.0.puede_votar', true)
            ->assertJsonPath('junte.encuestas.0.opciones.0.latitud', -16.5)
            ->assertJsonCount(2, 'junte.encuestas.0.opciones');
        $pollId = $created->json('junte.encuestas.0.id');
        $optionIds = collect($created->json('junte.encuestas.0.opciones'))
            ->pluck('id')
            ->all();

        $this->actingAs($member)
            ->putJson("/api/encuestas/{$pollId}/voto", [
                'opcion_id' => $optionIds[0],
            ])
            ->assertOk()
            ->assertJsonPath('junte.encuestas.0.opciones.0.votos', 1);

        $this->actingAs($organizer)
            ->putJson("/api/encuestas/{$pollId}/ubicacion-oficial", [
                'opcion_id' => $optionIds[0],
            ])
            ->assertStatus(409);

        $this->actingAs($organizer)
            ->putJson("/api/encuestas/{$pollId}/cerrar")
            ->assertOk()
            ->assertJsonPath('junte.encuestas.0.puede_establecer_ubicacion', true);

        $this->actingAs($member)
            ->putJson("/api/encuestas/{$pollId}/ubicacion-oficial", [
                'opcion_id' => $optionIds[0],
            ])
            ->assertForbidden();

        $this->actingAs($organizer)
            ->putJson("/api/encuestas/{$pollId}/ubicacion-oficial", [
                'opcion_id' => $optionIds[1],
            ])
            ->assertStatus(409);

        $this->actingAs($organizer)
            ->putJson("/api/encuestas/{$pollId}/ubicacion-oficial", [
                'opcion_id' => $optionIds[0],
            ])
            ->assertOk()
            ->assertJsonPath('junte.ubicacion_final', 'Parque Central · La Paz')
            ->assertJsonPath('junte.latitud', -16.5)
            ->assertJsonPath('junte.longitud', -68.15)
            ->assertJsonPath('junte.encuestas.0.ubicacion_establecida', true)
            ->assertJsonPath('junte.encuestas.0.opciones.0.es_ubicacion_oficial', true);

        $this->assertDatabaseHas('juntes', [
            'id' => $junteId,
            'ubicacion_final' => 'Parque Central · La Paz',
            'latitud' => -16.5,
            'longitud' => -68.15,
        ]);
        Event::assertDispatched(
            PollResultsUpdated::class,
            fn (PollResultsUpdated $event): bool => $event->junteId === $junteId
                && $event->pollId === $pollId,
        );
    }

    public function test_location_poll_rejects_duplicate_options_and_coordinates(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $junteId = $this->createJunte($organizer);

        $this->actingAs($organizer)
            ->postJson("/api/juntes/{$junteId}/encuestas/ubicaciones", [
                'titulo' => '¿Dónde nos juntamos?',
                'opciones' => [
                    ['valor' => 'Parque Central', 'latitud' => -16.5, 'longitud' => -68.15],
                    ['valor' => 'Parque Central', 'latitud' => -16.51, 'longitud' => -68.12],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opciones.1.valor');

        $this->actingAs($organizer)
            ->postJson("/api/juntes/{$junteId}/encuestas/ubicaciones", [
                'titulo' => '¿Dónde nos juntamos?',
                'opciones' => [
                    ['valor' => 'Parque Central', 'latitud' => -16.5, 'longitud' => -68.15],
                    ['valor' => 'Plaza Abaroa', 'latitud' => -16.5, 'longitud' => -68.15],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opciones.1');
    }

    private function futureOptions(): array
    {
        return collect([2, 3])->map(fn (int $days): array => [
            'valor' => 'Opción en '.$days.' días',
            'fecha_inicio' => now()->addDays($days)->setTime(18, 0)->toDateTimeString(),
            'fecha_fin' => now()->addDays($days)->setTime(20, 0)->toDateTimeString(),
        ])->all();
    }

    private function createJunte(User $organizer): int
    {
        return (int) DB::table('juntes')->insertGetId([
            'creador_id' => $organizer->id,
            'titulo' => 'Junte de prueba',
            'descripcion' => 'Encuesta de fechas',
            'estado' => 'activo',
            'fecha_final' => null,
            'ubicacion_final' => null,
            'latitud' => null,
            'longitud' => null,
            'gasto_total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
