import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'config.dart';

class ApiException implements Exception {
  final String message;
  final int? status;
  ApiException(this.message, [this.status]);
  @override
  String toString() => message;
}

/// Thin HTTP client for api.php.
class Api {
  static Future<String?> token() async {
    final sp = await SharedPreferences.getInstance();
    await sp.reload();
    return sp.getString('token');
  }

  static Future<Map<String, dynamic>> _handle(http.Response r) async {
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
    final r = await http
        .post(Uri.parse('$kApiUrl?action=$action'),
            headers: {'Content-Type': 'application/json', if (t != null) 'Authorization': 'Bearer $t'},
            body: jsonEncode(body))
        .timeout(const Duration(seconds: 30));
    return _handle(r);
  }

  static Future<Map<String, dynamic>> get(String action) async {
    final t = await token();
    final r = await http
        .get(Uri.parse('$kApiUrl?action=$action'), headers: {if (t != null) 'Authorization': 'Bearer $t'})
        .timeout(const Duration(seconds: 30));
    return _handle(r);
  }

  static Future<Map<String, dynamic>> upload(String action, Map<String, String> fields, File file) async {
    final t = await token();
    final fMap = Map<String, String>.from(fields);
    if (t != null) {
      fMap['token'] = t;
    }
    final req = http.MultipartRequest('POST', Uri.parse('$kApiUrl?action=$action'))
      ..fields.addAll(fMap)
      ..files.add(await http.MultipartFile.fromPath('photo', file.path));
    if (t != null) req.headers['Authorization'] = 'Bearer $t';
    final r = await http.Response.fromStream(await req.send().timeout(const Duration(seconds: 120)));
    return _handle(r);
  }

  static Future<void> saveServerConfig(Map c) async {
    final sp = await SharedPreferences.getInstance();
    if (c['location_interval'] != null) await sp.setInt('server_interval', (c['location_interval'] as num).toInt());
    if (c['allow_staff_change_interval'] != null) await sp.setBool('allow_change', c['allow_staff_change_interval'] == true);
    if (c['upload_batch_size'] != null) await sp.setInt('batch', (c['upload_batch_size'] as num).toInt());
  }
}
