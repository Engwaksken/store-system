import '../../../core/api/json.dart';
import '../../../core/api/refs.dart';

/// Material request statuses (contract): used for filter chips.
class RequestStatuses {
  static const draft = 'draft';
  static const submitted = 'submitted';
  static const pmApproved = 'pm_approved';
  static const pmRejected = 'pm_rejected';
  static const overallApproved = 'overall_approved';
  static const overallRejected = 'overall_rejected';
  static const partiallyIssued = 'partially_issued';
  static const fullyIssued = 'fully_issued';
  static const cancelled = 'cancelled';

  /// (status value, chip label). `null` = all.
  static const filters = <(String?, String)>[
    (null, 'All'),
    (draft, 'Draft'),
    (submitted, 'Awaiting PM'),
    (pmApproved, 'PM approved'),
    (overallApproved, 'Approved'),
    (partiallyIssued, 'Partly issued'),
    (fullyIssued, 'Issued'),
    (pmRejected, 'PM rejected'),
    (overallRejected, 'Rejected'),
    (cancelled, 'Cancelled'),
  ];
}

class ItemRef {
  const ItemRef({required this.id, required this.sku, required this.name, required this.unit});
  final int id;
  final String sku;
  final String name;
  final String unit;

  factory ItemRef.fromJson(Json json) => ItemRef(
        id: asInt(json['id']),
        sku: asString(json['sku']),
        name: asString(json['name']),
        unit: asString(json['unit']),
      );
}

class RequestSummary {
  const RequestSummary({
    required this.id,
    required this.requestNo,
    required this.version,
    required this.status,
    required this.statusLabel,
    required this.statusTone,
    required this.project,
    required this.requester,
    required this.purpose,
    this.neededOn,
    required this.linesCount,
    this.submittedAt,
    this.createdAt,
  });

  final int id;
  final String requestNo;
  final int version;
  final String status;
  final String statusLabel;
  final String statusTone;
  final CodedRef? project;
  final NamedRef? requester;
  final String purpose;

  /// `YYYY-MM-DD`.
  final String? neededOn;
  final int linesCount;

  /// ISO 8601 strings (kept raw).
  final String? submittedAt;
  final String? createdAt;

  factory RequestSummary.fromJson(Json json) => RequestSummary(
        id: asInt(json['id']),
        requestNo: asString(json['request_no']),
        version: asInt(json['version'], 1),
        status: asString(json['status']),
        statusLabel: asString(json['status_label'], asString(json['status'])),
        statusTone: asString(json['status_tone'], 'muted'),
        project: CodedRef.maybe(json['project']),
        requester: NamedRef.maybe(json['requester']),
        purpose: asString(json['purpose']),
        neededOn: asStringOrNull(json['needed_on']),
        linesCount: asInt(json['lines_count']),
        submittedAt: asStringOrNull(json['submitted_at']),
        createdAt: asStringOrNull(json['created_at']),
      );
}

class RequestLine {
  const RequestLine({
    required this.id,
    required this.item,
    required this.requestedQty,
    this.pmApprovedQty,
    this.finalApprovedQty,
    this.issuedQty,
    this.onHand,
    this.notes,
  });

  final int id;
  final ItemRef item;
  final String requestedQty;
  final String? pmApprovedQty;
  final String? finalApprovedQty;
  final String? issuedQty;
  final String? onHand;
  final String? notes;

  /// Reads a quantity field by its API name (used with `can.max_qty_field`).
  String? qtyField(String? field) => switch (field) {
        'requested_qty' => requestedQty,
        'pm_approved_qty' => pmApprovedQty,
        'final_approved_qty' => finalApprovedQty,
        'issued_qty' => issuedQty,
        _ => null,
      };

  factory RequestLine.fromJson(Json json) => RequestLine(
        id: asInt(json['id']),
        item: ItemRef.fromJson(asMap(json['item'])),
        requestedQty: asDecimalString(json['requested_qty']) ?? '0',
        pmApprovedQty: asDecimalString(json['pm_approved_qty']),
        finalApprovedQty: asDecimalString(json['final_approved_qty']),
        issuedQty: asDecimalString(json['issued_qty']),
        onHand: asDecimalString(json['on_hand']),
        notes: asStringOrNull(json['notes']),
      );
}

class ApprovalEntry {
  const ApprovalEntry({
    required this.step,
    required this.version,
    required this.decision,
    this.reason,
    this.approver,
    required this.wasSubstitute,
    this.decidedAt,
  });

  /// `pm` | `overall`.
  final String step;
  final int version;

  /// `approved` | `rejected` (other values are shown as-is).
  final String decision;
  final String? reason;
  final NamedRef? approver;
  final bool wasSubstitute;
  final String? decidedAt;

  String get stepLabel => switch (step) {
        'pm' => 'Project manager',
        'overall' => 'Final approval',
        _ => step,
      };

  factory ApprovalEntry.fromJson(Json json) => ApprovalEntry(
        step: asString(json['step']),
        version: asInt(json['version'], 1),
        decision: asString(json['decision']),
        reason: asStringOrNull(json['reason']),
        approver: NamedRef.maybe(json['approver']),
        wasSubstitute: asBool(json['was_substitute']),
        decidedAt: asStringOrNull(json['decided_at']),
      );
}

class RequestCan {
  const RequestCan({
    this.decide = false,
    this.decisionStep,
    this.maxQtyField,
    this.cancel = false,
  });

  final bool decide;
  final String? decisionStep;

  /// `requested_qty` (PM step) or `pm_approved_qty` (overall step).
  final String? maxQtyField;
  final bool cancel;

  factory RequestCan.fromJson(Json json) => RequestCan(
        decide: asBool(json['decide']),
        decisionStep: asStringOrNull(json['decision_step']),
        maxQtyField: asStringOrNull(json['max_qty_field']),
        cancel: asBool(json['cancel']),
      );
}

class RequestDetail extends RequestSummary {
  const RequestDetail({
    required super.id,
    required super.requestNo,
    required super.version,
    required super.status,
    required super.statusLabel,
    required super.statusTone,
    required super.project,
    required super.requester,
    required super.purpose,
    super.neededOn,
    required super.linesCount,
    super.submittedAt,
    super.createdAt,
    this.projectManager,
    required this.lines,
    required this.approvals,
    required this.can,
  });

  final NamedRef? projectManager;
  final List<RequestLine> lines;
  final List<ApprovalEntry> approvals;
  final RequestCan can;

  /// Ceiling for the approved quantity of [line] at the current step.
  /// Falls back to the requested qty when `max_qty_field` is missing.
  String ceilingFor(RequestLine line) =>
      line.qtyField(can.maxQtyField ?? 'requested_qty') ?? '0';

  factory RequestDetail.fromJson(Json json) {
    final s = RequestSummary.fromJson(json);
    final lines = asList(json['lines'], RequestLine.fromJson);
    return RequestDetail(
      id: s.id,
      requestNo: s.requestNo,
      version: s.version,
      status: s.status,
      statusLabel: s.statusLabel,
      statusTone: s.statusTone,
      project: s.project,
      requester: s.requester,
      purpose: s.purpose,
      neededOn: s.neededOn,
      linesCount: json.containsKey('lines_count') ? s.linesCount : lines.length,
      submittedAt: s.submittedAt,
      createdAt: s.createdAt,
      projectManager: NamedRef.maybe(json['project_manager']),
      lines: lines,
      approvals: asList(json['approvals'], ApprovalEntry.fromJson),
      can: RequestCan.fromJson(asMap(json['can'])),
    );
  }
}

/// A line of a new request being composed.
class NewRequestLine {
  NewRequestLine({this.item, this.qty = '', this.notes = ''});
  ItemRef? item;
  String qty;
  String notes;

  Json toJson() => {
        'item_id': item?.id,
        'requested_qty': qty.trim(),
        if (notes.trim().isNotEmpty) 'notes': notes.trim(),
      };
}
