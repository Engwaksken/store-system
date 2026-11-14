import '../../../core/api/json.dart';

class NotificationTarget {
  const NotificationTarget({this.type, this.id});

  /// `request` | `claim` | null.
  final String? type;
  final int? id;

  /// In-app route for this target, or null when the app cannot open it.
  String? get route {
    if (id == null) return null;
    return switch (type) {
      'request' => '/request/$id',
      'claim' => '/claims/$id',
      _ => null,
    };
  }

  factory NotificationTarget.fromJson(Json json) => NotificationTarget(
        type: asStringOrNull(json['type']),
        id: asIntOrNull(json['id']),
      );
}

class AppNotification {
  const AppNotification({
    required this.id,
    required this.subject,
    required this.body,
    required this.kind,
    required this.read,
    this.createdAt,
    this.target,
  });

  final String id;
  final String subject;
  final String body;

  /// `info | warn | bad | ok`.
  final String kind;
  final bool read;
  final String? createdAt;
  final NotificationTarget? target;

  AppNotification markRead() => AppNotification(
        id: id,
        subject: subject,
        body: body,
        kind: kind,
        read: true,
        createdAt: createdAt,
        target: target,
      );

  factory AppNotification.fromJson(Json json) {
    final t = asMapOrNull(json['target']);
    return AppNotification(
      id: asString(json['id']),
      subject: asString(json['subject']),
      body: asString(json['body']),
      kind: asString(json['kind'], 'info'),
      read: asBool(json['read']),
      createdAt: asStringOrNull(json['created_at']),
      target: t == null ? null : NotificationTarget.fromJson(t),
    );
  }
}
