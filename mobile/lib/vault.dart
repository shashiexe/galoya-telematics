import 'dart:convert';
import 'dart:io';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Enterprise Anti-Wipe Persistence Vault.
/// Protects staff login credentials and unique device UUID against
/// phone 'Clear Cache', aggressive cleaner apps, and low-memory purges.
class AuthVault {
  static const String _fileName = '.telematics_vault.json';

  /// Returns list of persistent directory paths to check/save
  static Future<List<File>> _getVaultFiles() async {
    final files = <File>[];
    try {
      final docDir = await getApplicationDocumentsDirectory();
      files.add(File('${docDir.path}/$_fileName'));
    } catch (_) {}

    try {
      final supportDir = await getApplicationSupportDirectory();
      files.add(File('${supportDir.path}/$_fileName'));
    } catch (_) {}

    try {
      if (Platform.isAndroid) {
        final extDirs = await getExternalStorageDirectories();
        if (extDirs != null && extDirs.isNotEmpty) {
          files.add(File('${extDirs.first.path}/$_fileName'));
        }
      }
    } catch (_) {}

    return files;
  }

  /// Save session to persistent vault (both SharedPreferences and hidden files)
  static Future<void> save({
    required String token,
    required String name,
    required String phone,
    String? code,
    required String deviceUuid,
    bool tracking = true,
  }) async {
    final data = {
      'token': token,
      'name': name,
      'phone': phone,
      'code': code ?? '',
      'app_device_uuid': deviceUuid,
      'tracking': tracking,
      'updated_at': DateTime.now().millisecondsSinceEpoch,
    };
    final jsonStr = jsonEncode(data);

    // Write to all redundant vault locations
    final files = await _getVaultFiles();
    for (final f in files) {
      try {
        await f.writeAsString(jsonStr, flush: true);
      } catch (_) {}
    }
  }

  /// Checks if SharedPreferences was wiped, and restores session from vault
  static Future<bool> restoreIfMissing() async {
    try {
      final sp = await SharedPreferences.getInstance();
      await sp.reload();
      final hasToken = sp.getString('token') != null;
      if (hasToken) return false; // Already has active session

      final files = await _getVaultFiles();
      for (final f in files) {
        if (await f.exists()) {
          final content = await f.readAsString();
          if (content.isNotEmpty) {
            final data = jsonDecode(content) as Map<String, dynamic>;
            final token = data['token'] as String?;
            if (token != null && token.isNotEmpty) {
              await sp.setString('token', token);
              await sp.setString('name', data['name'] ?? 'Staff');
              if (data['code'] != null && data['code'].toString().isNotEmpty) {
                await sp.setString('code', data['code'].toString());
              }
              await sp.setString('phone', data['phone'] ?? '');
              if (data['app_device_uuid'] != null) {
                await sp.setString('app_device_uuid', data['app_device_uuid'].toString());
              }
              if (data['tracking'] == true) {
                await sp.setBool('tracking', true);
              }
              return true; // Successfully restored!
            }
          }
        }
      }
    } catch (_) {}
    return false;
  }

  /// Explicit user logout: clear vault files
  static Future<void> clear() async {
    final files = await _getVaultFiles();
    for (final f in files) {
      try {
        if (await f.exists()) await f.delete();
      } catch (_) {}
    }
  }

  /// Direct token reader from vault files
  static Future<String?> getToken() async {
    try {
      final files = await _getVaultFiles();
      for (final f in files) {
        if (await f.exists()) {
          final content = await f.readAsString();
          if (content.isNotEmpty) {
            final data = jsonDecode(content) as Map<String, dynamic>;
            final t = data['token'] as String?;
            if (t != null && t.isNotEmpty) return t;
          }
        }
      }
    } catch (_) {}
    return null;
  }

  /// Direct device UUID reader from vault files
  static Future<String?> getDeviceUuid() async {
    try {
      final files = await _getVaultFiles();
      for (final f in files) {
        if (await f.exists()) {
          final content = await f.readAsString();
          if (content.isNotEmpty) {
            final data = jsonDecode(content) as Map<String, dynamic>;
            final id = data['app_device_uuid'] as String?;
            if (id != null && id.isNotEmpty) return id;
          }
        }
      }
    } catch (_) {}
    return null;
  }
}

