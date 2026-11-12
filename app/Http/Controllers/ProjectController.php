<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $projects = Project::visibleTo($request->user())->with('manager')->withCount('members')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$request->q}%")->orWhere('code', 'like', "%{$request->q}%")))
            ->orderBy('code')->paginate(25)->withQueryString();

        return view('projects.index', compact('projects'));
    }

    public function create()
    {
        Gate::authorize('manage-projects');

        return view('projects.form', ['project' => new Project(['status' => 'active']), ...$this->formOptions()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-projects');
        $data = $this->validated($request);
        $project = Project::create($data + ['created_by' => $request->user()->id]);
        $project->members()->sync($request->input('member_ids', []));
        ActivityLog::record('project.created', "Project {$project->code} created", $project);

        return redirect()->route('projects.show', $project)->with('status', 'Project created.');
    }

    public function show(Request $request, Project $project, ProjectCostService $costs)
    {
        Gate::authorize('view-project', $project);
        $project->load('manager', 'members');
        $user = $request->user();
        $finance = Gate::allows('view-project-finance', $project);

        return view('projects.show', [
            'project' => $project,
            'cost' => $finance ? $costs->summary(collect([$project]))->first() : null,
            'requests' => MaterialRequest::visibleTo($user)->where('project_id', $project->id)->with('requester')->latest()->limit(20)->get(),
            'issues' => $finance || Gate::allows('view-stock-ledger') ? MaterialIssue::where('project_id', $project->id)->latest('issued_at')->limit(20)->get() : collect(),
            'expenses' => $finance ? Expense::where('project_id', $project->id)->with('category')->latest('incurred_on')->limit(20)->get() : collect(),
        ]);
    }

    public function edit(Project $project)
    {
        Gate::authorize('manage-projects');

        return view('projects.form', ['project' => $project->load('members'), ...$this->formOptions()]);
    }

    public function update(Request $request, Project $project)
    {
        Gate::authorize('manage-projects');
        $data = $this->validated($request, $project);
        $before = $project->only('manager_id', 'budget', 'status');
        $project->update($data);
        $sync = $project->members()->sync($request->input('member_ids', []));
        ActivityLog::record('project.updated', "Project {$project->code} updated", $project, [
            'before' => $before, 'after' => $project->only('manager_id', 'budget', 'status'),
            'members_added' => $sync['attached'], 'members_removed' => $sync['detached'],
        ]);

        return redirect()->route('projects.show', $project)->with('status', 'Project updated.');
    }

    private function formOptions(): array
    {
        return [
            'managers' => User::where('role', Role::ProjectManager)->where('is_active', true)->orderBy('name')->get(),
            'staff' => User::whereIn('role', [Role::Staff, Role::ProjectManager])->where('is_active', true)->orderBy('name')->get(),
        ];
    }

    private function validated(Request $request, ?Project $project = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('projects')->ignore($project?->id)],
            'name' => 'required|string|max:255',
            'client' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => ['required', Rule::in(Project::STATUSES)],
            'budget' => 'required|numeric|min:0|max:9999999999999',
            'manager_id' => ['nullable', Rule::exists('users', 'id')->where('role', Role::ProjectManager->value)->where('is_active', true)],
            'description' => 'nullable|string|max:2000',
            'member_ids' => 'array',
            'member_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);
    }
}
