import 'dart:async';
import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../config/app_config.dart';
import 'api_exception.dart';
import 'json.dart';
import 'token_storage.dart';

/// Session-level events raised by the HTTP layer. The auth controller listens
/// and updates the app state (which the router reacts to).
enum SessionEvent { unauthorized, passwordChangeRequired, deactivated }

class SessionEvents {
  final _controller = StreamController<SessionEvent>.broadcast();
  Stream<SessionEvent> get stream => _controller.stream;
  void emit(SessionEvent e) => _controller.add(e);
  void dispose() => _controller.close();
}

/// Per-request flag: do not raise session events for this call (login,
/// logout, the initial /me restore which handles 401 itself).
const kSkipSessionEvents = 'skipSessionEvents';

class AuthInterceptor extends Interceptor {
  AuthInterceptor({
    required this.tokenStorage,
    required this.baseUri,
    required this.events,
  });

  final TokenStorage tokenStorage;
  final Uri baseUri;
  final SessionEvents events;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers['Accept'] = 'application/json';
    final token = tokenStorage.cachedToken;
    // Only ever send the token to our own API host (receipt_url is absolute).
    if (token != null && _sameOrigin(options.uri)) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final apiEx = ApiException.fromDio(err);
    if (err.requestOptions.extra[kSkipSessionEvents] != true) {
      switch (apiEx.kind) {
        case ApiErrorKind.unauthorized:
          events.emit(SessionEvent.unauthorized);
        case ApiErrorKind.passwordChangeRequired:
          events.emit(SessionEvent.passwordChangeRequired);
        case ApiErrorKind.deactivated:
          events.emit(SessionEvent.deactivated);
        default:
          break;
      }
    }
    handler.next(err.copyWith(error: apiEx));
  }

  bool _sameOrigin(Uri uri) =>
      uri.scheme == baseUri.scheme && uri.host == baseUri.host && uri.port == baseUri.port;
}

Dio buildDio({
  required AppConfig config,
  required TokenStorage tokenStorage,
  required SessionEvents events,
  HttpClientAdapter? adapter,
}) {
  final base = '${config.normalizedBaseUrl}/';
  final dio = Dio(BaseOptions(
    baseUrl: base,
    connectTimeout: const Duration(seconds: 15),
    receiveTimeout: const Duration(seconds: 30),
    sendTimeout: const Duration(seconds: 60),
    headers: {'Accept': 'application/json'},
    responseType: ResponseType.json,
  ));
  if (adapter != null) dio.httpClientAdapter = adapter;
  dio.interceptors.add(AuthInterceptor(
    tokenStorage: tokenStorage,
    baseUri: Uri.parse(base),
    events: events,
  ));
  return dio;
}

/// Downloaded binary (e.g. a claim receipt).
class DownloadedFile {
  const DownloadedFile({required this.bytes, required this.contentType, this.fileName});
  final Uint8List bytes;
  final String contentType;
  final String? fileName;

  bool get isImage => contentType.startsWith('image/');
  bool get isPdf => contentType.contains('pdf');
}

/// Thin wrapper over Dio that returns decoded JSON maps and throws
/// [ApiException] for every failure.
class ApiClient {
  ApiClient(this.dio);
  final Dio dio;

  Future<Json> get(String path, {Map<String, dynamic>? query, bool skipSession = false}) =>
      _run(() => dio.get<Object?>(
            _rel(path),
            queryParameters: _clean(query),
            options: Options(extra: {kSkipSessionEvents: skipSession}),
          ));

  Future<Json> post(String path, {Object? body, bool skipSession = false}) =>
      _run(() => dio.post<Object?>(
            _rel(path),
            data: body,
            options: Options(extra: {kSkipSessionEvents: skipSession}),
          ));

  Future<Json> postMultipart(
    String path,
    FormData form, {
    void Function(int sent, int total)? onSendProgress,
    CancelToken? cancelToken,
  }) =>
      _run(() => dio.post<Object?>(
            _rel(path),
            data: form,
            onSendProgress: onSendProgress,
            cancelToken: cancelToken,
          ));

  /// Authenticated binary download. [url] may be absolute (same origin) or relative.
  Future<DownloadedFile> download(String url) async {
    try {
      final res = await dio.get<List<int>>(
        url.startsWith('http') ? url : _rel(url),
        options: Options(responseType: ResponseType.bytes),
      );
      final type = res.headers.value(Headers.contentTypeHeader) ?? 'application/octet-stream';
      final disposition = res.headers.value('content-disposition');
      return DownloadedFile(
        bytes: Uint8List.fromList(res.data ?? const []),
        contentType: type,
        fileName: _fileNameFrom(disposition),
      );
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  Future<Json> _run(Future<Response<Object?>> Function() call) async {
    try {
      final res = await call();
      final data = res.data;
      if (data is Map) return data.cast<String, dynamic>();
      return <String, dynamic>{}; // 204 No Content and friends.
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  /// Paths are relative to the base URL (`/me` -> `me`).
  static String _rel(String path) => path.startsWith('/') ? path.substring(1) : path;

  static Map<String, dynamic>? _clean(Map<String, dynamic>? q) {
    if (q == null) return null;
    final out = <String, dynamic>{};
    q.forEach((k, v) {
      if (v == null) return;
      if (v is String && v.trim().isEmpty) return;
      out[k] = v is bool ? (v ? 1 : 0) : v;
    });
    return out;
  }

  static String? _fileNameFrom(String? disposition) {
    if (disposition == null) return null;
    final match = RegExp(r'filename="?([^";]+)"?').firstMatch(disposition);
    return match?.group(1);
  }
}
