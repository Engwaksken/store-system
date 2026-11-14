<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Proves the books agree with themselves (Definition of Done: "stock ledger reconciles with balances").
 * Read-only. Exit code 1 and a list of differences when anything does not reconcile.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'cms:reconcile {--limit=20 : maximum differences listed per check}';

    protected $description = 'Reconcile stock ledger, vouchers, purchase orders, invoices, transfers, returns and claims';

    private array $failures = [];

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        // 1. Ledger = balance, per store × item, and the last balance_after equals the balance.
        $sums = DB::table('stock_movements')->groupBy('store_id', 'item_id')
            ->selectRaw('store_id, item_id, SUM(qty_delta) as s, MAX(id) as last_id')->get()->keyBy(fn ($r) => $r->store_id.':'.$r->item_id);
        $lastAfter = DB::table('stock_movements')->whereIn('id', $sums->pluck('last_id'))->pluck('balance_after', 'id');
        $bad = [];
        foreach (DB::table('inventory_balances')->get() as $b) {
            $key = $b->store_id.':'.$b->item_id;
            $ledger = bcadd((string) ($sums[$key]->s ?? 0), '0', 3);
            $onHand = bcadd((string) $b->on_hand, '0', 3);
            if (bccomp($ledger, $onHand, 3) !== 0) {
                $bad[] = "store {$b->store_id} item {$b->item_id}: ledger {$ledger} != on hand {$onHand}";
            } elseif (isset($sums[$key]) && bccomp(bcadd((string) $lastAfter[$sums[$key]->last_id], '0', 3), $onHand, 3) !== 0) {
                $bad[] = "store {$b->store_id} item {$b->item_id}: last balance_after != on hand {$onHand}";
            }
            if (bccomp($onHand, '0', 3) < 0) {
                $bad[] = "store {$b->store_id} item {$b->item_id}: negative stock {$onHand}";
            }
        }
        foreach ($sums as $key => $s) {
            if (! DB::table('inventory_balances')->where('store_id', explode(':', $key)[0])->where('item_id', explode(':', $key)[1])->exists()) {
                $bad[] = "store/item {$key}: movements without a balance row";
            }
        }
        $this->check('Stock ledger = balances (per store × item)', $bad, $limit);

        // 2. Request lines: issued_qty = Σ voucher lines, and never above the final approved quantity.
        $this->check('Request lines issued = vouchers, ≤ approved', DB::table('material_request_lines as rl')
            ->leftJoinSub(DB::table('material_issue_lines')->groupBy('material_request_line_id')->selectRaw('material_request_line_id, SUM(qty) as q'), 'il', 'il.material_request_line_id', '=', 'rl.id')
            ->whereRaw('ROUND(COALESCE(il.q, 0), 3) <> ROUND(rl.issued_qty, 3) OR rl.issued_qty > COALESCE(rl.final_approved_qty, 0)')
            ->limit($limit)->get(['rl.id', 'rl.issued_qty', 'il.q'])->map(fn ($r) => "request line {$r->id}: issued {$r->issued_qty}, vouchers ".($r->q ?? 0))->all(), $limit);

        // 3. Vouchers: header total = Σ lines; one issue movement per line.
        $this->check('Voucher totals and movements', DB::table('material_issues as i')
            ->leftJoinSub(DB::table('material_issue_lines')->groupBy('material_issue_id')->selectRaw('material_issue_id, SUM(line_value) as v, COUNT(*) as n'), 'l', 'l.material_issue_id', '=', 'i.id')
            ->leftJoinSub(DB::table('stock_movements')->where('source_type', 'material_issue')->groupBy('source_id')->selectRaw('source_id, COUNT(*) as n'), 'm', 'm.source_id', '=', 'i.id')
            ->whereRaw('ROUND(COALESCE(l.v, 0), 2) <> ROUND(i.total_value, 2) OR COALESCE(l.n, 0) <> COALESCE(m.n, 0)')
            ->limit($limit)->pluck('i.issue_no')->map(fn ($no) => "voucher {$no}: total or movements do not match its lines")->all(), $limit);

        // 4. Returns never exceed what was issued on the voucher line.
        $this->check('Returns ≤ issued per voucher line', DB::table('material_issue_lines as il')
            ->joinSub(DB::table('stock_return_lines')->groupBy('material_issue_line_id')->selectRaw('material_issue_line_id, SUM(qty) as q'), 'r', 'r.material_issue_line_id', '=', 'il.id')
            ->whereColumn('r.q', '>', 'il.qty')->limit($limit)->pluck('il.id')->map(fn ($id) => "voucher line {$id}: returned more than issued")->all(), $limit);

        // 5. Transfers: received = Σ transfer_in, dispatched = Σ transfer_out (and cancellations fully reversed).
        $this->check('Transfers reconcile (dispatched / received / cancelled)', DB::table('stock_transfers as t')
            ->leftJoinSub(DB::table('stock_transfer_lines')->groupBy('stock_transfer_id')->selectRaw('stock_transfer_id, SUM(qty) as d, SUM(COALESCE(received_qty, 0)) as r'), 'l', 'l.stock_transfer_id', '=', 't.id')
            ->leftJoinSub(DB::table('stock_movements')->where('source_type', 'stock_transfer')->groupBy('source_id')
                ->selectRaw("source_id, SUM(CASE WHEN movement_type = 'transfer_out' THEN -qty_delta ELSE 0 END) as o, SUM(CASE WHEN movement_type = 'transfer_in' THEN qty_delta ELSE 0 END) as i, SUM(CASE WHEN movement_type = 'transfer_cancel' THEN qty_delta ELSE 0 END) as c"), 'm', 'm.source_id', '=', 't.id')
            ->whereRaw("ROUND(COALESCE(m.o, 0), 3) <> ROUND(COALESCE(l.d, 0), 3) OR ROUND(COALESCE(m.i, 0), 3) <> ROUND(COALESCE(l.r, 0), 3) OR (t.status = 'cancelled' AND ROUND(COALESCE(m.c, 0), 3) <> ROUND(COALESCE(l.d, 0), 3))")
            ->limit($limit)->pluck('t.transfer_no')->map(fn ($no) => "transfer {$no}: movements do not match its lines")->all(), $limit);

        // 6. Purchase order lines: received = Σ GRN lines ≤ ordered; invoiced = Σ invoice lines.
        $this->check('PO received = GRNs, invoiced = invoices', DB::table('purchase_order_lines as pl')
            ->leftJoinSub(DB::table('stock_receipt_lines')->whereNotNull('purchase_order_line_id')->groupBy('purchase_order_line_id')->selectRaw('purchase_order_line_id, SUM(qty) as q'), 'g', 'g.purchase_order_line_id', '=', 'pl.id')
            ->leftJoinSub(DB::table('purchase_invoice_lines')->groupBy('purchase_order_line_id')->selectRaw('purchase_order_line_id, SUM(qty) as q'), 'v', 'v.purchase_order_line_id', '=', 'pl.id')
            ->whereRaw('ROUND(COALESCE(g.q, 0), 3) <> ROUND(pl.received_qty, 3) OR pl.received_qty > pl.qty OR ROUND(COALESCE(v.q, 0), 3) <> ROUND(pl.invoiced_qty, 3)')
            ->limit($limit)->pluck('pl.id')->map(fn ($id) => "PO line {$id}: counters do not match GRNs/invoices")->all(), $limit);

        // 7. Supplier invoices: amount_paid = Σ payments ≤ total.
        $this->check('Invoice paid = payments ≤ total', DB::table('purchase_invoices as i')
            ->leftJoinSub(DB::table('supplier_payments')->groupBy('purchase_invoice_id')->selectRaw('purchase_invoice_id, SUM(amount) as p'), 'p', 'p.purchase_invoice_id', '=', 'i.id')
            ->whereRaw('ROUND(COALESCE(p.p, 0), 2) <> ROUND(i.amount_paid, 2) OR i.amount_paid > i.total')
            ->limit($limit)->pluck('i.pi_no')->map(fn ($no) => "invoice {$no}: amount paid does not match payments")->all(), $limit);

        // 8. Paid claims ↔ exactly one expense.
        $this->check('Paid claims = one expense each', DB::table('expense_claims as c')
            ->leftJoinSub(DB::table('expenses')->whereNotNull('expense_claim_id')->groupBy('expense_claim_id')->selectRaw('expense_claim_id, COUNT(*) as n'), 'e', 'e.expense_claim_id', '=', 'c.id')
            ->whereRaw("(c.status = 'paid' AND COALESCE(e.n, 0) <> 1) OR (c.status <> 'paid' AND COALESCE(e.n, 0) <> 0)")
            ->limit($limit)->pluck('c.claim_no')->map(fn ($no) => "claim {$no}: expense rows do not match its status")->all(), $limit);

        if ($this->failures) {
            $this->error('Reconciliation FAILED: '.implode('; ', $this->failures).'.');

            return self::FAILURE;
        }
        $this->info('All books reconcile.');

        return self::SUCCESS;
    }

    private function check(string $name, array $problems, int $limit): void
    {
        if (! $problems) {
            $this->line("  PASS  {$name}");

            return;
        }
        $this->failures[] = $name;
        $this->line("  FAIL  {$name}");
        foreach (array_slice($problems, 0, $limit) as $p) {
            $this->line("        - {$p}");
        }
    }
}
