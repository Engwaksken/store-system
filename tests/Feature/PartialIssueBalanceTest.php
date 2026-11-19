<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Exceptions\WorkflowException;
use App\Models\MaterialIssue;
use App\Services\IssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** More requested than in store: the available stock is issued and the remaining balance is shown everywhere. */
class PartialIssueBalanceTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    public function test_web_issue_of_more_than_on_hand_issues_the_available_and_shows_the_balance(): void
    {
        $this->receive($this->cement, 6);
        $req = $this->approvedRequest(10);
        $line = $req->lines()->sole();

        $this->actingAs($this->store)->get(route('issues.create', $req))->assertOk()->assertSee('value="6"', false);

        $res = $this->post(route('issues.store', $req), ['qty' => [$line->id => 10], 'recipient_name' => 'Foreman'])->assertRedirect();
        $issue = MaterialIssue::sole();
        $res->assertSessionHas('status', fn ($s) => str_contains($s, "Remaining balance on {$req->request_no}: {$this->cement->name} 4"));

        $this->assertSame('6.000', (string) $issue->lines()->sole()->qty);
        $this->assertSame('0.000', $this->onHand($this->cement));
        $this->assertSame(RequestStatus::PartiallyIssued, $req->fresh()->status);

        $this->get(route('issues.show', $issue))->assertOk()
            ->assertSee('Balance remaining')->assertSee('Partial issue.');
        $this->get(route('issues.pdf', $issue))->assertOk();
        $this->actingAs($this->staff)->get(route('requests.show', $req))->assertOk()
            ->assertSee('Partly issued.')->assertSee('Remaining balance to issue');
    }

    public function test_balance_on_each_voucher_reflects_issues_up_to_that_voucher(): void
    {
        $this->receive($this->cement, 3);
        $req = $this->approvedRequest(10);
        $lineId = $req->lines()->sole()->id;
        $first = app(IssueService::class)->issue($this->store, $req, [$lineId => 10], 'A');
        $this->receive($this->cement, 20);
        $second = app(IssueService::class)->issue($this->store, $req->fresh(), [$lineId => 7], 'B');

        $this->assertSame('7.000', $first->lines()->sole()->balanceAfter());
        $this->assertSame('0.000', $second->lines()->sole()->balanceAfter());
        $this->assertSame(RequestStatus::FullyIssued, $req->fresh()->status);

        $this->actingAs($this->store)->get(route('issues.show', $second))->assertOk()->assertDontSee('Partial issue.');
    }

    public function test_nothing_in_stock_is_refused_with_a_clear_message(): void
    {
        $req = $this->approvedRequest(5);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('out of stock at this store');
        app(IssueService::class)->issue($this->store, $req, [$req->lines()->sole()->id => 5], 'X');
    }

    public function test_api_issue_returns_the_issued_quantity_and_balance(): void
    {
        $this->receive($this->cement, 2);
        $req = $this->approvedRequest(5);
        $token = $this->store->createToken('t')->plainTextToken;

        $json = $this->postJson("/api/v1/requests/{$req->id}/issue", [
            'store_id' => 1, 'qty' => [$req->lines()->sole()->id => 5], 'recipient_name' => 'Foreman',
        ], ['Authorization' => "Bearer {$token}"])->assertSuccessful()->json('data');

        $this->assertSame('2.000', $json['lines'][0]['qty']);
        $this->assertSame('5.000', $json['lines'][0]['approved_qty']);
        $this->assertSame('3.000', $json['lines'][0]['balance_qty']);
    }
}
