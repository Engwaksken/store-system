/// Compile-time configuration, set with `--dart-define`.
///
/// ```sh
/// flutter run --dart-define=API_BASE_URL=https://staging.example.com/api/v1
/// flutter run --dart-define=USE_FAKE_API=true
/// ```
class AppConfig {
  const AppConfig({required this.apiBaseUrl, required this.useFakeApi});

  /// Values baked in at build time.
  static const AppConfig fromEnvironment = AppConfig(
    apiBaseUrl: String.fromEnvironment(
      'API_BASE_URL',
      defaultValue: 'https://store.kemmytech.com/api/v1',
    ),
    useFakeApi: bool.fromEnvironment('USE_FAKE_API', defaultValue: false),
  );

  /// Base URL of the JSON API, without a trailing slash.
  final String apiBaseUrl;

  /// When true, every API call is answered by an in-memory fake backend.
  final bool useFakeApi;

  String get normalizedBaseUrl => apiBaseUrl.endsWith('/')
      ? apiBaseUrl.substring(0, apiBaseUrl.length - 1)
      : apiBaseUrl;

  static const String appName = 'Kemmy Store';
}
