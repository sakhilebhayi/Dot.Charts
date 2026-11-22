<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-54: the link from a Charts account to the person's InfoDot user id (the OIDC `sub`). Additive and nullable: every
 * pre-existing account stays unlinked until its owner proves the email address by the emailed claim code. Unique, so one
 * InfoDot person can never be attached to two Charts accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'infodot_user_uuid')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('infodot_user_uuid', 64)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['infodot_user_uuid']);
            $table->dropColumn('infodot_user_uuid');
        });
    }
};
