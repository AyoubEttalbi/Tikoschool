<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admins had no profile-image support: students/teachers/assistants carry
        // profile_image on their own tables, but admins live only on users. Same
        // convention as the other tables: a logical relative path such as
        // "admins/<hex>.webp", or a legacy absolute URL for pre-existing values.
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_image')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_image');
        });
    }
};
