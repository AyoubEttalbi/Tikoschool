<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up()
{
    Schema::create('teacher_subject', function (Blueprint $table) {
        $table->foreignId('teacher_id')
              ->constrained()
              ->cascadeOnDelete();
        
        $table->foreignId('subject_id')
              ->constrained()
              ->cascadeOnDelete();
        
        $table->primary(['teacher_id', 'subject_id']);
        
        // Optional: Add timestamps or custom columns
        // $table->timestamps();
        // $table->string('teaching_level')->nullable();
    });
}
    public function down()
    {
        Schema::dropIfExists('teacher_subject');
    }
};
?>