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
        // `zone`: mine | influence | outside (App\Enums\ControlZone).
        Schema::create('control_map_entries', function (Blueprint $table) {
            $table->id();
            $table->morphs('plannable');
            $table->string('zone', 10);
            $table->string('text');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('control_map_entries');
    }
};
