<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('attendances', function (Blueprint $table) {
            // Drop the old unique index if it exists
            try {
                $table->dropUnique('attendances_student_id_classid_date_unique');
            } catch (\Exception $e) {}
            // Add the new unique index
            $table->unique(['student_id', 'classId', 'teacher_id', 'subject', 'date'], 'attendances_unique_full');
        });
    }

    public function down()
    {
        Schema::table('attendances', function (Blueprint $table) {
            try {
                $table->dropUnique('attendances_unique_full');
            } catch (\Exception $e) {}
            $table->unique(['student_id', 'classId', 'date'], 'attendances_student_id_classid_date_unique');
        });
    }
};
