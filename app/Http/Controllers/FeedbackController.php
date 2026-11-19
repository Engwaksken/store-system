<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Services\FeedbackService;
use App\Support\ListPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Everyone can send feedback and follow their own; admins see all of it, reply and set its status. */
class FeedbackController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $manage = Gate::allows('manage-feedback');
        $base = fn () => Feedback::visibleTo($user);
        $stats = [
            'total' => $base()->count(),
            'new' => $base()->where('status', 'new')->count(),
            'in_review' => $base()->where('status', 'in_review')->count(),
            'resolved' => $base()->whereIn('status', ['resolved', 'closed'])->count(),
        ];
        $query = $base()->with('user:id,name')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->latest('id');

        return ListPage::make($request, $query, 'Feedback')
            ->period('created_at')
            ->search(['subject', 'message'], 'Subject or message')
            ->columns([
                'Reference' => fn ($f) => $f->reference(), 'Sent' => fn ($f) => $f->created_at->toDateTimeString(),
                'From' => fn ($f) => $f->user?->name, 'Category' => fn ($f) => $f->categoryLabel(), 'Subject' => fn ($f) => $f->subject,
                'Rating' => fn ($f) => $f->rating, 'Source' => fn ($f) => $f->source, 'Status' => fn ($f) => $f->statusLabel(),
            ])
            ->respond(fn ($items, $list) => view('feedback.index', ['items' => $items, 'list' => $list, 'stats' => $stats, 'manage' => $manage]));
    }

    public function create()
    {
        return view('feedback.create', ['feedback' => new Feedback]);
    }

    public function store(Request $request, FeedbackService $service)
    {
        $feedback = $service->submit($request->user(), $request->validate(FeedbackService::submitRules()), 'web', null, mb_substr((string) $request->userAgent(), 0, 120) ?: null);

        return redirect()->route('feedback.show', $feedback)->with('status', 'Thank you. Your feedback was sent to the administrators.');
    }

    public function show(Feedback $feedback)
    {
        Gate::authorize('view-feedback', $feedback);

        return view('feedback.show', ['feedback' => $feedback->load('user', 'responder'), 'manage' => Gate::allows('manage-feedback')]);
    }

    public function respond(Request $request, Feedback $feedback, FeedbackService $service)
    {
        Gate::authorize('manage-feedback');
        $data = $request->validate(FeedbackService::respondRules());
        $service->respond($request->user(), $feedback, $data['status'], $data['response'] ?? null);

        return redirect()->route('feedback.show', $feedback)->with('status', 'Feedback updated.');
    }

    /** Owner/Admin: delete the ticked feedback. */
    public function bulk(Request $request)
    {
        \Illuminate\Support\Facades\Gate::authorize('manage-feedback');
        $items = \App\Models\Feedback::whereIn('id', $this->bulkIds($request))->orderBy('id')->get();

        return $this->bulkApply($items, fn ($f) => $f->reference(), fn ($f) => $this->deleteUnused($f, $f->reference()), 'feedback item', 'deleted');
    }
}
