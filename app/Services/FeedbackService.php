<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Feedback from the web app and the mobile app: admins are alerted, and the sender is told when it is answered. */
class FeedbackService
{
    public function __construct(private WorkflowNotifier $notifier)
    {
    }

    public static function submitRules(): array
    {
        return [
            'category' => ['required', Rule::in(array_keys(Feedback::CATEGORIES))],
            'subject' => 'required|string|max:150',
            'message' => 'required|string|max:5000',
            'rating' => 'nullable|integer|between:1,5',
        ];
    }

    public static function respondRules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(Feedback::STATUSES))],
            'response' => 'nullable|string|max:5000',
        ];
    }

    public function submit(User $by, array $data, string $source = 'web', ?string $appVersion = null, ?string $device = null): Feedback
    {
        return DB::transaction(function () use ($by, $data, $source, $appVersion, $device) {
            $feedback = Feedback::create([
                'user_id' => $by->id,
                'category' => $data['category'],
                'subject' => trim($data['subject']),
                'message' => trim($data['message']),
                'rating' => $data['rating'] ?? null,
                'source' => $source,
                'app_version' => $appVersion ? mb_substr($appVersion, 0, 40) : null,
                'device' => $device ? mb_substr($device, 0, 120) : null,
            ]);
            ActivityLog::record('feedback.submitted', "Feedback {$feedback->reference()} sent ({$feedback->categoryLabel()}, {$source})", $feedback);
            $this->notifier->send(
                $this->notifier->role(Role::Admin)->where('id', '!=', $by->id),
                "New feedback: {$feedback->subject}",
                "{$by->name} sent feedback {$feedback->reference()} ({$feedback->categoryLabel()}) from the ".($source === 'mobile' ? 'mobile app' : 'web app').'.',
                route('feedback.show', $feedback), 'Open feedback',
                $feedback->category === 'bug' ? 'warn' : 'info',
            );

            return $feedback;
        });
    }

    public function respond(User $by, Feedback $feedback, string $status, ?string $response): Feedback
    {
        return DB::transaction(function () use ($by, $feedback, $status, $response) {
            $response = $response !== null && trim($response) !== '' ? trim($response) : null;
            $replyChanged = $response !== null && $response !== $feedback->response;
            $statusChanged = $status !== $feedback->status;
            $feedback->status = $status;
            if ($replyChanged) {
                $feedback->fill(['response' => $response, 'responded_by' => $by->id, 'responded_at' => now()]);
            }
            $feedback->save();
            if (! $replyChanged && ! $statusChanged) {
                return $feedback;
            }
            ActivityLog::record('feedback.updated', "Feedback {$feedback->reference()} set to {$feedback->statusLabel()}".($replyChanged ? ' with a reply' : ''), $feedback);
            if ($feedback->user_id !== $by->id) {
                $this->notifier->send(
                    User::whereKey($feedback->user_id)->get(),
                    ($replyChanged ? 'Reply to your feedback: ' : 'Feedback updated: ').$feedback->subject,
                    $replyChanged
                        ? "{$by->name} replied to your feedback {$feedback->reference()}: ".mb_strimwidth($response, 0, 300, '...')
                        : "Your feedback {$feedback->reference()} is now {$feedback->statusLabel()}.",
                    route('feedback.show', $feedback), 'Open feedback',
                    in_array($status, ['resolved', 'closed'], true) ? 'ok' : 'info',
                );
            }

            return $feedback;
        });
    }
}
