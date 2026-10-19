import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'api.dart';
import 'config.dart';

class AppUpdater {
  static bool _checking = false;
  static bool _hasPromptedThisSession = false;

  /// Checks server for newer version and shows an update modal if available.
  static Future<void> checkForUpdates(BuildContext context, {bool silent = false}) async {
    if (_checking) return;
    _checking = true;

    try {
      final res = await Api.get('check_update', params: {
        'version': kAppVersion,
        'build': kAppBuildNumber.toString(),
      });

      _checking = false;

      if (res['ok'] == true && res['has_update'] == true) {
        if (!context.mounted) return;
        final newVersion = res['latest_version']?.toString() ?? 'New Version';
        final changelog = res['changelog']?.toString() ?? 'Improvements & bug fixes';
        final apkUrl = res['apk_url']?.toString() ?? kApkDownloadUrl;

        _showUpdateDialog(context, newVersion, changelog, apkUrl);
      } else if (!silent && context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Row(
              children: [
                const Icon(Icons.check_circle, color: Color(0xff22c55e), size: 18),
                const SizedBox(width: 8),
                Expanded(
                  child: Text('App is up to date (v$kAppVersion • $kCreatedBy)',
                      style: const TextStyle(fontWeight: FontWeight.bold)),
                ),
              ],
            ),
            backgroundColor: const Color(0xff1e293b),
            duration: const Duration(seconds: 3),
          ),
        );
      }
    } catch (e) {
      _checking = false;
      if (!silent && context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not check for updates: $e')),
        );
      }
    }
  }

  /// Automatically checks once per session on home screen open
  static Future<void> checkOnLaunch(BuildContext context) async {
    if (_hasPromptedThisSession) return;
    _hasPromptedThisSession = true;
    await Future.delayed(const Duration(seconds: 4));
    if (context.mounted) {
      await checkForUpdates(context, silent: true);
    }
  }

  static void _showUpdateDialog(BuildContext context, String newVersion, String changelog, String apkUrl) {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xff1e293b),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(color: const Color(0xff0284c7).withOpacity(0.2), shape: BoxShape.circle),
              child: const Icon(Icons.system_update, color: Color(0xff38bdf8), size: 24),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('New Update Available', style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
                  Text('Version $newVersion', style: const TextStyle(color: Color(0xff38bdf8), fontSize: 12, fontWeight: FontWeight.w600)),
                ],
              ),
            ),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: const Color(0xff0f172a), borderRadius: BorderRadius.circular(10)),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text("What's New:", style: TextStyle(color: Colors.white70, fontWeight: FontWeight.bold, fontSize: 12)),
                  const SizedBox(height: 6),
                  Text(changelog, style: const TextStyle(color: Colors.white60, fontSize: 12, height: 1.4)),
                ],
              ),
            ),
            const SizedBox(height: 12),
            const Text(
              kCreatedBy,
              style: TextStyle(color: Color(0xff94a3b8), fontSize: 11, fontWeight: FontWeight.bold),
            ),
            const Text(
              kOrganization,
              style: TextStyle(color: Color(0xff64748b), fontSize: 10),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Later', style: TextStyle(color: Colors.white54)),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xff16a34a),
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
            icon: const Icon(Icons.download, size: 18),
            label: const Text('Download & Update', style: TextStyle(fontWeight: FontWeight.bold)),
            onPressed: () async {
              Navigator.pop(ctx);
              final uri = Uri.parse(apkUrl);
              try {
                await launchUrl(uri, mode: LaunchMode.externalApplication);
              } catch (_) {}
            },
          ),
        ],
      ),
    );
  }
}
