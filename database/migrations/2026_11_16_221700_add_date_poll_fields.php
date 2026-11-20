<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encuestas', function (Blueprint $table): void {
            $table->dateTime('cierra_en')->nullable();
        });

        Schema::table('opciones_encuesta', function (Blueprint $table): void {
            $table->dateTime('fecha_inicio')->nullable();
            $table->dateTime('fecha_fin')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opciones_encuesta', function (Blueprint $table): void {
            $table->dropColumn(['fecha_inicio', 'fecha_fin']);
        });

        Schema::table('encuestas', function (Blueprint $table): void {
            $table->dropColumn('cierra_en');
        });
    }
};
