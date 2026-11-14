import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../../core/theme/app_theme.dart';
import '../data/auth_controller.dart';

/// Restores the token and calls `/me`; the router then moves on.
class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (ref.read(authControllerProvider) is AuthUnknown) {
        ref.read(authControllerProvider.notifier).restore();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(authControllerProvider);
    return Scaffold(
      backgroundColor: Brand.navy,
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const BrandMark(size: 84),
                const SizedBox(height: 20),
                Text(
                  AppConfig.appName,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                      ),
                ),
                const SizedBox(height: 32),
                if (state is AuthRestoreFailed) ...[
                  const Icon(Icons.cloud_off_outlined, color: Colors.white70, size: 36),
                  const SizedBox(height: 12),
                  Text(
                    state.message,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white),
                  ),
                  const SizedBox(height: 20),
                  FilledButton.icon(
                    onPressed: () => ref.read(authControllerProvider.notifier).restore(),
                    icon: const Icon(Icons.refresh),
                    label: const Text('Try again'),
                  ),
                  TextButton(
                    onPressed: () => ref.read(authControllerProvider.notifier).logout(),
                    style: TextButton.styleFrom(foregroundColor: Colors.white),
                    child: const Text('Sign in as someone else'),
                  ),
                ] else
                  const SizedBox(
                    width: 28,
                    height: 28,
                    child: CircularProgressIndicator(strokeWidth: 3, color: Brand.gold),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Simple brand mark: gold rounded square with a navy "K".
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 64});
  final double size;

  @override
  Widget build(BuildContext context) => Semantics(
        label: 'Kemmy Store logo',
        image: true,
        child: Container(
          width: size,
          height: size,
          decoration: BoxDecoration(color: Brand.gold, borderRadius: BorderRadius.circular(size * 0.24)),
          alignment: Alignment.center,
          child: ExcludeSemantics(
            child: Text(
              'K',
              style: TextStyle(color: Brand.navy, fontSize: size * 0.55, fontWeight: FontWeight.w800, height: 1),
            ),
          ),
        ),
      );
}
