import '../../../core/api/json.dart';

class UserCan {
  const UserCan({
    this.approveRequests = false,
    this.createRequest = false,
    this.submitClaim = false,
    this.decideClaims = false,
    this.viewStockValue = false,
    this.viewClaims = false,
  });

  final bool approveRequests;
  final bool createRequest;
  final bool submitClaim;
  final bool decideClaims;
  final bool viewStockValue;
  final bool viewClaims;

  factory UserCan.fromJson(Json json) => UserCan(
        approveRequests: asBool(json['approve_requests']),
        createRequest: asBool(json['create_request']),
        submitClaim: asBool(json['submit_claim']),
        decideClaims: asBool(json['decide_claims']),
        viewStockValue: asBool(json['view_stock_value']),
        viewClaims: asBool(json['view_claims']),
      );

  Json toJson() => {
        'approve_requests': approveRequests,
        'create_request': createRequest,
        'submit_claim': submitClaim,
        'decide_claims': decideClaims,
        'view_stock_value': viewStockValue,
        'view_claims': viewClaims,
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
        can: can,
      );
}
