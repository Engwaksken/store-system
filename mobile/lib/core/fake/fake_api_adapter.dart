import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

import 'fake_backend.dart';

/// A Dio [HttpClientAdapter] that answers every request from [FakeBackend],
/// so the whole client stack (interceptors, error mapping, parsing) runs
/// unchanged in fake mode.
class FakeApiAdapter implements HttpClientAdapter {
  FakeApiAdapter(this.backend, {required String baseUrl, this.latency = const Duration(milliseconds: 350)})
      : _basePath = Uri.parse(baseUrl).path.replaceFirst(RegExp(r'/+$'), '');

  final FakeBackend backend;
  final Duration latency;
  final String _basePath;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    // Drain the body so Dio reports upload progress (multipart).
    if (requestStream != null) {
      await for (final _ in requestStream) {
        if (latency > Duration.zero) await Future<void>.delayed(const Duration(milliseconds: 15));
      }
    }
    if (latency > Duration.zero) await Future<void>.delayed(latency);

    var path = options.uri.path;
    if (_basePath.isNotEmpty && path.startsWith(_basePath)) path = path.substring(_basePath.length);

    Object? body = options.data;
    final fields = <String, String>{};
    final files = <String, FakeUpload>{};
    if (body is FormData) {
      for (final f in body.fields) {
        fields[f.key] = f.value;
      }
      for (final f in body.files) {
        files[f.key] = FakeUpload(filename: f.value.filename ?? 'file', length: f.value.length);
      }
      body = null;
    } else if (body is String && body.isNotEmpty) {
      body = jsonDecode(body);
    }

    final res = backend.handle(
      method: options.method.toUpperCase(),
      path: path,
      query: options.uri.queryParameters,
      body: body,
      fields: fields,
      files: files,
      authorization: options.headers['Authorization'] as String?,
    );

    if (res.bytes != null) {
      return ResponseBody.fromBytes(
        Uint8List.fromList(res.bytes!),
        res.status,
        headers: {
          Headers.contentTypeHeader: [res.contentType],
        },
      );
    }
    return ResponseBody.fromString(
      res.body == null ? '' : jsonEncode(res.body),
      res.status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
