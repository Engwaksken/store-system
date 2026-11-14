import '../../../core/api/json.dart';
import '../../requests/domain/request_models.dart';

class DashboardCard {
  const DashboardCard({required this.key, required this.label, required this.value, required this.tone});
  final String key;
  final String label;

  /// Usually an integer count; kept as the raw JSON value (int or decimal
  /// string) so the UI can format it appropriately.
  final Object? value;

  /// `navy | gold | bad | ok | ""`.
  final String tone;

  factory DashboardCard.fromJson(Json json) => DashboardCard(
        key: asString(json['key']),
        label: asString(json['label']),
        value: json['value'],
        tone: asString(json['tone']),
      );
}

class Dashboard {
  const Dashboard({required this.cards, required this.awaitingMe, required this.unreadNotifications});
  final List<DashboardCard> cards;
  final List<RequestSummary> awaitingMe;
  final int unreadNotifications;

  factory Dashboard.fromJson(Json json) => Dashboard(
        cards: asList(json['cards'], DashboardCard.fromJson),
        awaitingMe: asList(json['awaiting_me'], RequestSummary.fromJson),
        unreadNotifications: asInt(json['unread_notifications']),
      );
}
