@extends('layouts.app')
@section('title', 'Send feedback')
@section('content')
<div class="page-head"><div><h1>Send feedback</h1><p>Report a problem, ask a question or suggest an improvement. The administrators are notified and you are told when they reply.</p></div>
    <a class="btn secondary" href="{{ route('feedback.index') }}">My feedback</a></div>
<form method="POST" action="{{ route('feedback.store') }}" class="card" style="max-width:760px">@csrf
    <div class="form-grid">
        <div class="field"><label for="fb_category">Category</label><select id="fb_category" name="category" required>
            @foreach(\App\Models\Feedback::CATEGORIES as $k => $label)<option value="{{ $k }}" @selected(old('category', 'suggestion') === $k)>{{ $label }}</option>@endforeach
        </select>@error('category')<div class="err">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="fb_rating">How is the system working for you? (optional)</label><select id="fb_rating" name="rating"><option value="">No rating</option>
            @foreach([5 => 'Excellent', 4 => 'Good', 3 => 'Okay', 2 => 'Poor', 1 => 'Very poor'] as $n => $label)<option value="{{ $n }}" @selected((string) old('rating') === (string) $n)>{{ str_repeat('★', $n) }} {{ $label }}</option>@endforeach
        </select>@error('rating')<div class="err">{{ $message }}</div>@enderror</div>
    </div>
    <div class="field"><label for="fb_subject">Subject</label><input id="fb_subject" type="text" name="subject" maxlength="150" required value="{{ old('subject') }}" placeholder="e.g. Approvals page is slow on my phone">@error('subject')<div class="err">{{ $message }}</div>@enderror</div>
    <div class="field"><label for="fb_message">Details</label><textarea id="fb_message" name="message" maxlength="5000" rows="6" required placeholder="What happened, where, and what you expected.">{{ old('message') }}</textarea>@error('message')<div class="err">{{ $message }}</div>@enderror</div>
    <button class="btn"><i class="fas fa-paper-plane"></i> Send feedback</button>
</form>
@endsection
