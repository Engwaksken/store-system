import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:store_system/core/api/api_client.dart';
import 'package:store_system/core/api/api_exception.dart';
import 'package:store_system/core/api/token_storage.dart';
import 'package:store_system/core/config/app_config.dart';
import 'package:store_system/core/fake/fake_api_adapter.dart';
import 'package:store_system/core/fake/fake_backend.dart';

import '../helpers.dart';

void main() {
  group('ApiException.fromResponse', () {
    test('401', () {
      final e = ApiException.fromResponse(401, {'message': 'Unauthenticated.'});
      expect(e.kind, ApiErrorKind.unauthorized);
      expect(e.statusCode, 401);
    });

    test('403 codes', () {
      expect(
        ApiException.fromResponse(403, {'message': 'x', 'code': 'password_change_required'}).kind,
        ApiErrorKind.passwordChangeRequired,
      );
      expect(ApiException.fromResponse(403, {'message': 'x', 'code': 'deactivated'}).kind, ApiErrorKind.deactivated);
      final forbidden = ApiException.fromResponse(403, {'message': 'Not on this project.', 'code': 'forbidden'});
      expect(forbidden.kind, ApiErrorKind.forbidden);
      expect(forbidden.code, 'forbidden');
      expect(forbidden.message, 'Not on this project.');
      expect(ApiException.fromResponse(403, null).kind, ApiErrorKind.forbidden);
    });

    test('404 / 409 / 429 / 5xx', () {
      expect(ApiException.fromResponse(404, {'message': 'Not found.'}).kind, ApiErrorKind.notFound);
      final conflict = ApiException.fromResponse(409, {
        'message': 'Request MR-0001 is Approved; this action is not allowed now.',
      });
      expect(conflict.isConflict, isTrue);
      expect(conflict.message, contains('MR-0001'));
      expect(ApiException.fromResponse(429, {'message': 'Too Many Attempts.'}).kind, ApiErrorKind.rateLimited);
      expect(ApiException.fromResponse(503, '<html>').kind, ApiErrorKind.server);
    });

    test('422 field errors', () {
      final e = ApiException.fromResponse(422, {
        'message': 'The purpose field is required. (and 1 more error)',
        'errors': {
          'purpose': ['The purpose field is required.'],
          'lines.0.requested_qty': ['Quantity must be more than 0.', 'Second'],
        },
      });
      expect(e.isValidation, isTrue);
      expect(e.fieldError('purpose'), 'The purpose field is required.');
      expect(e.fieldError('lines.0.requested_qty'), 'Quantity must be more than 0.');
      expect(e.fieldError('missing'), isNull);
      expect(e.fieldErrorsWithPrefix('lines.0.').keys, ['lines.0.requested_qty']);
    });
  });

  group('ApiException.fromDio', () {
    RequestOptions ro() => RequestOptions(path: '/x');

    test('timeouts and connection errors', () {
      expect(
        ApiException.fromDio(DioException(requestOptions: ro(), type: DioExceptionType.receiveTimeout)).kind,
        ApiErrorKind.timeout,
      );
      expect(
        ApiException.fromDio(DioException(requestOptions: ro(), type: DioExceptionType.connectionError)).kind,
        ApiErrorKind.network,
      );
    });

    test('bad response delegates to status mapping', () {
      final e = ApiException.fromDio(DioException(
        requestOptions: ro(),
        type: DioExceptionType.badResponse,
        response: Response(requestOptions: ro(), statusCode: 409, data: {'message': 'Changed.'}),
      ));
      expect(e.kind, ApiErrorKind.conflict);
      expect(e.message, 'Changed.');
    });
  });

  group('AuthInterceptor + session events (fake backend)', () {
    late FakeBackend backend;
    late MemoryTokenStorage tokens;
    late SessionEvents events;
    late ApiClient api;
    late List<SessionEvent> emitted;

    setUp(() {
      backend = FakeBackend();
      tokens = MemoryTokenStorage();
      events = SessionEvents();
      emitted = [];
      events.stream.listen(emitted.add);
      api = ApiClient(buildDio(
        config: const AppConfig(apiBaseUrl: testBaseUrl, useFakeApi: true),
        tokenStorage: tokens,
        events: events,
        adapter: FakeApiAdapter(backend, baseUrl: testBaseUrl, latency: Duration.zero),
      ));
    });

    test('401 throws typed exception and emits unauthorized', () async {
      await expectLater(
        api.get('/dashboard'),
        throwsA(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.unauthorized)),
      );
      await Future<void>.delayed(Duration.zero);
      expect(emitted, [SessionEvent.unauthorized]);
    });

    test('skipSession suppresses events', () async {
      await expectLater(api.get('/me', skipSession: true), throwsA(isA<ApiException>()));
      await Future<void>.delayed(Duration.zero);
      expect(emitted, isEmpty);
    });

    test('403 password_change_required emits event', () async {
      await tokens.write(fakeLogin(backend, email: 'new@kemmy.test'));
      // /me stays open...
      final me = await api.get('/me');
      expect((me['user'] as Map)['must_change_password'], isTrue);
      // ...everything else is blocked.
      await expectLater(
        api.get('/requests'),
        throwsA(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.passwordChangeRequired)),
      );
      await Future<void>.delayed(Duration.zero);
      expect(emitted, [SessionEvent.passwordChangeRequired]);
    });

    test('422 login error maps to email field', () async {
      try {
        await api.post('/auth/login',
            body: {'email': 'brian@kemmy.test', 'password': 'wrong', 'device_name': 't'}, skipSession: true);
        fail('should throw');
      } on ApiException catch (e) {
        expect(e.kind, ApiErrorKind.validation);
        expect(e.fieldError('email'), isNotNull);
      }
    });

    test('token is sent only to the API origin', () async {
      await tokens.write(fakeLogin(backend));
      final captured = <RequestOptions>[];
      api.dio.interceptors.add(InterceptorsWrapper(onRequest: (o, h) {
        captured.add(o);
        h.next(o);
      }));
      await api.get('/me');
      try {
        await api.download('https://evil.example.com/file.pdf');
      } catch (_) {}
      expect(captured.first.headers['Authorization'], startsWith('Bearer '));
      expect(captured.last.headers['Authorization'], isNull);
      expect(captured.first.headers['Accept'], 'application/json');
    });
  });
}
