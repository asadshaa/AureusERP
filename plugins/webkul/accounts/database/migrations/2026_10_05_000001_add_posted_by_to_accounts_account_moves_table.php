<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts_account_moves', function (Blueprint $table) {
            $table->foreignId('posted_by_id')->nullable()->after('creator_id')->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('posted_before');
        });
    }

    public function down(): void
    {
        Schema::table('accounts_account_moves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('posted_by_id');
            $table->dropColumn('posted_at');
        });
    }
};
