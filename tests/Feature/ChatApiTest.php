<?php

namespace Tests\Feature;

use App\Events\ChatMessageSent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_member_can_read_and_send_a_sanitized_chat_message(): void
    {
        Event::fake([ChatMessageSent::class]);

        $organizer = User::factory()->create([
            'nombre' => 'Ana',
            'correo' => 'organizer@example.com',
        ]);
        $member = User::factory()->create([
            'nombre' => 'Luis',
            'correo' => 'member@example.com',
        ]);
        $junteId = $this->createJunte($organizer, 'Cena');
        DB::table('junte_usuario')->insert([
            'junte_id' => $junteId,
            'usuario_id' => $member->id,
            'rol' => 'invitado',
            'estado_rsvp' => 'confirmado',
            'cuota_pagada' => false,
        ]);

        $this->actingAs($member)
            ->postJson("/api/juntes/{$junteId}/mensajes", [
                'mensaje' => "  Nos vemos <script>alert('x')</script>  ",
            ])
            ->assertCreated()
            ->assertJsonPath('mensaje.mensaje', "Nos vemos alert('x')")
            ->assertJsonPath('mensaje.usuario.nombre', 'Luis');

        $this->assertDatabaseHas('mensajes_chat', [
            'junte_id' => $junteId,
            'usuario_id' => $member->id,
            'mensaje' => "Nos vemos alert('x')",
        ]);

        $this->actingAs($member)
            ->getJson("/api/juntes/{$junteId}/mensajes")
            ->assertOk()
            ->assertJsonCount(1, 'mensajes')
            ->assertJsonPath('mensajes.0.usuario.nombre', 'Luis');

        Event::assertDispatched(
            ChatMessageSent::class,
            fn (ChatMessageSent $event): bool => $event->junteId === $junteId
                && $event->messageId > 0,
        );
    }

    public function test_chat_rejects_non_members_and_empty_sanitized_messages(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $outsider = User::factory()->create(['correo' => 'outsider@example.com']);
        $junteId = $this->createJunte($organizer, 'Privado');

        $this->actingAs($outsider)
            ->getJson("/api/juntes/{$junteId}/mensajes")
            ->assertNotFound();

        $this->actingAs($organizer)
            ->postJson("/api/juntes/{$junteId}/mensajes", [
                'mensaje' => '<script></script>',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mensaje');
    }

    public function test_chat_routes_require_authentication_and_messages_are_limited(): void
    {
        $organizer = User::factory()->create(['correo' => 'organizer@example.com']);
        $junteId = $this->createJunte($organizer, 'Chat');

        $this->getJson("/api/juntes/{$junteId}/mensajes")->assertUnauthorized();
        $this->postJson("/api/juntes/{$junteId}/mensajes", [
            'mensaje' => str_repeat('a', 2001),
        ])->assertUnauthorized();

        $this->actingAs($organizer)
            ->postJson("/api/juntes/{$junteId}/mensajes", [
                'mensaje' => str_repeat('a', 2001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mensaje');
    }
}
