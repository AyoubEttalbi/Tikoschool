<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An off switch for one student's guardian.
 *
 * The alternative was a notification_preferences table keyed on recipient and type. There
 * is no parent login and no parent-facing UI in this product, so nothing would ever have
 * written to it — and a table that looks like a control but is never populated is worse
 * than no table, because the next reader assumes preferences are being honoured.
 *
 * What is actually needed, and will be within months: a parent asks to stop, or a number
 * bounces repeatedly, and staff need to silence it without deleting the number. One
 * column on the student does that. camelCase because `students` is a camelCase table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->boolean('notifyGuardian')->default(true)->after('guardianNumber');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('notifyGuardian');
        });
    }
};
