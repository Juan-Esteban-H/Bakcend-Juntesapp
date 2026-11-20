<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    public function show(Request $request, int $junteId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $junte = $this->memberJunte($junteId, $user);

        return response()->json($this->payload($junteId, $junte));
    }

    public function update(Request $request, int $junteId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $junte = $this->organizerJunte($junteId, $user);

        $validated = $request->validate([
            'gasto_total' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'gastos' => ['sometimes', 'array', 'max:50'],
            'gastos.*.concepto' => ['required_with:gastos', 'string', 'max:150'],
            'gastos.*.monto' => ['required_with:gastos', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        DB::transaction(function () use ($junteId, $validated): void {
            DB::table('juntes')->where('id', $junteId)->update([
                'gasto_total' => $validated['gasto_total'],
                'updated_at' => now(),
            ]);

            if (array_key_exists('gastos', $validated)) {
                DB::table('junte_gastos')->where('junte_id', $junteId)->delete();
                foreach ($validated['gastos'] as $expense) {
                    DB::table('junte_gastos')->insert([
                        'junte_id' => $junteId,
                        'concepto' => trim($expense['concepto']),
                        'monto' => $expense['monto'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        $updated = DB::table('juntes')->where('id', $junteId)->first();

        return response()->json($this->payload($junteId, $updated));
    }

    public function updatePayment(Request $request, int $junteId, int $usuarioId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->organizerJunte($junteId, $user);

        $validated = $request->validate([
            'cuota_pagada' => ['required', 'boolean'],
        ]);

        $updated = DB::table('junte_usuario')
            ->where('junte_id', $junteId)
            ->where('usuario_id', $usuarioId)
            ->where('estado_rsvp', 'confirmado')
            ->update(['cuota_pagada' => $validated['cuota_pagada']]);

        abort_if($updated === 0, 404, 'La persona no es un asistente confirmado.');

        $junte = DB::table('juntes')->where('id', $junteId)->first();

        return response()->json($this->payload($junteId, $junte));
    }

    private function payload(int $junteId, object $junte): array
    {
        $confirmed = DB::table('junte_usuario')
            ->join('users', 'users.id', '=', 'junte_usuario.usuario_id')
            ->where('junte_usuario.junte_id', $junteId)
            ->where('junte_usuario.estado_rsvp', 'confirmado')
            ->select([
                'users.id',
                'users.nombre',
                'users.avatar_url',
                'junte_usuario.cuota_pagada',
            ])
            ->orderBy('users.nombre')
            ->get()
            ->map(fn (object $member): array => [
                'id' => (int) $member->id,
                'nombre' => $member->nombre,
                'avatar_url' => $member->avatar_url,
                'cuota_pagada' => (bool) $member->cuota_pagada,
            ])
            ->values();

        $expenses = DB::table('junte_gastos')
            ->where('junte_id', $junteId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $expense): array => [
                'id' => (int) $expense->id,
                'concepto' => $expense->concepto,
                'monto' => (float) $expense->monto,
            ])
            ->values();

        $total = (float) $junte->gasto_total;
        $confirmedCount = $confirmed->count();
        $share = $confirmedCount > 0 ? round($total / $confirmedCount, 2) : 0.0;

        return [
            'gasto_total' => $total,
            'cuota_por_confirmado' => $share,
            'asistentes_confirmados' => $confirmedCount,
            'gastos' => $expenses,
            'asistentes' => $confirmed,
        ];
    }

    private function memberJunte(int $junteId, User $user): object
    {
        $junte = DB::table('juntes')->where('id', $junteId)->first();
        abort_if($junte === null, 404);

        $member = (int) $junte->creador_id === (int) $user->id
            || DB::table('junte_usuario')
                ->where('junte_id', $junteId)
                ->where('usuario_id', $user->id)
                ->exists();
        abort_unless($member, 404);

        return $junte;
    }

    private function organizerJunte(int $junteId, User $user): object
    {
        $junte = DB::table('juntes')->where('id', $junteId)->first();
        abort_if($junte === null, 404);

        $organizer = (int) $junte->creador_id === (int) $user->id
            || DB::table('junte_usuario')
                ->where('junte_id', $junteId)
                ->where('usuario_id', $user->id)
                ->where('rol', 'organizador')
                ->exists();
        abort_unless($organizer, 403);
        abort_unless($junte->estado === 'activo', 409);

        return $junte;
    }
}
