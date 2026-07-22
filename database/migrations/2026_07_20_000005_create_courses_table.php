<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course catalog (spec §2.5). Fee is a whole-rupee integer. capacity NULL =
 * unlimited seats. Inactive courses are hidden from the registration wizard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('title', 160);
            $table->foreignId('trainer_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->unsignedInteger('fee');            // base fee PKR (> 0)
            $table->unsignedInteger('capacity')->nullable(); // NULL = unlimited
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
