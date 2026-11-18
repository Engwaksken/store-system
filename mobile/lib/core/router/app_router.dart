import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/data/auth_controller.dart';
import '../../features/auth/presentation/change_password_screen.dart';
import '../../features/auth/presentation/login_screen.dart';
import '../../features/auth/presentation/splash_screen.dart';
import '../../features/claims/presentation/claim_detail_screen.dart';
import '../../features/claims/presentation/claims_list_screen.dart';
import '../../features/claims/presentation/new_claim_screen.dart';
import '../../features/dashboard/presentation/home_screen.dart';
import '../../features/feedback/presentation/feedback_detail_screen.dart';
import '../../features/feedback/presentation/feedback_list_screen.dart';
import '../../features/feedback/presentation/new_feedback_screen.dart';
import '../../features/more/presentation/about_screen.dart';
import '../../features/more/presentation/edit_profile_screen.dart';
import '../../features/more/presentation/more_screen.dart';
import '../../features/more/presentation/profile_screen.dart';
import '../../features/notifications/presentation/notifications_screen.dart';
import '../../features/operations/presentation/issue_screens.dart';
import '../../features/operations/presentation/purchasing_screens.dart';
import '../../features/operations/presentation/stock_ops_screens.dart';
import '../../features/reports/presentation/reports_screen.dart';
import '../../features/requests/presentation/approvals_screen.dart';
import '../../features/requests/presentation/new_request_screen.dart';
import '../../features/requests/presentation/request_detail_screen.dart';
import '../../features/requests/presentation/requests_list_screen.dart';
import '../../features/shell/presentation/home_shell.dart';
import '../../features/stock/presentation/item_detail_screen.dart';
import '../../features/stock/presentation/stock_screen.dart';
import '../../features/users/presentation/user_form_screen.dart';
import '../../features/users/presentation/users_list_screen.dart';

final rootNavigatorKey = GlobalKey<NavigatorState>();

/// Pure redirect logic (unit-testable).
String? authRedirect(AuthState auth, String location) {
  const publicPaths = {'/splash', '/login'};
  switch (auth) {
    case AuthUnknown():
    case AuthRestoreFailed():
      return location == '/splash' ? null : '/splash';
    case AuthSignedOut():
      return location == '/login' ? null : '/login';
    case AuthSignedIn(:final user):
      if (user.mustChangePassword) {
        return location == '/change-password' ? null : '/change-password';
      }
      if (publicPaths.contains(location)) return '/home';
      if (location == '/approvals' && !user.can.approveRequests) return '/home';
      if ((location == '/users' || location.startsWith('/users/')) && !user.can.manageUsers) return '/more';
      return null;
  }
}

int _intParam(GoRouterState s, String name) => int.tryParse(s.pathParameters[name] ?? '') ?? 0;

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier<int>(0);
  ref.listen(authControllerProvider, (prev, next) {
    // Only re-run redirects when the routing-relevant part changed.
    final relevant = prev.runtimeType != next.runtimeType ||
        (prev is AuthSignedIn &&
            next is AuthSignedIn &&
            (prev.user.mustChangePassword != next.user.mustChangePassword ||
                prev.user.can.approveRequests != next.user.can.approveRequests ||
                prev.user.can.manageUsers != next.user.can.manageUsers ||
                prev.user.id != next.user.id));
    if (relevant) refresh.value++;
  });
  ref.onDispose(refresh.dispose);

  final router = GoRouter(
    navigatorKey: rootNavigatorKey,
    initialLocation: '/splash',
    refreshListenable: refresh,
    redirect: (context, state) => authRedirect(ref.read(authControllerProvider), state.matchedLocation),
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const SplashScreen()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: '/change-password', builder: (_, _) => const ChangePasswordScreen()),
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => HomeShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: '/home', builder: (_, _) => const HomeScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/approvals', builder: (_, _) => const ApprovalsScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/requests', builder: (_, _) => const RequestsListScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/stock', builder: (_, _) => const StockScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/more', builder: (_, _) => const MoreScreen())]),
        ],
      ),
      GoRoute(path: '/request/new', builder: (_, _) => const NewRequestScreen()),
      GoRoute(path: '/request/:id', builder: (_, s) => RequestDetailScreen(id: _intParam(s, 'id'))),
      GoRoute(path: '/request/:id/issue', builder: (_, s) => IssueFormScreen(requestId: _intParam(s, 'id'))),
      // Store operations and purchasing
      GoRoute(path: '/store', builder: (_, _) => const StoreHubScreen()),
      GoRoute(
        path: '/issues',
        builder: (_, _) => const IssuesScreen(),
        routes: [
          GoRoute(path: ':id', builder: (_, s) => IssueDetailScreen(id: _intParam(s, 'id'))),
          GoRoute(path: ':id/return', builder: (_, s) => ReturnFormScreen(issueId: _intParam(s, 'id'))),
        ],
      ),
      GoRoute(
        path: '/receipts',
        builder: (_, _) => const ReceiptsScreen(),
        routes: [
          GoRoute(path: 'new', builder: (_, _) => const NewReceiptScreen()),
          GoRoute(path: ':id', builder: (_, s) => ReceiptDetailScreen(id: _intParam(s, 'id'))),
        ],
      ),
      GoRoute(path: '/adjustments/new', builder: (_, _) => const NewAdjustmentScreen()),
      GoRoute(
        path: '/transfers',
        builder: (_, _) => const TransfersScreen(),
        routes: [
          GoRoute(path: 'new', builder: (_, _) => const NewTransferScreen()),
          GoRoute(path: ':id', builder: (_, s) => TransferDetailScreen(id: _intParam(s, 'id'))),
        ],
      ),
      GoRoute(
        path: '/returns',
        builder: (_, _) => const ReturnsScreen(),
        routes: [GoRoute(path: ':id', builder: (_, s) => ReturnDetailScreen(id: _intParam(s, 'id')))],
      ),
      GoRoute(
        path: '/purchasing',
        builder: (_, _) => const PurchasingScreen(),
        routes: [
          GoRoute(path: 'pr/:id', builder: (_, s) => PrDetailScreen(id: _intParam(s, 'id'))),
          GoRoute(path: 'po/:id', builder: (_, s) => PoDetailScreen(id: _intParam(s, 'id'))),
        ],
      ),
      GoRoute(path: '/item/:id', builder: (_, s) => ItemDetailScreen(id: _intParam(s, 'id'))),
      GoRoute(
        path: '/claims',
        builder: (_, _) => const ClaimsListScreen(),
        routes: [
          GoRoute(path: 'new', builder: (_, _) => const NewClaimScreen()),
          GoRoute(path: ':id', builder: (_, s) => ClaimDetailScreen(id: _intParam(s, 'id'))),
        ],
      ),
      GoRoute(
        path: '/users',
        builder: (_, _) => const UsersListScreen(),
        routes: [
          GoRoute(path: 'new', builder: (_, _) => const UserFormScreen()),
          GoRoute(path: ':id', builder: (_, s) => UserFormScreen(id: _intParam(s, 'id'))),
        ],
      ),
      GoRoute(
        path: '/feedback',
        builder: (_, _) => const FeedbackListScreen(),
        routes: [
          GoRoute(path: 'new', builder: (_, _) => const NewFeedbackScreen()),
          GoRoute(path: ':id', builder: (_, s) => FeedbackDetailScreen(id: _intParam(s, 'id'))),
        ],
      ),
      GoRoute(path: '/notifications', builder: (_, _) => const NotificationsScreen()),
      GoRoute(
        path: '/profile',
        builder: (_, _) => const ProfileScreen(),
        routes: [GoRoute(path: 'edit', builder: (_, _) => const EditProfileScreen())],
      ),
      GoRoute(path: '/reports', builder: (_, _) => const ReportsScreen()),
      GoRoute(path: '/about', builder: (_, _) => const AboutScreen()),
    ],
  );
  ref.onDispose(router.dispose);
  return router;
});
