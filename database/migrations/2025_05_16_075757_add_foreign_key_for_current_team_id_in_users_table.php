<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('
            update users u
            left join organizations o on u.current_team_id = o.id
            set u.current_team_id = null
            where u.current_team_id is not null and o.id is null
        ');
        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('current_team_id', 'organizations_current_organization_id_foreign')
                ->references('id')
                ->on('organizations')
                ->onDelete('restrict')
                ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign('organizations_current_organization_id_foreign');
        });
    }
};
