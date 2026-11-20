<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amistades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('amigo_id')->constrained('users')->onDelete('cascade');
            $table->enum('estado', ['pendiente', 'aceptado', 'rechazado'])->default('pendiente');
            $table->timestamps();

            $table->unique(['usuario_id', 'amigo_id']);
            $table->index(['usuario_id', 'estado']);
            $table->index(['amigo_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amistades');
    }
};
