<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table): void {
            $table->string('submission_key', 100)->nullable()->after('retry_of_id');
            $table->unique(['user_id', 'submission_key']);
        });
    }
};
