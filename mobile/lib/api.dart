import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'config.dart';
import 'data_usage.dart';
import 'vault.dart';

/// SSL override to trust galoya.lk server certificates on all Android versions
class AppHttpOverrides extends HttpOverrides {
  @override
  HttpClient createHttpClient(SecurityContext? context) {
    return super.createHttpClient(context)
      ..badCertificateCallback = (X509Certificate cert, String host, int port) {
        return true; // Bypass SSL certificate verification issues for telematics
      };
  }
}

class ApiException implements Exception {
  final String message;
  final int? status;
  ApiException(this.message, [this.status]);
  @override
  String toString() => message;
}

/// Thin HTTP client for api.php with automatic HTTPS -> HTTP fallback and custom User-Agent.
class Api {
  static const Map<String, String> _defaultHeaders = {
    'User-Agent': 'StaffTrack-Mobile/1.2.0 (Android; lk.galoya.stafftrack)',
    'Accept': 'application/json, text/plain, */*',
  };

  static Future<String?> token() async {
    try {
      final sp = await SharedPreferences.getInstance();
      await sp.reload();
      final t = sp.getString('token');
      if (t != null && t.isNotEmpty) return t;
    } catch (_) {}
    return AuthVault.getToken();
  }

  static Future<Map<String, dynamic>> _handle(http.Response r, [int sentBytes = 0]) async {
    // Record network/cellular data consumption
    final receivedBytes = r.bodyBytes.length + 200;
    DataUsage.addBytes(sent: sentBytes > 0 ? sentBytes : 350, received: receivedBytes);

    Map<String, dynamic> j;
    try {
      j = jsonDecode(r.body) as Map<String, dynamic>;
    } catch (_) {
      throw ApiException('Server error (${r.statusCode})', r.statusCode);
    }
    if (r.statusCode >= 400 || j['ok'] != true) {
      throw ApiException(j['error']?.toString() ?? 'Error ${r.statusCode}', r.statusCode);
    }
    if (j['config'] is Map) await saveServerConfig(j['config']);
    return j;
  }

  static Future<Map<String, dynamic>> post(String action, Map<String, dynamic> body, {bool auth = true}) async {
    final t = auth ? await token() : null;
    final headers = {
      ..._defaultHeaders,
      'Content-Type': 'application/json',
      if (t != null) 'Authorization': 'Bearer $t',
    };
    final sendBody = Map<String, dynamic>.from(body);
    if (t != null) sendBody['token'] = t;
    final tokenQuery = (t != null && t.isNotEmpty) ? '&token=${Uri.encodeComponent(t)}' : '';
    final encoded = jsonEncode(sendBody);
    final sentBytes = utf8.encode(encoded).length + 350;

    try {
      final r = await http
          .post(Uri.parse('$kApiUrl?action=$action$tokenQuery'), headers: headers, body: encoded)
          .timeout(const Duration(seconds: 25));
      return _handle(r, sentBytes);
    } catch (e) {
      if (e is ApiException) rethrow;
      // Fallback to HTTP if HTTPS handshake or connection failed
      try {
        final rHttp = await http
            .post(Uri.parse('$kApiUrlHttp?action=$action$tokenQuery'), headers: headers, body: encoded)
            .timeout(const Duration(seconds: 25));
        return _handle(rHttp, sentBytes);
      } catch (_) {}
      rethrow;
    }
  }

  static Future<Map<String, dynamic>> get(String action, {Map<String, String>? params}) async {
    final t = await token();
    final headers = {
      ..._defaultHeaders,
      if (t != null) 'Authorization': 'Bearer $t',
    };

    String queryString = (t != null && t.isNotEmpty) ? '&token=${Uri.encodeComponent(t)}' : '';
    if (params != null && params.isNotEmpty) {
      queryString += '&' + params.entries.map((e) => '${Uri.encodeComponent(e.key)}=${Uri.encodeComponent(e.value)}').join('&');
    }
    const sentBytes = 350;

    try {
      final r = await http
          .get(Uri.parse('$kApiUrl?action=$action$queryString'), headers: headers)
          .timeout(const Duration(seconds: 25));
      return _handle(r, sentBytes);
    } catch (e) {
      if (e is ApiException) rethrow;
      // Fallback to HTTP
      try {
        final rHttp = await http
            .get(Uri.parse('$kApiUrlHttp?action=$action$queryString'), headers: headers)
            .timeout(const Duration(seconds: 25));
        return _handle(rHttp, sentBytes);
      } catch (_) {}
      rethrow;
    }
  }

  static Future<Map<String, dynamic>> upload(String action, Map<String, String> fields, File file) async {
    final t = await token();
    final fMap = Map<String, String>.from(fields);
    if (t != null) {
      fMap['token'] = t;
    }
    final sentBytes = (file.existsSync() ? file.lengthSync() : 0) + 600;

    Future<http.Response> sendTo(String url) async {
      final req = http.MultipartRequest('POST', Uri.parse(url))
        ..fields.addAll(fMap)
        ..files.add(await http.MultipartFile.fromPath('photo', file.path));
      req.headers.addAll(_defaultHeaders);
      if (t != null) req.headers['Authorization'] = 'Bearer $t';
      return http.Response.fromStream(await req.send().timeout(const Duration(seconds: 120)));
    }

    try {
      final r = await sendTo('$kApiUrl?action=$action');
      return _handle(r, sentBytes);
    } catch (e) {
      if (e is ApiException) rethrow;
      try {
        final rHttp = await sendTo('$kApiUrlHttp?action=$action');
        return _handle(rHttp, sentBytes);
      } catch (_) {}
      rethrow;
    }
  }

  static Future<void> saveServerConfig(Map c) async {
    final sp = await SharedPreferences.getInstance();
    if (c['location_interval'] != null) await sp.setInt('server_interval', (c['location_interval'] as num).toInt());
    if (c['allow_staff_change_interval'] != null) await sp.setBool('allow_change', c['allow_staff_change_interval'] == true);
    if (c['upload_batch_size'] != null) await sp.setInt('batch', (c['upload_batch_size'] as num).toInt());
  }
}
