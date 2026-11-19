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
        // El acceso solo se necesita en modo lectura
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('dbhost');
            $table->string('dbport');
            $table->string('dbuser');
            $table->string('dbpass');
            $table->string('dbname');
            $table->enum('isactive',['Y','N'])->default('Y');
            $table->timestamps();
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
