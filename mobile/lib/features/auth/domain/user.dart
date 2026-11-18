import '../../../core/api/json.dart';

class UserCan {
  const UserCan({
    this.approveRequests = false,
    this.createRequest = false,
    this.submitClaim = false,
    this.decideClaims = false,
    this.viewStockValue = false,
    this.viewClaims = false,
    this.manageUsers = false,
    this.manageFeedback = false,
    this.issueStock = false,
    this.viewIssues = false,
    this.manageStock = false,
    this.viewReceipts = false,
    this.viewTransfers = false,
    this.createTransfer = false,
    this.viewReturns = false,
    this.viewPurchasing = false,
    this.decidePurchasing = false,
  });

  final bool approveRequests;
  final bool createRequest;
  final bool submitClaim;
  final bool decideClaims;
  final bool viewStockValue;
  final bool viewClaims;

  /// Admin: Users screens.
  final bool manageUsers;

  /// Admin: sees all feedback and replies.
  final bool manageFeedback;

  /// Storekeeper: issues approved requests (Store menu).
  final bool issueStock;
  final bool viewIssues;

  /// Records receipts and adjustments in a store they operate.
  final bool manageStock;
  final bool viewReceipts;
  final bool viewTransfers;
  final bool createTransfer;
  final bool viewReturns;
  final bool viewPurchasing;

  /// Owner/Admin and Overall Manager approve requisitions (incl. restock) and orders.
  final bool decidePurchasing;

  /// Anything for the Store menu.
  bool get anyStoreOps => issueStock || manageStock || viewReceipts || viewTransfers || viewReturns || viewPurchasing;

  factory UserCan.fromJson(Json json) => UserCan(
        approveRequests: asBool(json['approve_requests']),
        createRequest: asBool(json['create_request']),
        submitClaim: asBool(json['submit_claim']),
        decideClaims: asBool(json['decide_claims']),
        viewStockValue: asBool(json['view_stock_value']),
        viewClaims: asBool(json['view_claims']),
        manageUsers: asBool(json['manage_users']),
        manageFeedback: asBool(json['manage_feedback']),
        issueStock: asBool(json['issue_stock']),
        viewIssues: asBool(json['view_issues']),
        manageStock: asBool(json['manage_stock']),
        viewReceipts: asBool(json['view_receipts']),
        viewTransfers: asBool(json['view_transfers']),
        createTransfer: asBool(json['create_transfer']),
        viewReturns: asBool(json['view_returns']),
        viewPurchasing: asBool(json['view_purchasing']),
        decidePurchasing: asBool(json['decide_purchasing']),
      );

  Json toJson() => {
        'approve_requests': approveRequests,
        'create_request': createRequest,
        'submit_claim': submitClaim,
        'decide_claims': decideClaims,
        'view_stock_value': viewStockValue,
        'view_claims': viewClaims,
        'manage_users': manageUsers,
        'manage_feedback': manageFeedback,
        'issue_stock': issueStock,
        'view_issues': viewIssues,
        'manage_stock': manageStock,
        'view_receipts': viewReceipts,
        'view_transfers': viewTransfers,
        'create_transfer': createTransfer,
        'view_returns': viewReturns,
        'view_purchasing': viewPurchasing,
        'decide_purchasing': decidePurchasing,
      };
}

class User {
  const User({
    required this.id,
    required this.name,
    required this.email,
    this.phone,
    required this.role,
    required this.roleLabel,
    required this.mustChangePassword,
    required this.currency,
    required this.currencyDecimals,
    required this.companyName,
    this.appSubtitle = '',
    this.avatarUrl,
    required this.can,
  });

  final int id;
  final String name;
  final String email;
  final String? phone;
  final String role;
  final String roleLabel;
  final bool mustChangePassword;
  final String currency;
  final int currencyDecimals;
  final String companyName;
  final String appSubtitle;

  /// Profile photo (public URL), or null for initials.
  final String? avatarUrl;
  final UserCan can;

  String get initials {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts.first[0].toUpperCase();
    return (parts.first[0] + parts.last[0]).toUpperCase();
  }

  factory User.fromJson(Json json) => User(
        id: asInt(json['id']),
        name: asString(json['name']),
        email: asString(json['email']),
        phone: asStringOrNull(json['phone']),
        role: asString(json['role']),
        roleLabel: asString(json['role_label'], asString(json['role'])),
        mustChangePassword: asBool(json['must_change_password']),
        currency: asString(json['currency']),
        currencyDecimals: asInt(json['currency_decimals'], 0).clamp(0, 6),
        companyName: asString(json['company_name']),
        appSubtitle: asString(json['app_subtitle']),
        avatarUrl: asStringOrNull(json['avatar_url']),
        can: UserCan.fromJson(asMap(json['can'])),
      );

  Json toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'phone': phone,
        'role': role,
        'role_label': roleLabel,
        'must_change_password': mustChangePassword,
        'currency': currency,
        'currency_decimals': currencyDecimals,
        'company_name': companyName,
        'app_subtitle': appSubtitle,
        'avatar_url': avatarUrl,
        'can': can.toJson(),
      };

  User copyWith({bool? mustChangePassword}) => User(
        id: id,
        name: name,
        email: email,
        phone: phone,
        role: role,
        roleLabel: roleLabel,
        mustChangePassword: mustChangePassword ?? this.mustChangePassword,
        currency: currency,
        currencyDecimals: currencyDecimals,
        companyName: companyName,
        appSubtitle: appSubtitle,
        avatarUrl: avatarUrl,
        can: can,
      );
}
