<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-activity');
        $query = ActivityLog::with('user')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->action.'%'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()));

        if (in_array($request->query('format'), ['csv', 'xlsx'], true)) {
            $headers = ['#', 'When', 'User', 'Action', 'Subject', 'Description', 'IP', 'Hash'];
            $rows = (clone $query)->orderBy('id')->limit(50000)->get()->map(fn ($l) => [
                $l->id, $l->created_at->format('Y-m-d H:i:s'), $l->user?->name ?? 'system', $l->action,
                trim(($l->subject_type ?? '').' '.($l->subject_id ?? '')), $l->description, $l->ip_address, $l->hash,
            ]);
            ActivityLog::record('audit.exported', 'Activity log exported ('.$rows->count().' rows, '.$request->query('format').')');
            $name = 'activity-log-'.now()->format('Ymd-His');
            if ($request->query('format') === 'xlsx') {
                return response()->download(\App\Support\XlsxWriter::write('Activity log', $headers, $rows, [0]), $name.'.xlsx')->deleteFileAfterSend();
            }

            return response()->streamDownload(function () use ($headers, $rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "ï»¿");
                fputcsv($out, $headers);
                foreach ($rows as $r) {
                    fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@	]/', $v) ? "'".$v : $v, $r));
                }
                fclose($out);
            }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $logs = $query->latest('id')->paginate(50)->withQueryString();

        return view('activity', compact('logs'));
    }
}
