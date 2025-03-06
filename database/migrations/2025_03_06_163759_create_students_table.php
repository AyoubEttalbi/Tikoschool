<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('firstName', 100); // Updated
            $table->string('lastName', 100); // Updated
            $table->date('dateOfBirth'); // Updated
            $table->date('billingDate'); // Updated
            $table->text('address')->nullable();
            $table->string('guardianName', 255)->nullable(); // Updated
            $table->string('CIN', 50)->unique();
            $table->string('phoneNumber', 20)->nullable(); // Updated
            $table->string('email', 255)->unique();
            $table->string('massarCode', 50)->unique(); // Updated
            $table->unsignedBigInteger('levelId')->nullable(); // Updated
            $table->string('class', 50)->nullable(); // Added
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->boolean('assurance')->default(false);
            $table->string('profile_image')->nullable();
            $table->timestamps(); // `createdAt` is automatically handled by `timestamps()`

            // Foreign key constraint
            $table->foreign('levelId')->references('id')->on('levels')->onDelete('set null'); // Updated
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};