<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

Broadcast::channel('junte.{junteId}', function (User $user, int $junteId): bool {
    return DB::table('juntes')
        ->where('id', $junteId)
        ->where(function ($query) use ($user): void {
            $query->where('creador_id', $user->id)
                ->orWhereExists(function ($membership) use ($user): void {
                    $membership->selectRaw('1')
                        ->from('junte_usuario')
                        ->whereColumn('junte_usuario.junte_id', 'juntes.id')
                        ->where('junte_usuario.usuario_id', $user->id);
                });
        })
        ->exists();
});
