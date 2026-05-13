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
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->string('annotatable_type');
            $table->unsignedBigInteger('annotatable_id');
            $table->string('annotation_id');
            $table->json('geometry');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['annotatable_type', 'annotatable_id']);
            $table->unique(['annotatable_type', 'annotatable_id', 'annotation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};
