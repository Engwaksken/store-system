import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/util/data_revision.dart';
import '../../auth/data/auth_controller.dart';
import '../../dashboard/data/dashboard_repository.dart';

class _Tab {
  const _Tab(this.branch, this.label, this.icon, this.selectedIcon);
  final int branch;
  final String label;
  final IconData icon;
  final IconData selectedIcon;
}

/// Bottom navigation shell. Tabs depend on `user.can`; branch indexes are
/// fixed (see the router) and mapped to the visible destinations here.
class HomeShell extends ConsumerStatefulWidget {
  const HomeShell({super.key, required this.shell});
  final StatefulNavigationShell shell;

  @override
  ConsumerState<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends ConsumerState<HomeShell> {
  late final AppLifecycleListener _lifecycle;

  @override
  void initState() {
    super.initState();
    // No push yet: refresh badge + data when the app comes back to the foreground.
    // TODO(push): when FCM is added, also bump on message receipt.
    _lifecycle = AppLifecycleListener(onResume: () {
      ref.read(dataRevisionProvider.notifier).bump();
      ref.read(authControllerProvider.notifier).refreshUser();
    });
  }

  @override
  void dispose() {
    _lifecycle.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final unread = ref.watch(unreadCountProvider);
    final awaiting = ref.watch(dashboardProvider.select((d) {
      final cards = d.value?.cards ?? const [];
      for (final c in cards) {
        if (c.key == 'awaiting_me' && c.value is num) return (c.value as num).toInt();
      }
      return 0;
    }));

    final tabs = <_Tab>[
      const _Tab(0, 'Home', Icons.home_outlined, Icons.home),
      if (user?.can.approveRequests ?? false)
        const _Tab(1, 'Approvals', Icons.fact_check_outlined, Icons.fact_check),
      const _Tab(2, 'Requests', Icons.assignment_outlined, Icons.assignment),
      const _Tab(3, 'Stock', Icons.inventory_2_outlined, Icons.inventory_2),
      const _Tab(4, 'More', Icons.menu, Icons.menu_open),
    ];
    var selected = tabs.indexWhere((t) => t.branch == widget.shell.currentIndex);
    if (selected < 0) selected = 0;

    Widget badged(_Tab t, IconData icon) {
      final count = switch (t.branch) {
        1 => awaiting,
        4 => unread,
        _ => 0,
      };
      return Badge(
        isLabelVisible: count > 0,
        label: Text(count > 99 ? '99+' : '$count'),
        child: Icon(icon),
      );
    }

    return Scaffold(
      body: widget.shell,
      bottomNavigationBar: NavigationBar(
        selectedIndex: selected,
        onDestinationSelected: (i) {
          final branch = tabs[i].branch;
          widget.shell.goBranch(branch, initialLocation: branch == widget.shell.currentIndex);
        },
        destinations: [
          for (final t in tabs)
            NavigationDestination(
              icon: badged(t, t.icon),
              selectedIcon: badged(t, t.selectedIcon),
              label: t.label,
              tooltip: t.branch == 4 && unread > 0 ? 'More, $unread unread notifications' : t.label,
            ),
        ],
      ),
    );
  }
}
