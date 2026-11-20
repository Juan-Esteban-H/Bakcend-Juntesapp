<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mensajes_chat', function (Blueprint $table) {
            $table->string('archivo_nombre')->nullable()->after('archivo_url');
            $table->string('archivo_tipo', 100)->nullable()->after('archivo_nombre');
            $table->unsignedBigInteger('archivo_tamano')->nullable()->after('archivo_tipo');
        });

        Schema::create('junte_gastos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('junte_id')->constrained('juntes')->onDelete('cascade');
            $table->string('concepto', 150);
            $table->decimal('monto', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('junte_gastos');

        Schema::table('mensajes_chat', function (Blueprint $table) {
            $table->dropColumn(['archivo_nombre', 'archivo_tipo', 'archivo_tamano']);
        });
    }
};
