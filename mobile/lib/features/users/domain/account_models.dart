import '../../../core/api/json.dart';
import '../../../core/api/refs.dart';

/// A user account as the admin sees it (`/users`).
class Account {
  const Account({
    required this.id,
    required this.name,
    required this.email,
    this.phone,
    required this.role,
    required this.roleLabel,
    required this.profileLabel,
    this.customRoleId,
    required this.isActive,
    required this.mustChangePassword,
    required this.projectsCount,
    this.projects = const [],
  });

  final int id;
  final String name;
  final String email;
  final String? phone;
  final String role;

  /// Custom role name if one is set, else the standard role label.
  final String roleLabel;

  /// Label of the permission profile (`role`) behind it.
  final String profileLabel;
  final int? customRoleId;
  final bool isActive;
  final bool mustChangePassword;
  final int projectsCount;

  /// Only filled on the detail endpoint.
  final List<CodedRef> projects;

  String get initials {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts.first[0].toUpperCase();
    return (parts.first[0] + parts.last[0]).toUpperCase();
  }

  /// Staff and project managers see nothing until assigned to a project.
  bool get needsProject => projectsCount == 0 && (role == 'staff' || role == 'project_manager');

  factory Account.fromJson(Json json) => Account(
        id: asInt(json['id']),
        name: asString(json['name']),
        email: asString(json['email']),
        phone: asStringOrNull(json['phone']),
        role: asString(json['role']),
        roleLabel: asString(json['role_label'], asString(json['role'])),
        profileLabel: asString(json['profile_label'], asString(json['role_label'])),
        customRoleId: asIntOrNull(json['custom_role_id']),
        isActive: asBool(json['is_active'], true),
        mustChangePassword: asBool(json['must_change_password']),
        projectsCount: asInt(json['projects_count']),
        projects: asList(json['projects'], CodedRef.fromJson),
      );
}

class RoleOption {
  const RoleOption(this.value, this.label);
  final String value;
  final String label;

  factory RoleOption.fromJson(Json json) => RoleOption(asString(json['value']), asString(json['label']));
}

class CustomRoleOption {
  const CustomRoleOption({required this.id, required this.name, required this.profile});
  final int id;
  final String name;

  /// The standard role (permission profile) this name stands for.
  final String profile;

  factory CustomRoleOption.fromJson(Json json) => CustomRoleOption(
        id: asInt(json['id']),
        name: asString(json['name']),
        profile: asString(json['permission_profile']),
      );
}

class ProjectOption {
  const ProjectOption({required this.ref, required this.status});
  final CodedRef ref;
  final String status;

  bool get isClosed => !const ['planned', 'active', 'on_hold'].contains(status);

  factory ProjectOption.fromJson(Json json) => ProjectOption(ref: CodedRef.fromJson(json), status: asString(json['status']));
}

/// Choices for the add / edit user form (`/users/options`).
class UserFormOptions {
  const UserFormOptions({
    required this.roles,
    required this.customRoles,
    required this.projects,
    this.total = 0,
    this.active = 0,
    this.inactive = 0,
  });

  final List<RoleOption> roles;
  final List<CustomRoleOption> customRoles;
  final List<ProjectOption> projects;
  final int total;
  final int active;
  final int inactive;

  String roleLabel(String value) =>
      roles.where((r) => r.value == value).map((r) => r.label).firstOrNull ?? value;

  factory UserFormOptions.fromJson(Json json) {
    final stats = asMap(json['stats']);
    return UserFormOptions(
      roles: asList(json['roles'], RoleOption.fromJson),
      customRoles: asList(json['custom_roles'], CustomRoleOption.fromJson),
      projects: asList(json['projects'], ProjectOption.fromJson),
      total: asInt(stats['total']),
      active: asInt(stats['active']),
      inactive: asInt(stats['inactive']),
    );
  }
}

/// Result of creating or updating a user. [temporaryPassword] is only set when
/// the sign-in e-mail could not be sent and the admin must hand it over.
class SaveAccountResult {
  const SaveAccountResult({required this.account, this.credentialsEmailed, this.temporaryPassword});
  final Account account;
  final bool? credentialsEmailed;
  final String? temporaryPassword;

  factory SaveAccountResult.fromJson(Json json) => SaveAccountResult(
        account: Account.fromJson(asMap(json['data'])),
        credentialsEmailed: json['credentials_emailed'] == null ? null : asBool(json['credentials_emailed']),
        temporaryPassword: asStringOrNull(json['temporary_password']),
      );
}

/// What the form sends.
class AccountInput {
  const AccountInput({
    required this.name,
    required this.email,
    this.phone,
    required this.role,
    this.customRoleId,
    required this.isActive,
    this.password,
    this.resetPassword = false,
    this.projectIds = const [],
  });

  final String name;
  final String email;
  final String? phone;
  final String role;
  final int? customRoleId;
  final bool isActive;
  final String? password;
  final bool resetPassword;
  final List<int> projectIds;

  Json toJson() => {
        'name': name.trim(),
        'email': email.trim(),
        'phone': (phone ?? '').trim().isEmpty ? null : phone!.trim(),
        'role': role,
        'custom_role_id': customRoleId,
        'is_active': isActive,
        if ((password ?? '').isNotEmpty) 'password': password,
        if (resetPassword) 'reset_password': true,
        'project_ids': projectIds,
      };
}

/// Filters for the list.
class AccountStatuses {
  static const filters = <(String?, String)>[
    (null, 'All'),
    ('active', 'Active'),
    ('inactive', 'Inactive'),
  ];
}
