<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Storekeepers now see and issue only to their assigned projects (Project::visibleTo).
 * Until now they saw every project, so existing storekeepers with no project assignments are ticked on every
 * existing project: nothing changes for them on deploy, and the admin can untick projects they do not serve.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keepers = DB::table('users')->where('role', 'storekeeper')
            ->whereNotExists(fn ($q) => $q->from('project_user')->whereColumn('project_user.user_id', 'users.id'))
            ->pluck('id');
        $projects = DB::table('projects')->pluck('id');
        $now = now();
        foreach ($keepers as $userId) {
            $rows = $projects->map(fn ($projectId) => ['project_id' => $projectId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($rows->chunk(500) as $chunk) {
                DB::table('project_user')->insert($chunk->all());
            }
        }
    }

    public function down(): void
    {
        // Assignments are kept: the admin may have edited them since.
    }
};
