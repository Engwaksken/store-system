import '../../../core/api/json.dart';
import '../../../core/api/refs.dart';

class ClaimStatuses {
  /// (status value, chip label). `null` = all.
  /// The contract only names `submitted`; the rest mirror the web app.
  static const filters = <(String?, String)>[
    (null, 'All'),
    ('submitted', 'Awaiting approval'),
    ('approved', 'Approved'),
    ('rejected', 'Rejected'),
    ('paid', 'Paid'),
  ];
}

class ClaimCan {
  const ClaimCan({this.decide = false, this.edit = false, this.delete = false});
  final bool decide;
  final bool edit;
  final bool delete;

  factory ClaimCan.fromJson(Json json) => ClaimCan(
        decide: asBool(json['decide']),
        edit: asBool(json['edit']),
        delete: asBool(json['delete']),
      );
}

class Claim {
  const Claim({
    required this.id,
    required this.claimNo,
    required this.status,
    required this.statusLabel,
    required this.statusTone,
    this.project,
    this.claimant,
    this.category,
    this.costCode,
    this.incurredOn,
    required this.amount,
    required this.description,
    required this.hasReceipt,
    this.receiptUrl,
    this.decisionReason,
    this.updatedAt,
    required this.can,
  });

  final int id;
  final String claimNo;
  final String status;
  final String statusLabel;
  final String statusTone;
  final CodedRef? project;
  final NamedRef? claimant;
  final NamedRef? category;
  final CodedRef? costCode;
  final String? incurredOn;
  final String amount;
  final String description;
  final bool hasReceipt;
  final String? receiptUrl;
  final String? decisionReason;

  /// Raw ISO string; sent back verbatim as `seen_at` on decisions.
  final String? updatedAt;
  final ClaimCan can;

  factory Claim.fromJson(Json json) => Claim(
        id: asInt(json['id']),
        claimNo: asString(json['claim_no']),
        status: asString(json['status']),
        statusLabel: asString(json['status_label'], asString(json['status'])),
        statusTone: asString(json['status_tone'], 'muted'),
        project: CodedRef.maybe(json['project']),
        claimant: NamedRef.maybe(json['claimant']),
        category: NamedRef.maybe(json['category']),
        costCode: CodedRef.maybe(json['cost_code']),
        incurredOn: asStringOrNull(json['incurred_on']),
        amount: asDecimalString(json['amount']) ?? '0',
        description: asString(json['description']),
        hasReceipt: asBool(json['has_receipt']),
        receiptUrl: asStringOrNull(json['receipt_url']),
        decisionReason: asStringOrNull(json['decision_reason']),
        updatedAt: asStringOrNull(json['updated_at']),
        can: ClaimCan.fromJson(asMap(json['can'])),
      );
}

class ClaimOptions {
  const ClaimOptions({required this.projects, required this.categories, required this.costCodes});
  final List<CodedRef> projects;
  final List<NamedRef> categories;
  final List<CodedRef> costCodes;

  factory ClaimOptions.fromJson(Json json) => ClaimOptions(
        projects: asList(json['projects'], CodedRef.fromJson),
        categories: asList(json['categories'], NamedRef.fromJson),
        costCodes: asList(json['cost_codes'], CodedRef.fromJson),
      );
}
