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
            $table->string('dbhost')->nullable();
            $table->string('dbport')->nullable();
            $table->string('dbuser')->nullable();
            $table->string('dbpass')->nullable();
            $table->string('dbname')->nullable();
            $table->string('url')->nullable();
            $table->string('smtp_host')->nullable();
            $table->string('smtp_port')->nullable();
            $table->string('smtp_tls')->nullable();
            $table->string('smtp_user')->nullable();
            $table->string('smtp_pass')->nullable();
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
