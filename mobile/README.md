# Kemmy Store (mobile)

Native Flutter app (Android + iOS) for the Kemmy Tech construction store-management system.
It talks to the Laravel JSON API described in [`../docs/API.md`](../docs/API.md) (v1, Sanctum bearer tokens).

## Requirements

- Flutter 3.44 / Dart 3.12 (stable)
- Android SDK (API 36 platform, build-tools) and JDK 17 for Android builds
- A Mac with Xcode for iOS builds

## Running

```sh
cd mobile
flutter pub get

# Against the real API (default base URL https://store.kemmytech.com/api/v1)
flutter run

# Against another server (staging, local Laravel via LAN IP, ...)
flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000/api/v1

# Demo mode: in-memory fake backend, no server needed
flutter run --dart-define=USE_FAKE_API=true
```

| dart-define | Default | Meaning |
|---|---|---|
| `API_BASE_URL` | `https://store.kemmytech.com/api/v1` | API root, without trailing slash |
| `USE_FAKE_API` | `false` | `true` answers every call from `lib/core/fake/fake_backend.dart` |

### Fake (demo) mode

The fake backend implements the whole contract in memory (pagination, filters, 401/403/404/409/422,
`password_change_required`, `avg_cost` visibility, version / `seen_at` conflict checks, multipart upload
with progress). Data resets when the app restarts. Sign in with **any e-mail** and the password **`password1`**:

| E-mail contains | Persona |
|---|---|
| `engineer` (e.g. `engineer@kemmy.test`) | Site Engineer: no Approvals tab, no stock value, own requests only |
| `new` (e.g. `new@kemmy.test`) | Project Manager who must change password first |
| `deactivated` | 403 `deactivated` on login |
| anything else (e.g. `brian@kemmy.test`) | Project Manager with every permission |

Fake mode keeps the token in memory (not the keystore), so every app start begins at the login screen.

## Building

```sh
# Android debug APK -> build/app/outputs/flutter-apk/app-debug.apk
flutter build apk --debug

# Android release (configure signing first, see TODO) -> app-release.apk / app-release.aab
flutter build apk --release --dart-define=API_BASE_URL=https://store.kemmytech.com/api/v1
flutter build appbundle --release

# iOS (on a Mac with Xcode; open ios/Runner.xcworkspace once to set the signing team)
flutter build ipa --release
```

Note for Windows: if the project and the pub cache are on different drives, Kotlin incremental compilation
fails ("this and base files have different roots"); `android/gradle.properties` sets `kotlin.incremental=false`
to avoid that.

## Tests

```sh
flutter analyze
flutter test
```

- `test/unit/models_test.dart`: JSON parsing of every model (decimal strings, nulls, `can` blocks).
- `test/unit/api_exception_test.dart`: 401 / 403 codes / 404 / 409 / 422 / 429 / 5xx mapping, session events
  from the interceptor, token only sent to the API origin.
- `test/unit/formatters_test.dart`: money / quantity / date formatting (exact, no doubles).
- `test/unit/validation_test.dart`: approve-editor qty validation, amounts, passwords, receipt files.
- `test/unit/router_and_fake_test.dart`: auth redirects and fake-backend contract behaviour.
- `test/widget/`: login validation and role-based tabs; request detail shows Approve only when `can.decide`,
  approve editor validation and submit (all against the fake API).

## Project structure

```
lib/
  main.dart, app.dart              ProviderScope, MaterialApp.router, themes
  core/
    config/app_config.dart         dart-define settings
    api/                           Dio client + auth interceptor, ApiException, pagination, JSON helpers,
                                   token storage (flutter_secure_storage), providers
    fake/                          FakeBackend (in-memory contract) + Dio adapter
    format/formatters.dart         money / qty / dates (decimal strings -> display, via intl)
    router/app_router.dart         go_router routes + auth redirect
    theme/app_theme.dart           brand colours, status tones, light/dark themes
    util/data_revision.dart        "something changed" counter that refreshes lists/dashboard
    widgets/                       status badge, empty/error/skeleton views, paged list, dialogs
  features/
    auth/          login, splash (session restore), change password, AuthController
    dashboard/     home: cards, waiting for me, quick actions
    requests/      approvals, requests list, detail, approve editor, new request
    stock/         item search (store filter, low stock), item detail, item picker
    claims/        list, detail (receipt download/preview), new claim (camera/gallery/file upload)
    notifications/ list, mark read / all read, navigate to target
    more/          more menu, profile, about
    shell/         bottom navigation (tabs from user.can)
tool/generate_icon.dart            generates the placeholder launcher icon
```

**State:** Riverpod (`flutter_riverpod` 3). Server data is exposed with `FutureProvider`s (detail screens,
dashboard, options); paginated lists keep their pages in a small widget-local controller (`PagedListView`)
and reload when `dataRevisionProvider` is bumped after any write. Automatic provider retries are disabled
so errors surface with a "Try again" button.

**Session handling:** the Dio interceptor adds `Authorization`/`Accept`, maps every failure to
`ApiException`, and emits session events: 401 clears the token and returns to login; 403
`password_change_required` flips `must_change_password`, and the router forces the change-password
screen; 403 `deactivated` signs out with a message.

**Money/quantities** stay decimal strings end to end; comparisons use `package:decimal`, display uses
`currency_decimals` from `/me`.

## TODO

- **Push notifications (FCM/APNs):** needs a Firebase project. Hook points: `HomeShell` lifecycle listener
  (where the badge is refreshed on resume), `dataRevisionProvider.bump()` on message receipt, and
  `NotificationTarget.route` for tap-to-open. The API will need a device-token endpoint.
- **Release signing:** add `android/key.properties` + a `signingConfigs.release` block in
  `android/app/build.gradle.kts` (release currently signs with the debug key), and set the iOS team in Xcode.
- **Launcher icon:** `assets/icon/*.png` is a generated placeholder (gold tile, navy "K"). Replace it with
  the real artwork and run `dart run flutter_launcher_icons`.
- Edit / resubmit drafts and rejected requests, and editing/deleting claims (`can.edit` / `can.delete` are
  parsed but not yet used in the UI).
- Offline cache of the last loaded lists.
