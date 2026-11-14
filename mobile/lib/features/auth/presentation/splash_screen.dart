import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

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
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(32),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const BrandLogo(width: 220),
                const SizedBox(height: 32),
                if (state is AuthRestoreFailed) ...[
                  const Icon(Icons.cloud_off_outlined, color: Colors.black45, size: 36),
                  const SizedBox(height: 12),
                  Text(
                    state.message,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Brand.navy),
                  ),
                  const SizedBox(height: 20),
                  FilledButton.icon(
                    onPressed: () => ref.read(authControllerProvider.notifier).restore(),
                    icon: const Icon(Icons.refresh),
                    label: const Text('Try again'),
                  ),
                  TextButton(
                    onPressed: () => ref.read(authControllerProvider.notifier).logout(),
                    style: TextButton.styleFrom(foregroundColor: Brand.navy),
                    child: const Text('Sign in as someone else'),
                  ),
                ] else
                  const SizedBox(
                    width: 28,
                    height: 28,
                    child: CircularProgressIndicator(strokeWidth: 3, color: Brand.navy),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Company mark (the favicon uploaded in the web app's Settings), assets/branding/favicon.png.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 64});
  final double size;

  @override
  Widget build(BuildContext context) => Semantics(
        label: 'Company logo',
        image: true,
        child: Image.asset('assets/branding/favicon.png', width: size, height: size, fit: BoxFit.contain),
      );
}

/// Full company logo (the logo uploaded in the web app's Settings), assets/branding/logo.png.
/// The artwork has a white background, so on dark surfaces pass [onDark] to set it on a white tile.
class BrandLogo extends StatelessWidget {
  const BrandLogo({super.key, this.width = 200, this.onDark = false});
  final double width;
  final bool onDark;

  @override
  Widget build(BuildContext context) {
    final image = Semantics(
      label: 'Company logo',
      image: true,
      child: Image.asset('assets/branding/logo.png', width: width, fit: BoxFit.contain),
    );
    if (!onDark) return image;
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(20),
        boxShadow: const [BoxShadow(color: Color(0x33000000), blurRadius: 16, offset: Offset(0, 6))],
      ),
      child: image,
    );
  }
}
