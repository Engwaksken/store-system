<?php

namespace App\Http\Controllers\Api;

use App\Models\Feedback;
use App\Services\FeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Feedback from the mobile app: everyone sends and follows their own; admins see all and reply. */
class FeedbackController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Feedback::visibleTo($user)->with('user', 'responder')
            ->when($request->query('scope') === 'mine', fn ($q) => $q->where('user_id', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('subject', 'like', $this->like($request->query('q')))
                ->orWhere('message', 'like', $this->like($request->query('q')))))
            ->latest('id');

        return $this->paginated($request, $query, fn ($f) => Present::feedback($f, $user));
    }

    public function options(): JsonResponse
    {
        $pairs = fn (array $map) => collect($map)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();

        return response()->json(['categories' => $pairs(Feedback::CATEGORIES), 'statuses' => $pairs(Feedback::STATUSES)]);
    }

    public function store(Request $request, FeedbackService $service): JsonResponse
    {
        $data = $request->validate(FeedbackService::submitRules() + [
            'app_version' => 'nullable|string|max:40',
            'device' => 'nullable|string|max:120',
        ]);
        $feedback = $service->submit($request->user(), $data, 'mobile', $data['app_version'] ?? null, $data['device'] ?? null);

        return response()->json(['data' => Present::feedback($feedback->load('user', 'responder'), $request->user())], 201);
    }

    public function show(Request $request, Feedback $feedback): JsonResponse
    {
        Gate::authorize('view-feedback', $feedback);

        return response()->json(['data' => Present::feedback($feedback->load('user', 'responder'), $request->user())]);
    }

    public function respond(Request $request, Feedback $feedback, FeedbackService $service): JsonResponse
    {
        Gate::authorize('manage-feedback');
        $data = $request->validate(FeedbackService::respondRules());
        $service->respond($request->user(), $feedback, $data['status'], $data['response'] ?? null);

        return response()->json(['data' => Present::feedback($feedback->fresh()->load('user', 'responder'), $request->user())]);
    }
}
