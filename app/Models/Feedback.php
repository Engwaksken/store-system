<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

/** A comment, bug report or idea sent by a user from the web app or the mobile app; admins reply and track it. */
class Feedback extends Model
{
    protected $table = 'feedback';

    public const CATEGORIES = ['bug' => 'Problem / bug', 'suggestion' => 'Suggestion', 'question' => 'Question', 'praise' => 'Praise', 'other' => 'Other'];

    public const STATUSES = ['new' => 'New', 'in_review' => 'In review', 'resolved' => 'Resolved', 'closed' => 'Closed'];

    /** Badge tone per status, shared by the web views and the API (`status_tone`). */
    public const TONES = ['new' => 'pending', 'in_review' => 'partial', 'resolved' => 'approved', 'closed' => 'muted'];

    protected $fillable = ['user_id', 'category', 'subject', 'message', 'rating', 'source', 'app_version', 'device', 'status', 'response', 'responded_by', 'responded_at'];

    protected $attributes = ['status' => 'new', 'source' => 'web'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'responded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /** Feedback managers see everything; everyone else sees only what they sent. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return Gate::forUser($user)->allows('manage-feedback') ? $q : $q->where('user_id', $user->id);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::TONES[$this->status] ?? 'muted';
    }

    public function reference(): string
    {
        return 'FB-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}
