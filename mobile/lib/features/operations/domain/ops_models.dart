import 'package:decimal/decimal.dart';

import '../../../core/api/json.dart';
import '../../../core/api/refs.dart';
import '../../requests/domain/request_models.dart';

/// Shapes of the store-operations and purchasing endpoints (docs/API.md, "Store operations").
/// Money and quantities stay decimal strings. Stock prices are absent unless the viewer may see them.

ItemRef _item(Object? v) => ItemRef.fromJson(asMap(v));

/// `{id, <no field>}` link to another document.
class DocRef {
  const DocRef({required this.id, required this.no});
  final int id;
  final String no;

  static DocRef? maybe(Object? v, String noKey) {
    final m = asMapOrNull(v);
    return m == null ? null : DocRef(id: asInt(m['id']), no: asString(m[noKey]));
  }
}

class StoreOptions {
  const StoreOptions({required this.operable, required this.active});
  final List<CodedRef> operable;
  final List<CodedRef> active;

  factory StoreOptions.fromJson(Json json) =>
      StoreOptions(operable: asList(json['operable_stores'], CodedRef.fromJson), active: asList(json['active_stores'], CodedRef.fromJson));
}

// ------------------------------------------------------------------ issue vouchers

class IssueSummary {
  const IssueSummary({
    required this.id,
    required this.issueNo,
    this.request,
    this.project,
    this.store,
    this.issuedAt,
    this.issuer,
    required this.recipientName,
    required this.acknowledged,
    required this.linesCount,
    this.totalValue,
  });

  final int id;
  final String issueNo;
  final DocRef? request;
  final CodedRef? project;
  final CodedRef? store;
  final String? issuedAt;
  final NamedRef? issuer;
  final String recipientName;
  final bool acknowledged;
  final int linesCount;
  final String? totalValue;

  factory IssueSummary.fromJson(Json j) => IssueSummary(
    id: asInt(j['id']),
    issueNo: asString(j['issue_no']),
    request: DocRef.maybe(j['request'], 'request_no'),
    project: CodedRef.maybe(j['project']),
    store: CodedRef.maybe(j['store']),
    issuedAt: asStringOrNull(j['issued_at']),
    issuer: NamedRef.maybe(j['issuer']),
    recipientName: asString(j['recipient_name']),
    acknowledged: asBool(j['acknowledged']),
    linesCount: asInt(j['lines_count']),
    totalValue: asDecimalString(j['total_value']),
  );
}

class IssueLine {
  const IssueLine({
    required this.id,
    required this.item,
    required this.qty,
    this.returnedQty,
    this.approvedQty,
    this.balanceQty,
    this.unitCost,
    this.lineValue,
  });
  final int id;
  final ItemRef item;
  final String qty;
  final String? returnedQty;

  /// Final approved quantity on the request line.
  final String? approvedQty;

  /// Still to issue on the request line after this voucher (partial issue).
  final String? balanceQty;

  bool get hasBalance => balanceQty != null && (Decimal.tryParse(balanceQty!) ?? Decimal.zero) > Decimal.zero;
  final String? unitCost;
  final String? lineValue;

  factory IssueLine.fromJson(Json j) => IssueLine(
    id: asInt(j['id']),
    item: _item(j['item']),
    qty: asDecimalString(j['qty']) ?? '0',
    returnedQty: asDecimalString(j['returned_qty']),
    approvedQty: asDecimalString(j['approved_qty']),
    balanceQty: asDecimalString(j['balance_qty']),
    unitCost: asDecimalString(j['unit_cost']),
    lineValue: asDecimalString(j['line_value']),
  );
}

class IssueDetail {
  const IssueDetail({
    required this.summary,
    this.notes,
    required this.lines,
    this.ackRecipient,
    this.ackRemarks,
    this.ackBy,
    this.ackAt,
    required this.returns,
    required this.canAcknowledge,
    required this.canReturn,
  });

  final IssueSummary summary;
  final String? notes;
  final List<IssueLine> lines;
  final String? ackRecipient;
  final String? ackRemarks;
  final NamedRef? ackBy;
  final String? ackAt;
  final List<DocRef> returns;
  final bool canAcknowledge;
  final bool canReturn;

  factory IssueDetail.fromJson(Json j) {
    final ack = asMapOrNull(j['acknowledgment']);
    final can = asMap(j['can']);
    return IssueDetail(
      summary: IssueSummary.fromJson(j),
      notes: asStringOrNull(j['notes']),
      lines: asList(j['lines'], IssueLine.fromJson),
      ackRecipient: ack == null ? null : asStringOrNull(ack['recipient_name']),
      ackRemarks: ack == null ? null : asStringOrNull(ack['remarks']),
      ackBy: ack == null ? null : NamedRef.maybe(ack['by']),
      ackAt: ack == null ? null : asStringOrNull(ack['at']),
      returns: [
        if (j['returns'] is List)
          for (final r in j['returns'] as List) ?DocRef.maybe(r, 'return_no'),
      ],
      canAcknowledge: asBool(can['acknowledge']),
      canReturn: asBool(can['return']),
    );
  }
}

/// GET /requests/{id}/issue: what can be issued now from a store, and what goes to restocking.
class IssueFormLine {
  const IssueFormLine({
    required this.id,
    required this.item,
    this.finalApprovedQty,
    this.issuedQty,
    required this.outstanding,
    required this.onHand,
    required this.maxIssue,
  });

  final int id;
  final ItemRef item;
  final String? finalApprovedQty;
  final String? issuedQty;
  final String outstanding;
  final String onHand;
  final String maxIssue;

  factory IssueFormLine.fromJson(Json j) => IssueFormLine(
    id: asInt(j['id']),
    item: _item(j['item']),
    finalApprovedQty: asDecimalString(j['final_approved_qty']),
    issuedQty: asDecimalString(j['issued_qty']),
    outstanding: asDecimalString(j['outstanding']) ?? '0',
    onHand: asDecimalString(j['on_hand']) ?? '0',
    maxIssue: asDecimalString(j['max_issue']) ?? '0',
  );
}

class Shortfall {
  const Shortfall({required this.itemName, required this.unit, required this.shortQty, required this.onHand, required this.restockQty});
  final String itemName;
  final String unit;
  final String shortQty;
  final String onHand;
  final String restockQty;

  factory Shortfall.fromJson(Json j) {
    final item = asMap(j['item']);
    return Shortfall(
      itemName: asString(item['name']),
      unit: asString(item['unit']),
      shortQty: asDecimalString(j['short_qty']) ?? '0',
      onHand: asDecimalString(j['on_hand']) ?? '0',
      restockQty: asDecimalString(j['restock_qty']) ?? '0',
    );
  }
}

class IssueForm {
  const IssueForm({
    required this.request,
    required this.stores,
    required this.storeId,
    required this.recipientName,
    required this.lines,
    required this.shortfall,
    required this.openRestock,
  });

  final RequestSummary request;
  final List<CodedRef> stores;
  final int storeId;
  final String recipientName;
  final List<IssueFormLine> lines;
  final List<Shortfall> shortfall;
  final List<DocRef> openRestock;

  factory IssueForm.fromJson(Json j) => IssueForm(
    request: RequestSummary.fromJson(asMap(j['request'])),
    stores: asList(j['stores'], CodedRef.fromJson),
    storeId: asInt(j['store_id'], 1),
    recipientName: asString(j['recipient_name']),
    lines: asList(j['lines'], IssueFormLine.fromJson),
    shortfall: asList(j['shortfall'], Shortfall.fromJson),
    openRestock: [
      if (j['open_restock'] is List)
        for (final r in j['open_restock'] as List) ?DocRef.maybe(r, 'pr_no'),
    ],
  );
}

/// Result of POST /requests/{id}/issue.
class IssueResult {
  const IssueResult({required this.issue, this.restock, required this.replayed});
  final IssueDetail issue;
  final DocRef? restock;
  final bool replayed;
}

// ------------------------------------------------------------------ receipts

class ReceiptSummary {
  const ReceiptSummary({
    required this.id,
    required this.receiptNo,
    required this.type,
    this.store,
    this.receivedOn,
    this.supplierName,
    this.reference,
    this.purchaseOrder,
    this.receiver,
    required this.linesCount,
    this.totalValue,
  });

  final int id;
  final String receiptNo;
  final String type;
  final CodedRef? store;
  final String? receivedOn;
  final String? supplierName;
  final String? reference;
  final DocRef? purchaseOrder;
  final NamedRef? receiver;
  final int linesCount;
  final String? totalValue;

  String get typeLabel => type == 'opening' ? 'Opening balance' : 'Purchase';

  factory ReceiptSummary.fromJson(Json j) => ReceiptSummary(
    id: asInt(j['id']),
    receiptNo: asString(j['receipt_no']),
    type: asString(j['type']),
    store: CodedRef.maybe(j['store']),
    receivedOn: asStringOrNull(j['received_on']),
    supplierName: asStringOrNull(j['supplier_name']),
    reference: asStringOrNull(j['reference']),
    purchaseOrder: DocRef.maybe(j['purchase_order'], 'po_no'),
    receiver: NamedRef.maybe(j['receiver']),
    linesCount: asInt(j['lines_count']),
    totalValue: asDecimalString(j['total_value']),
  );
}

class ValuedLine {
  const ValuedLine({required this.id, required this.item, required this.qty, this.unitCost, this.total});
  final int id;
  final ItemRef item;
  final String qty;
  final String? unitCost;
  final String? total;

  factory ValuedLine.fromJson(Json j) => ValuedLine(
    id: asInt(j['id']),
    item: _item(j['item']),
    qty: asDecimalString(j['qty']) ?? '0',
    unitCost: asDecimalString(j['unit_cost']),
    total: asDecimalString(j['line_total'] ?? j['line_value']),
  );
}

class ReceiptDetail {
  const ReceiptDetail({required this.summary, this.notes, required this.lines});
  final ReceiptSummary summary;
  final String? notes;
  final List<ValuedLine> lines;

  factory ReceiptDetail.fromJson(Json j) =>
      ReceiptDetail(summary: ReceiptSummary.fromJson(j), notes: asStringOrNull(j['notes']), lines: asList(j['lines'], ValuedLine.fromJson));
}

/// One line typed on the new-receipt / new-transfer forms.
class DraftLine {
  DraftLine({required this.item, this.qty = '', this.unitCost = ''});
  final ItemRef item;
  String qty;
  String unitCost;
}

// ------------------------------------------------------------------ transfers

class TransferStatuses {
  static const filters = <(String?, String)>[(null, 'All'), ('in_transit', 'In transit'), ('received', 'Received'), ('cancelled', 'Cancelled')];
}

class TransferSummary {
  const TransferSummary({
    required this.id,
    required this.transferNo,
    required this.status,
    required this.statusLabel,
    required this.statusTone,
    this.fromStore,
    this.toStore,
    this.dispatchedAt,
    this.dispatcher,
    this.receivedAt,
    this.receiver,
    required this.linesCount,
  });

  final int id;
  final String transferNo;
  final String status;
  final String statusLabel;
  final String statusTone;
  final CodedRef? fromStore;
  final CodedRef? toStore;
  final String? dispatchedAt;
  final NamedRef? dispatcher;
  final String? receivedAt;
  final NamedRef? receiver;
  final int linesCount;

  factory TransferSummary.fromJson(Json j) => TransferSummary(
    id: asInt(j['id']),
    transferNo: asString(j['transfer_no']),
    status: asString(j['status']),
    statusLabel: asString(j['status_label'], asString(j['status'])),
    statusTone: asString(j['status_tone']),
    fromStore: CodedRef.maybe(j['from_store']),
    toStore: CodedRef.maybe(j['to_store']),
    dispatchedAt: asStringOrNull(j['dispatched_at']),
    dispatcher: NamedRef.maybe(j['dispatcher']),
    receivedAt: asStringOrNull(j['received_at']),
    receiver: NamedRef.maybe(j['receiver']),
    linesCount: asInt(j['lines_count']),
  );
}

class TransferLine {
  const TransferLine({required this.id, required this.item, required this.qty, this.receivedQty, this.shortfallReason});
  final int id;
  final ItemRef item;
  final String qty;
  final String? receivedQty;
  final String? shortfallReason;

  factory TransferLine.fromJson(Json j) => TransferLine(
    id: asInt(j['id']),
    item: _item(j['item']),
    qty: asDecimalString(j['qty']) ?? '0',
    receivedQty: asDecimalString(j['received_qty']),
    shortfallReason: asStringOrNull(j['shortfall_reason']),
  );
}

class TransferDetail {
  const TransferDetail({required this.summary, this.notes, this.receiveNotes, required this.lines, required this.canReceive, required this.canCancel});

  final TransferSummary summary;
  final String? notes;
  final String? receiveNotes;
  final List<TransferLine> lines;
  final bool canReceive;
  final bool canCancel;

  factory TransferDetail.fromJson(Json j) {
    final can = asMap(j['can']);
    return TransferDetail(
      summary: TransferSummary.fromJson(j),
      notes: asStringOrNull(j['notes']),
      receiveNotes: asStringOrNull(j['receive_notes']),
      lines: asList(j['lines'], TransferLine.fromJson),
      canReceive: asBool(can['receive']),
      canCancel: asBool(can['cancel']),
    );
  }
}

// ------------------------------------------------------------------ returns

class ReturnSummary {
  const ReturnSummary({
    required this.id,
    required this.returnNo,
    this.returnedAt,
    this.issue,
    this.project,
    this.store,
    required this.returnedByName,
    this.reason,
    this.receiver,
    this.totalValue,
  });

  final int id;
  final String returnNo;
  final String? returnedAt;
  final DocRef? issue;
  final CodedRef? project;
  final CodedRef? store;
  final String returnedByName;
  final String? reason;
  final NamedRef? receiver;
  final String? totalValue;

  factory ReturnSummary.fromJson(Json j) => ReturnSummary(
    id: asInt(j['id']),
    returnNo: asString(j['return_no']),
    returnedAt: asStringOrNull(j['returned_at']),
    issue: DocRef.maybe(j['issue'], 'issue_no'),
    project: CodedRef.maybe(j['project']),
    store: CodedRef.maybe(j['store']),
    returnedByName: asString(j['returned_by_name']),
    reason: asStringOrNull(j['reason']),
    receiver: NamedRef.maybe(j['receiver']),
    totalValue: asDecimalString(j['total_value']),
  );
}

class ReturnDetail {
  const ReturnDetail({required this.summary, required this.lines});
  final ReturnSummary summary;
  final List<ValuedLine> lines;

  factory ReturnDetail.fromJson(Json j) => ReturnDetail(summary: ReturnSummary.fromJson(j), lines: asList(j['lines'], ValuedLine.fromJson));
}

// ------------------------------------------------------------------ purchasing

class PurchaseStatuses {
  static const prFilters = <(String?, String)>[
    (null, 'All'),
    ('submitted', 'Awaiting approval'),
    ('approved', 'Approved'),
    ('ordered', 'Ordered'),
    ('rejected', 'Rejected'),
    ('draft', 'Draft'),
  ];

  static const poFilters = <(String?, String)>[
    (null, 'All'),
    ('pending_approval', 'Awaiting approval'),
    ('approved', 'Approved'),
    ('partially_received', 'Part received'),
    ('received', 'Received'),
    ('rejected', 'Rejected'),
  ];
}

class PrSummary {
  const PrSummary({
    required this.id,
    required this.prNo,
    required this.status,
    required this.statusLabel,
    required this.statusTone,
    this.project,
    this.store,
    required this.purpose,
    this.requester,
    this.neededOn,
    this.estimatedTotal,
    this.materialRequest,
    required this.isRestock,
    required this.linesCount,
    this.submittedAt,
  });

  final int id;
  final String prNo;
  final String status;
  final String statusLabel;
  final String statusTone;
  final CodedRef? project;
  final CodedRef? store;
  final String purpose;
  final NamedRef? requester;
  final String? neededOn;
  final String? estimatedTotal;
  final DocRef? materialRequest;
  final bool isRestock;
  final int linesCount;
  final String? submittedAt;

  factory PrSummary.fromJson(Json j) => PrSummary(
    id: asInt(j['id']),
    prNo: asString(j['pr_no']),
    status: asString(j['status']),
    statusLabel: asString(j['status_label'], asString(j['status'])),
    statusTone: asString(j['status_tone']),
    project: CodedRef.maybe(j['project']),
    store: CodedRef.maybe(j['store']),
    purpose: asString(j['purpose']),
    requester: NamedRef.maybe(j['requester']),
    neededOn: asStringOrNull(j['needed_on']),
    estimatedTotal: asDecimalString(j['estimated_total']),
    materialRequest: DocRef.maybe(j['material_request'], 'request_no'),
    isRestock: asBool(j['is_restock']),
    linesCount: asInt(j['lines_count']),
    submittedAt: asStringOrNull(j['submitted_at']),
  );
}

class PrLine {
  const PrLine({required this.id, required this.item, required this.qty, this.estUnitCost, this.notes});
  final int id;
  final ItemRef item;
  final String qty;
  final String? estUnitCost;
  final String? notes;

  factory PrLine.fromJson(Json j) => PrLine(
    id: asInt(j['id']),
    item: _item(j['item']),
    qty: asDecimalString(j['qty']) ?? '0',
    estUnitCost: asDecimalString(j['est_unit_cost']),
    notes: asStringOrNull(j['notes']),
  );
}

class PrDetail {
  const PrDetail({
    required this.summary,
    this.decider,
    this.decidedAt,
    this.decisionReason,
    required this.lines,
    required this.orders,
    required this.canDecide,
    required this.canSubmit,
  });

  final PrSummary summary;
  final NamedRef? decider;
  final String? decidedAt;
  final String? decisionReason;
  final List<PrLine> lines;
  final List<DocRef> orders;
  final bool canDecide;
  final bool canSubmit;

  factory PrDetail.fromJson(Json j) {
    final can = asMap(j['can']);
    return PrDetail(
      summary: PrSummary.fromJson(j),
      decider: NamedRef.maybe(j['decider']),
      decidedAt: asStringOrNull(j['decided_at']),
      decisionReason: asStringOrNull(j['decision_reason']),
      lines: asList(j['lines'], PrLine.fromJson),
      orders: [
        if (j['purchase_orders'] is List)
          for (final o in j['purchase_orders'] as List) ?DocRef.maybe(o, 'po_no'),
      ],
      canDecide: asBool(can['decide']),
      canSubmit: asBool(can['submit']),
    );
  }
}

class PoSummary {
  const PoSummary({
    required this.id,
    required this.poNo,
    required this.status,
    required this.statusLabel,
    required this.statusTone,
    this.supplier,
    this.project,
    this.store,
    this.orderDate,
    this.expectedOn,
    this.total,
    this.creator,
    required this.linesCount,
  });

  final int id;
  final String poNo;
  final String status;
  final String statusLabel;
  final String statusTone;
  final NamedRef? supplier;
  final CodedRef? project;
  final CodedRef? store;
  final String? orderDate;
  final String? expectedOn;
  final String? total;
  final NamedRef? creator;
  final int linesCount;

  factory PoSummary.fromJson(Json j) => PoSummary(
    id: asInt(j['id']),
    poNo: asString(j['po_no']),
    status: asString(j['status']),
    statusLabel: asString(j['status_label'], asString(j['status'])),
    statusTone: asString(j['status_tone']),
    supplier: NamedRef.maybe(j['supplier']),
    project: CodedRef.maybe(j['project']),
    store: CodedRef.maybe(j['store']),
    orderDate: asStringOrNull(j['order_date']),
    expectedOn: asStringOrNull(j['expected_on']),
    total: asDecimalString(j['total']),
    creator: NamedRef.maybe(j['creator']),
    linesCount: asInt(j['lines_count']),
  );
}

class PoLine {
  const PoLine({required this.id, required this.item, required this.qty, this.receivedQty, this.unitPrice, this.lineTotal});
  final int id;
  final ItemRef item;
  final String qty;
  final String? receivedQty;
  final String? unitPrice;
  final String? lineTotal;

  factory PoLine.fromJson(Json j) => PoLine(
    id: asInt(j['id']),
    item: _item(j['item']),
    qty: asDecimalString(j['qty']) ?? '0',
    receivedQty: asDecimalString(j['received_qty']),
    unitPrice: asDecimalString(j['unit_price']),
    lineTotal: asDecimalString(j['line_total']),
  );
}

class PoDetail {
  const PoDetail({
    required this.summary,
    required this.lockVersion,
    this.terms,
    this.notes,
    this.requisition,
    this.decider,
    this.decidedAt,
    this.decisionReason,
    this.ownerThreshold,
    required this.lines,
    required this.canDecide,
  });

  final PoSummary summary;
  final int lockVersion;
  final String? terms;
  final String? notes;
  final DocRef? requisition;
  final NamedRef? decider;
  final String? decidedAt;
  final String? decisionReason;
  final String? ownerThreshold;
  final List<PoLine> lines;
  final bool canDecide;

  factory PoDetail.fromJson(Json j) => PoDetail(
    summary: PoSummary.fromJson(j),
    lockVersion: asInt(j['lock_version']),
    terms: asStringOrNull(j['terms']),
    notes: asStringOrNull(j['notes']),
    requisition: DocRef.maybe(j['requisition'], 'pr_no'),
    decider: NamedRef.maybe(j['decider']),
    decidedAt: asStringOrNull(j['decided_at']),
    decisionReason: asStringOrNull(j['decision_reason']),
    ownerThreshold: asDecimalString(j['owner_threshold']),
    lines: asList(j['lines'], PoLine.fromJson),
    canDecide: asBool(asMap(j['can'])['decide']),
  );
}
