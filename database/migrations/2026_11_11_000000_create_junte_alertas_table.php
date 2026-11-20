<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('junte_alertas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('junte_id')->constrained('juntes')->onDelete('cascade');
            $table->foreignId('usuario_id')->constrained('users')->onDelete('cascade');
            $table->string('tipo', 50); // 'reprogramacion', 'edicion_logistica', etc.
            $table->string('titulo', 150);
            $table->text('mensaje');
            $table->json('datos_anteriores')->nullable();
            $table->json('datos_nuevos')->nullable();
            $table->timestamps();

            $table->index('junte_id');
            $table->index('tipo');
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('junte_alertas');
    }
};