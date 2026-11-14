import 'package:dio/dio.dart';

/// What kind of failure an [ApiException] represents. The UI and the session
/// handling switch on this rather than on raw status codes.
enum ApiErrorKind {
  /// No connection, DNS failure, TLS failure, connection refused.
  network,

  /// Connect / send / receive timeout.
  timeout,

  /// 401: missing, expired or revoked token.
  unauthorized,

  /// 403 with `code: forbidden` (or no code).
  forbidden,

  /// 403 with `code: password_change_required`.
  passwordChangeRequired,

  /// 403 with `code: deactivated`.
  deactivated,

  /// 404.
  notFound,

  /// 409: the workflow refused the action / record changed since loaded.
  conflict,

  /// 422 with field errors.
  validation,

  /// 429.
  rateLimited,

  /// 5xx.
  server,

  /// Anything else (unexpected status, malformed body, cancelled...).
  unknown,
}

/// A typed error produced from any failed API call.
class ApiException implements Exception {
  ApiException({
    required this.kind,
    required this.message,
    this.statusCode,
    this.code,
    Map<String, List<String>>? fieldErrors,
  }) : fieldErrors = fieldErrors ?? const {};

  final ApiErrorKind kind;

  /// Human readable message, safe to show to the user.
  final String message;
  final int? statusCode;

  /// The `code` field of a 403 body (`forbidden`, `deactivated`,
  /// `password_change_required`).
  final String? code;

  /// 422 `errors` map: field name to messages.
  final Map<String, List<String>> fieldErrors;

  /// First error message for [field], or null.
  String? fieldError(String field) {
    final list = fieldErrors[field];
    return (list == null || list.isEmpty) ? null : list.first;
  }

  /// Field errors whose key starts with [prefix] (e.g. `lines.0.`).
  Map<String, List<String>> fieldErrorsWithPrefix(String prefix) => {
        for (final e in fieldErrors.entries)
          if (e.key.startsWith(prefix)) e.key: e.value,
      };

  bool get isConflict => kind == ApiErrorKind.conflict;
  bool get isValidation => kind == ApiErrorKind.validation;

  /// Builds an exception from an HTTP status and decoded JSON body.
  factory ApiException.fromResponse(int? status, Object? body) {
    final map = body is Map ? body : const {};
    final serverMessage = map['message'] is String && (map['message'] as String).trim().isNotEmpty
        ? map['message'] as String
        : null;
    final code = map['code'] is String ? map['code'] as String : null;

    switch (status) {
      case 401:
        return ApiException(
          kind: ApiErrorKind.unauthorized,
          statusCode: 401,
          message: 'Your session has ended. Please sign in again.',
        );
      case 403:
        final kind = switch (code) {
          'password_change_required' => ApiErrorKind.passwordChangeRequired,
          'deactivated' => ApiErrorKind.deactivated,
          _ => ApiErrorKind.forbidden,
        };
        final fallback = switch (kind) {
          ApiErrorKind.passwordChangeRequired => 'You must change your password before continuing.',
          ApiErrorKind.deactivated => 'Your account has been deactivated. Contact your administrator.',
          _ => 'You are not allowed to do that.',
        };
        return ApiException(
          kind: kind,
          statusCode: 403,
          code: code,
          message: serverMessage ?? fallback,
        );
      case 404:
        return ApiException(
          kind: ApiErrorKind.notFound,
          statusCode: 404,
          message: 'This record was not found or is no longer visible to you.',
        );
      case 409:
        return ApiException(
          kind: ApiErrorKind.conflict,
          statusCode: 409,
          message: serverMessage ?? 'This record changed since you opened it.',
        );
      case 422:
        return ApiException(
          kind: ApiErrorKind.validation,
          statusCode: 422,
          message: serverMessage ?? 'Please check the highlighted fields.',
          fieldErrors: _parseFieldErrors(map['errors']),
        );
      case 429:
        return ApiException(
          kind: ApiErrorKind.rateLimited,
          statusCode: 429,
          message: 'Too many attempts. Please wait a minute and try again.',
        );
    }
    if (status != null && status >= 500) {
      return ApiException(
        kind: ApiErrorKind.server,
        statusCode: status,
        message: 'The server had a problem. Please try again shortly.',
      );
    }
    return ApiException(
      kind: ApiErrorKind.unknown,
      statusCode: status,
      message: serverMessage ?? 'Something went wrong. Please try again.',
    );
  }

  /// Maps any [DioException] to an [ApiException].
  factory ApiException.fromDio(DioException e) {
    final inner = e.error;
    if (inner is ApiException) return inner;
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.transformTimeout:
        return ApiException(
          kind: ApiErrorKind.timeout,
          message: 'The server took too long to respond. Check your connection and try again.',
        );
      case DioExceptionType.connectionError:
        return ApiException(
          kind: ApiErrorKind.network,
          message: 'No connection to the server. Check your internet and try again.',
        );
      case DioExceptionType.badCertificate:
        return ApiException(
          kind: ApiErrorKind.network,
          message: 'Secure connection to the server failed.',
        );
      case DioExceptionType.cancel:
        return ApiException(kind: ApiErrorKind.unknown, message: 'Request cancelled.');
      case DioExceptionType.badResponse:
        return ApiException.fromResponse(e.response?.statusCode, e.response?.data);
      case DioExceptionType.unknown:
        if (e.response != null) {
          return ApiException.fromResponse(e.response?.statusCode, e.response?.data);
        }
        return ApiException(
          kind: ApiErrorKind.network,
          message: 'No connection to the server. Check your internet and try again.',
        );
    }
  }

  /// Converts anything thrown by the data layer into a user-facing message.
  static String messageOf(Object error) {
    if (error is ApiException) return error.message;
    if (error is DioException) return ApiException.fromDio(error).message;
    return 'Something went wrong. Please try again.';
  }

  static Map<String, List<String>> _parseFieldErrors(Object? raw) {
    if (raw is! Map) return const {};
    final out = <String, List<String>>{};
    raw.forEach((key, value) {
      if (value is List) {
        out['$key'] = value.map((v) => '$v').toList();
      } else if (value != null) {
        out['$key'] = ['$value'];
      }
    });
    return out;
  }

  @override
  String toString() => 'ApiException($kind, $statusCode, $code): $message';
}
