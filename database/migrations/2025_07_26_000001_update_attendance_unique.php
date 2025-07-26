<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign('attendances_student_id_foreign');
            $table->dropForeign('attendances_classid_foreign');
            // Drop the unique index
            $table->dropUnique('attendances_student_id_classid_date_unique');
            // Add the new unique index
            $table->unique(['student_id', 'classId', 'teacher_id', 'subject', 'date'], 'attendances_unique_full');
            // Re-add the foreign keys
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('classId')->references('id')->on('classes')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['student_id']);
            $table->dropForeign(['classId']);
            $table->dropUnique('attendances_unique_full');
            $table->unique(['student_id', 'classId', 'date'], 'attendances_student_id_classid_date_unique');
            $table->foreign('student_id')->references('id')->on('students');
            $table->foreign('classId')->references('id')->on('classes')->onDelete('cascade');
        });
    }
};
