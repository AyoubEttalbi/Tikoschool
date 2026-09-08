<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalized name keys for duplicate detection. Nullable so the migration
     * applies instantly on big tables; students:backfill-name-keys fills
     * legacy rows, and the model boot keeps new/updated rows stamped.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // 255, not 100: transliteration can expand (œ→oe), and the input
            // cap counts characters while the key counts bytes-ish output.
            $table->string('firstNameKey', 255)->nullable()->after('lastName');
            $table->string('lastNameKey', 255)->nullable()->after('firstNameKey');
            $table->index(['firstNameKey', 'lastNameKey'], 'students_name_keys_index');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_name_keys_index');
            $table->dropColumn(['firstNameKey', 'lastNameKey']);
        });
    }
};
