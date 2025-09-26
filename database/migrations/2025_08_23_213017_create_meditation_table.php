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
        Schema::create('meditations', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('email');
            $table->date('birth_date')->nullable();
            $table->string('style')->nullable();
            $table->text('goals')->nullable();
            $table->text('challenges')->nullable();
            $table->longText('script_text')->nullable();
            $table->text('voice_url')->nullable();
            $table->text('music_url')->nullable();
            $table->text('music_task_id')->nullable();
            $table->text('meditation_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meditations');
    }
};
