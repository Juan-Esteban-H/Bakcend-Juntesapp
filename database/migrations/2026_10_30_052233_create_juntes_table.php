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
        Schema::create('juntes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creador_id')->constrained('users')->onDelete('cascade');
            $table->string('titulo', 150);
            $table->text('descripcion')->nullable();
            $table->enum('estado', ['activo', 'cancelado', 'finalizado'])->default('activo');
            $table->dateTime('fecha_final')->nullable();
            $table->string('ubicacion_final')->nullable();
            $table->decimal('latitud', 10, 8)->nullable();
            $table->decimal('longitud', 11, 8)->nullable();
            $table->decimal('gasto_total', 10, 2)->default(0.00);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('juntes');
    }
};
