<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ListPage;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-activity');
        $query = ActivityLog::with('user')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->action.'%'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')));

        $list = ListPage::make($request, $query, 'Activity log')
            ->period('created_at', 'When')->search(['description', 'ip_address'], 'Description or IP address')->perPage(50)
            ->columns([
                '#' => fn ($l) => $l->id, 'When' => fn ($l) => $l->created_at->format('Y-m-d H:i:s'), 'User' => fn ($l) => $l->user?->name ?? 'system',
                'Action' => fn ($l) => $l->action, 'Description' => fn ($l) => $l->description, 'IP' => fn ($l) => $l->ip_address,
            ]);

        // Full CSV / Excel: every column (incl. the hash chain), oldest first, up to 50 000 rows. Every export is itself audited.
        $format = $request->query('format');
        if (in_array($format, ['csv', 'xlsx'], true)) {
            $headers = ['#', 'When', 'User', 'Action', 'Subject', 'Description', 'IP', 'Hash'];
            $rows = (clone $list->query())->orderBy('id')->limit(50000)->get()->map(fn ($l) => [
                $l->id, $l->created_at->format('Y-m-d H:i:s'), $l->user?->name ?? 'system', $l->action,
                trim(($l->subject_type ?? '').' '.($l->subject_id ?? '')), $l->description, $l->ip_address, $l->hash,
            ]);
            ActivityLog::record('audit.exported', 'Activity log exported ('.$rows->count().' rows, '.$format.')');
            $name = 'activity-log-'.now()->format('Ymd-His');
            if ($format === 'xlsx') {
                return response()->download(XlsxWriter::write('Activity log', $headers, $rows, [0]), $name.'.xlsx')->deleteFileAfterSend();
            }

            return ListPage::csv($name.'.csv', $headers, $rows);
        }
        if ($format === 'pdf') {
            $count = min((clone $list->query())->count(), ListPage::EXPORT_LIMIT);
            ActivityLog::record('audit.exported', 'Activity log exported ('.$count.' rows, pdf)');
        }
        $list->query()->latest('id');

        $stats = [
            'total' => ActivityLog::count(),
            'today' => ActivityLog::where('created_at', '>=', now()->startOfDay())->count(),
            'week' => ActivityLog::where('created_at', '>=', now()->subDays(7))->count(),
            'users' => ActivityLog::where('created_at', '>=', now()->subDays(7))->distinct()->count('user_id'),
        ];

        return $list->respond(fn ($logs, $list) => view('activity', [
            'logs' => $logs, 'list' => $list, 'stats' => $stats, 'users' => User::orderBy('name')->get(['id', 'name']),
        ]));
    }
}
