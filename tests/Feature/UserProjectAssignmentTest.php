<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** The admin assigns users to projects on the user form; staff need at least one project to work. */
class UserProjectAssignmentTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function newUser(array $over = [])
    {
        return $this->actingAs($this->admin)->post(route('users.store'), $over + [
            'name' => 'Brian Site', 'email' => 'brian@example.test', 'role' => 'staff', 'password' => 'Temp12345', 'is_active' => '1',
        ]);
    }

    public function test_admin_creates_a_staff_member_on_a_project_who_can_then_request(): void
    {
        $this->actingAs($this->admin)->get(route('users.create'))->assertOk()->assertSee($this->project->code)->assertSee('project_ids[]', false);

        $this->newUser(['project_ids' => [$this->project->id]])->assertSessionHasNoErrors();
        $brian = User::where('email', 'brian@example.test')->sole();
        $this->assertTrue($brian->isAssignedTo($this->project));
        $this->assertTrue($brian->can('create-request'));
        $this->assertStringContainsString($this->project->code, ActivityLog::where('action', 'user.created')->latest('id')->value('description'));
    }

    public function test_a_new_staff_member_must_get_a_project(): void
    {
        $this->newUser()->assertSessionHasErrors('project_ids');
        $this->assertDatabaseMissing('users', ['email' => 'brian@example.test']);

        // Other roles see every project, so they do not need one.
        $this->newUser(['role' => 'accountant', 'email' => 'acc2@example.test'])->assertSessionHasNoErrors();
    }

    public function test_editing_changes_open_projects_and_keeps_closed_ones(): void
    {
        $second = Project::create(['code' => 'PRJ-2', 'name' => 'Second site', 'status' => 'active', 'budget' => 0]);
        $closed = Project::create(['code' => 'PRJ-OLD', 'name' => 'Finished job', 'status' => 'completed', 'budget' => 0]);
        $this->newUser(['project_ids' => [$this->project->id]]);
        $brian = User::where('email', 'brian@example.test')->sole();
        $brian->projects()->attach($closed->id);

        $otherClosed = Project::create(['code' => 'PRJ-OLD2', 'name' => 'Another finished job', 'status' => 'completed', 'budget' => 0]);

        // The closed project they are already on is shown (ticked) so it can be kept or removed; other closed ones are not offered.
        $this->actingAs($this->admin)->get(route('users.edit', $brian))->assertOk()->assertSee('PRJ-OLD')->assertDontSee('PRJ-OLD2');
        $this->actingAs($this->admin)->put(route('users.update', $brian), [
            'name' => $brian->name, 'email' => $brian->email, 'role' => 'staff', 'is_active' => '1',
            'project_ids' => [$second->id, $closed->id, $otherClosed->id],
        ])->assertSessionHasNoErrors();

        // Moved from the first project to the second, kept on the closed one, and cannot be added to another closed project.
        $this->assertEqualsCanonicalizing([$second->id, $closed->id], $brian->projects()->pluck('projects.id')->all());
        $this->actingAs($this->admin)->get(route('users.index'))->assertOk();
        $this->actingAs($this->staff)->get(route('users.create'))->assertForbidden();
    }
}
