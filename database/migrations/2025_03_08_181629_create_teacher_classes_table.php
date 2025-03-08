<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up()
{
    Schema::create('teacher_class', function (Blueprint $table) {
        $table->foreignId('teacher_id')
              ->constrained()
              ->cascadeOnDelete();
        
        $table->foreignId('class_id') // Matches Classes model's table 'classes'
              ->constrained() // Assumes the table name is 'classes'
              ->cascadeOnDelete();
        
        $table->primary(['teacher_id', 'class_id']);
        
        // Optional: Add metadata
        // $table->string('role')->default('main_teacher');
    });
}

    public function down()
    {
        Schema::dropIfExists('teacher_class');
    }
};
?>