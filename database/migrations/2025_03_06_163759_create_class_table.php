<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique(); // Matches the `name` field in the data
            $table->string('level', 50); // Matches the `level` field in the data
            $table->integer('numStudents'); // Matches the `numStudents` field in the data
            $table->integer('numTeachers'); // Matches the `numTeachers` field in the data
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
