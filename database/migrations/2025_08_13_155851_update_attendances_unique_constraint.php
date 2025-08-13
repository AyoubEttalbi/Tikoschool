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
        Schema::table('attendances', function (Blueprint $table) {
            // Drop the old unique constraint
            $table->dropUnique(['student_id', 'classId', 'date']);
            
            // Add new unique constraint that includes teacher_id and subject
            $table->unique(['student_id', 'classId', 'date', 'teacher_id', 'subject'], 'attendances_student_class_date_teacher_subject_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Drop the new unique constraint
            $table->dropUnique('attendances_student_class_date_teacher_subject_unique');
            
            // Restore the old unique constraint
            $table->unique(['student_id', 'classId', 'date']);
        });
    }
};
