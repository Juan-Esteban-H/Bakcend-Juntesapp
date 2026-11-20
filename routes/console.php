<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('juntes:archive-expired', function () {
    $count = DB::table('juntes')
        ->where('estado', 'activo')
        ->whereNotNull('fecha_final')
        ->where('fecha_final', '<', now())
        ->update([
            'estado' => 'finalizado',
            'updated_at' => now(),
        ]);

    $this->info("Juntes archivados: {$count}");
})->purpose('Finaliza automáticamente los juntes cuya fecha ya pasó');

Schedule::command('juntes:archive-expired')->everyTenMinutes();
