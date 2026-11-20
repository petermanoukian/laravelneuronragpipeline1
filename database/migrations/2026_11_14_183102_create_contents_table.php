<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            
            // 3 explicit nullable URL fields
            $table->string('url_one')->nullable();
            $table->string('url_two')->nullable();
            $table->string('url_three')->nullable();
            
            // Explicit file, image, and PDF fields
            $table->string('file_path')->nullable();
            $table->string('image_path')->nullable();
            $table->string('pdf_path')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('contents');
    }
};