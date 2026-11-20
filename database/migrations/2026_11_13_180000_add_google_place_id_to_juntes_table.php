<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('juntes', function (Blueprint $table): void {
            $table->string('google_place_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('juntes', function (Blueprint $table): void {
            $table->dropColumn('google_place_id');
        });
    }
};
