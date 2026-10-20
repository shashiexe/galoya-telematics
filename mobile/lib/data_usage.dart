import 'package:shared_preferences/shared_preferences.dart';

/// Tracks mobile cellular/Wi-Fi data consumed by the app for GPS, telemetry, photos, and updates.
class DataUsage {
  static const String _kKeyTotalSent = 'data_usage_total_sent';
  static const String _kKeyTotalReceived = 'data_usage_total_received';
  static const String _kKeyToday = 'data_usage_today_bytes';
  static const String _kKeyTodayDate = 'data_usage_today_date';
  static const String _kKeyMonth = 'data_usage_month_bytes';
  static const String _kKeyMonthId = 'data_usage_month_id';

  /// Add transmitted bytes (sent + received)
  static Future<void> addBytes({required int sent, required int received}) async {
    try {
      final sp = await SharedPreferences.getInstance();
      final now = DateTime.now();
      final todayStr = '${now.year}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}';
      final monthStr = '${now.year}-${now.month.toString().padLeft(2, '0')}';

      // Check daily reset
      final savedTodayStr = sp.getString(_kKeyTodayDate);
      int todayBytes = (savedTodayStr == todayStr) ? (sp.getInt(_kKeyToday) ?? 0) : 0;

      // Check monthly reset
      final savedMonthStr = sp.getString(_kKeyMonthId);
      int monthBytes = (savedMonthStr == monthStr) ? (sp.getInt(_kKeyMonth) ?? 0) : 0;

      final totalSent = (sp.getInt(_kKeyTotalSent) ?? 0) + sent;
      final totalReceived = (sp.getInt(_kKeyTotalReceived) ?? 0) + received;
      todayBytes += (sent + received);
      monthBytes += (sent + received);

      await sp.setInt(_kKeyTotalSent, totalSent);
      await sp.setInt(_kKeyTotalReceived, totalReceived);
      await sp.setInt(_kKeyToday, todayBytes);
      await sp.setString(_kKeyTodayDate, todayStr);
      await sp.setInt(_kKeyMonth, monthBytes);
      await sp.setString(_kKeyMonthId, monthStr);
    } catch (_) {}
  }

  /// Get summary of data usage
  static Future<Map<String, dynamic>> getStats() async {
    final sp = await SharedPreferences.getInstance();
    await sp.reload();
    final now = DateTime.now();
    final todayStr = '${now.year}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}';
    final monthStr = '${now.year}-${now.month.toString().padLeft(2, '0')}';

    final savedTodayStr = sp.getString(_kKeyTodayDate);
    final todayBytes = (savedTodayStr == todayStr) ? (sp.getInt(_kKeyToday) ?? 0) : 0;

    final savedMonthStr = sp.getString(_kKeyMonthId);
    final monthBytes = (savedMonthStr == monthStr) ? (sp.getInt(_kKeyMonth) ?? 0) : 0;

    final totalSent = sp.getInt(_kKeyTotalSent) ?? 0;
    final totalReceived = sp.getInt(_kKeyTotalReceived) ?? 0;
    final totalBytes = totalSent + totalReceived;

    return {
      'today_bytes': todayBytes,
      'month_bytes': monthBytes,
      'total_sent': totalSent,
      'total_received': totalReceived,
      'total_bytes': totalBytes,
      'today_formatted': formatBytes(todayBytes),
      'month_formatted': formatBytes(monthBytes),
      'total_formatted': formatBytes(totalBytes),
      'sent_formatted': formatBytes(totalSent),
      'received_formatted': formatBytes(totalReceived),
    };
  }

  static String formatBytes(int bytes) {
    if (bytes < 1024) return '$bytes B';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(1)} KB';
    if (bytes < 1024 * 1024 * 1024) return '${(bytes / (1024 * 1024)).toStringAsFixed(2)} MB';
    return '${(bytes / (1024 * 1024 * 1024)).toStringAsFixed(2)} GB';
  }

  static Future<void> resetStats() async {
    final sp = await SharedPreferences.getInstance();
    await sp.remove(_kKeyTotalSent);
    await sp.remove(_kKeyTotalReceived);
    await sp.remove(_kKeyToday);
    await sp.remove(_kKeyTodayDate);
    await sp.remove(_kKeyMonth);
    await sp.remove(_kKeyMonthId);
  }
}
