<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('junte_usuario', function (Blueprint $table) {
            $table->foreignId('junte_id')->constrained('juntes')->onDelete('cascade');
            $table->foreignId('usuario_id')->constrained('users')->onDelete('cascade');
            $table->enum('rol', ['organizador', 'invitado'])->default('invitado');
            $table->enum('estado_rsvp', ['pendiente', 'confirmado', 'rechazado'])->default('pendiente');
            $table->boolean('cuota_pagada')->default(false);
            $table->primary(['junte_id', 'usuario_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('junte_usuario');
    }
};
