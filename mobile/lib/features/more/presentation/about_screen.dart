import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../../core/api/providers.dart';
import '../../../core/config/app_config.dart';
import '../../../core/widgets/common.dart';
import '../../auth/presentation/splash_screen.dart';

final packageInfoProvider = FutureProvider<PackageInfo>((ref) => PackageInfo.fromPlatform());

class AboutScreen extends ConsumerWidget {
  const AboutScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(appConfigProvider);
    final info = ref.watch(packageInfoProvider).value;
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('About')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          ResponsiveCenter(
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  children: [
                    const BrandMark(size: 64),
                    const SizedBox(height: 12),
                    Text(AppConfig.appName, style: theme.textTheme.titleLarge),
                    const SizedBox(height: 4),
                    Text(
                      info == null ? '' : 'Version ${info.version} (build ${info.buildNumber})',
                      style: theme.textTheme.bodyMedium,
                    ),
                    const Divider(height: 32),
                    InfoField(label: 'Server', value: config.normalizedBaseUrl, icon: Icons.dns_outlined),
                    if (config.useFakeApi) ...[
                      const SizedBox(height: 12),
                      const InfoField(
                        label: 'Mode',
                        value: 'Demo (fake API, in-memory data)',
                        icon: Icons.science_outlined,
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
          const SizedBox(height: 12),
          ResponsiveCenter(
            child: Card(
              child: ListTile(
                leading: const Icon(Icons.description_outlined),
                title: const Text('Open-source licences'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => showLicensePage(
                  context: context,
                  applicationName: AppConfig.appName,
                  applicationVersion: info?.version,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
