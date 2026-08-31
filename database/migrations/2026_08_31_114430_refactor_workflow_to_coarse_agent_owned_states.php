<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collapse the fine-grained workflow state machine into the coarse, Agent-owned vocabulary:
     * pending, running, waiting, completed, failed, cancelled.
     */
    public function up(): void
    {
        Schema::table('work_requests', function (Blueprint $table) {
            $table->json('last_handoff')->nullable()->after('failure_reason');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->json('last_handoff')->nullable()->after('blocked_reason');
        });

        $workRequestStatusMap = [
            'submitted' => 'pending',
            'processing' => 'running',
            'planned' => 'completed',
            'completed' => 'completed',
            'blocked' => 'failed',
            'failed' => 'failed',
        ];

        foreach ($workRequestStatusMap as $from => $to) {
            DB::table('work_requests')->where('status', $from)->update(['status' => $to]);
        }

        $taskStatusMap = [
            'queued' => 'pending',
            'coding' => 'running',
            'ready_for_qa' => 'waiting',
            'qa_reviewing' => 'running',
            'changes_required' => 'waiting',
            'manual_browser_check_required' => 'waiting',
            'approved' => 'waiting',
            'committing' => 'running',
            'integrating' => 'running',
            'done' => 'completed',
            'blocked' => 'failed',
        ];

        foreach ($taskStatusMap as $from => $to) {
            DB::table('tasks')->where('status', $from)->update(['status' => $to]);
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('blocked_from_status');
        });

        // Column-level defaults are left as-is (no doctrine/dbal dependency for ->change());
        // App\Models\Task and App\Models\WorkRequest set the 'pending' default via $attributes instead.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_requests', function (Blueprint $table) {
            $table->dropColumn('last_handoff');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('blocked_from_status', 32)->nullable()->after('blocked_reason');
            $table->dropColumn('last_handoff');
        });
    }
};
