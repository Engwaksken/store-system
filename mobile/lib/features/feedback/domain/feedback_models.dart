import '../../../core/api/json.dart';
import '../../../core/api/refs.dart';

class FeedbackItem {
  const FeedbackItem({
    required this.id,
    required this.reference,
    required this.category,
    required this.categoryLabel,
    required this.subject,
    required this.message,
    this.rating,
    required this.source,
    this.appVersion,
    this.device,
    required this.status,
    required this.statusLabel,
    required this.statusTone,
    this.user,
    this.response,
    this.responder,
    this.respondedAt,
    this.createdAt,
    required this.canRespond,
  });

  final int id;
  final String reference;
  final String category;
  final String categoryLabel;
  final String subject;
  final String message;
  final int? rating;

  /// `web` or `mobile`.
  final String source;

  /// Only sent to admins.
  final String? appVersion;
  final String? device;
  final String status;
  final String statusLabel;
  final String statusTone;
  final NamedRef? user;
  final String? response;
  final NamedRef? responder;
  final String? respondedAt;
  final String? createdAt;
  final bool canRespond;

  bool get hasReply => (response ?? '').isNotEmpty;

  factory FeedbackItem.fromJson(Json json) => FeedbackItem(
        id: asInt(json['id']),
        reference: asString(json['reference']),
        category: asString(json['category']),
        categoryLabel: asString(json['category_label'], asString(json['category'])),
        subject: asString(json['subject']),
        message: asString(json['message']),
        rating: asIntOrNull(json['rating']),
        source: asString(json['source'], 'web'),
        appVersion: asStringOrNull(json['app_version']),
        device: asStringOrNull(json['device']),
        status: asString(json['status']),
        statusLabel: asString(json['status_label'], asString(json['status'])),
        statusTone: asString(json['status_tone']),
        user: NamedRef.maybe(json['user']),
        response: asStringOrNull(json['response']),
        responder: NamedRef.maybe(json['responder']),
        respondedAt: asStringOrNull(json['responded_at']),
        createdAt: asStringOrNull(json['created_at']),
        canRespond: asBool(json['can_respond']),
      );
}

/// `{value, label}` choice from `/feedback/options`.
class FeedbackChoice {
  const FeedbackChoice(this.value, this.label);
  final String value;
  final String label;

  factory FeedbackChoice.fromJson(Json json) => FeedbackChoice(asString(json['value']), asString(json['label']));
}

class FeedbackOptions {
  const FeedbackOptions({required this.categories, required this.statuses});
  final List<FeedbackChoice> categories;
  final List<FeedbackChoice> statuses;

  /// Used when `/feedback/options` cannot be reached; matches the server.
  static const fallback = FeedbackOptions(
    categories: [
      FeedbackChoice('bug', 'Problem / bug'),
      FeedbackChoice('suggestion', 'Suggestion'),
      FeedbackChoice('question', 'Question'),
      FeedbackChoice('praise', 'Praise'),
      FeedbackChoice('other', 'Other'),
    ],
    statuses: [
      FeedbackChoice('new', 'New'),
      FeedbackChoice('in_review', 'In review'),
      FeedbackChoice('resolved', 'Resolved'),
      FeedbackChoice('closed', 'Closed'),
    ],
  );

  factory FeedbackOptions.fromJson(Json json) {
    final categories = asList(json['categories'], FeedbackChoice.fromJson);
    final statuses = asList(json['statuses'], FeedbackChoice.fromJson);
    return FeedbackOptions(
      categories: categories.isEmpty ? fallback.categories : categories,
      statuses: statuses.isEmpty ? fallback.statuses : statuses,
    );
  }
}

class FeedbackStatuses {
  static const filters = <(String?, String)>[
    (null, 'All'),
    ('new', 'New'),
    ('in_review', 'In review'),
    ('resolved', 'Resolved'),
    ('closed', 'Closed'),
  ];
}

/// Five-star rating text, e.g. `★★★☆☆`.
String ratingStars(int rating) => '${'★' * rating.clamp(0, 5)}${'☆' * (5 - rating.clamp(0, 5))}';

const ratingLabels = {5: 'Excellent', 4: 'Good', 3: 'Okay', 2: 'Poor', 1: 'Very poor'};
