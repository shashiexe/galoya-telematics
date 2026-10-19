import 'dart:io';

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api.dart';
import '../theme.dart';
import '../vault.dart';
import 'home.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phone = TextEditingController();
  final _pass = TextEditingController();
  bool _busy = false;
  String? _err;

  Future<void> _login() async {
    final phone = _phone.text.trim();
    final pass = _pass.text;
    if (phone.isEmpty || pass.isEmpty) {
      setState(() => _err = '[ERR-INPUT] Please enter both phone number and password.');
      return;
    }

    setState(() { _busy = true; _err = null; });
    try {
      String? deviceUuid;
      SharedPreferences? sp;
      try {
        sp = await SharedPreferences.getInstance();
        deviceUuid = sp.getString('app_device_uuid');
      } catch (e) {
        debugPrint('SharedPreferences init note: $e');
      }

      if (deviceUuid == null || deviceUuid.isEmpty) {
        deviceUuid = await AuthVault.getDeviceUuid();
      }

      if (deviceUuid == null || deviceUuid.isEmpty) {
        deviceUuid = '8661920' + DateTime.now().millisecondsSinceEpoch.toString().substring(5);
        try {
          await sp?.setString('app_device_uuid', deviceUuid);
        } catch (_) {}
      }

      final j = await Api.post('login', {
        'phone': phone,
        'password': pass,
        'device_model': 'Android Phone (${Platform.operatingSystem})',
        'unique_id': deviceUuid,
      }, auth: false);

      try {
        await sp?.setString('token', j['token']);
        await sp?.setString('name', j['user']['name'] ?? 'Staff');
        if (j['user']['code'] != null) await sp?.setString('code', j['user']['code'].toString());
        if (j['user']['unit_type'] != null) await sp?.setString('unit_type', j['user']['unit_type'].toString());
        if (j['user']['designation'] != null) await sp?.setString('designation', j['user']['designation'].toString());
        await sp?.setString('phone', phone);
      } catch (e) {
        debugPrint('SharedPreferences save note: $e');
      }

      // Save to Enterprise Anti-Wipe Vault
      await AuthVault.save(
        token: j['token'],
        name: j['user']['name'] ?? 'Staff',
        phone: phone,
        code: j['user']['code']?.toString(),
        deviceUuid: deviceUuid,
        tracking: true,
      );

      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const HomeScreen()));
    } catch (e) {
      String msg;
      if (e is ApiException) {
        final m = e.message.toLowerCase();
        if (m.contains('phone') || m.contains('password') || m.contains('wrong') || m.contains('invalid') || e.status == 401) {
          msg = '[ERR-AUTH-01] Phone number or password is incorrect.';
        } else if (e.status != null) {
          msg = '[ERR-SRV-${e.status}] ${e.message}';
        } else {
          msg = '[ERR-API] ${e.message}';
        }
      } else {
        final err = e.toString().toLowerCase();
        if (err.contains('socketexception') || err.contains('failed host lookup') || err.contains('network is unreachable')) {
          msg = '[ERR-NET-01] Cannot reach server. Please check mobile data or WiFi.';
        } else if (err.contains('timeoutexception') || err.contains('timed out')) {
          msg = '[ERR-NET-02] Server response timed out. Please try again.';
        } else if (err.contains('handshakeexception') || err.contains('certificate')) {
          msg = '[ERR-SSL-01] Security handshake failed. Retrying...';
        } else if (err.contains('sharedpreferences') || err.contains('nosuchmethoderror')) {
          msg = '[ERR-SYS-01] System storage module error.';
        } else {
          final clean = e.toString().replaceAll('Exception:', '').replaceAll('PlatformException(', '').trim();
          final shortMsg = clean.length > 70 ? clean.substring(0, 67) + '...' : clean;
          msg = '[ERR-SYS-99] $shortMsg';
        }
      }
      setState(() => _err = msg);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: RadialGradient(center: Alignment(.8, -1), radius: 1.2, colors: [Color(0x552A3A80), AppColors.bg]),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(28),
              child: TweenAnimationBuilder<double>(
                tween: Tween(begin: 0, end: 1), duration: const Duration(milliseconds: 600), curve: Curves.easeOutCubic,
                builder: (_, v, child) => Opacity(opacity: v, child: Transform.translate(offset: Offset(0, 20 * (1 - v)), child: child)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Container(
                    width: 72, height: 72, alignment: Alignment.center,
                    decoration: BoxDecoration(gradient: AppColors.grad, borderRadius: BorderRadius.circular(22),
                        boxShadow: [BoxShadow(color: AppColors.pri.withOpacity(.5), blurRadius: 30)]),
                    child: const Icon(Icons.eco, color: Colors.white, size: 38),
                  ),
                  const SizedBox(height: 20),
                  const Text('Gal-Oya OmniOps', style: TextStyle(fontSize: 28, fontWeight: FontWeight.w800, letterSpacing: -0.5)),
                  const SizedBox(height: 4),
                  const Text('Field & Fleet Telematics Ecosystem', style: TextStyle(color: Color(0xff38bdf8), fontSize: 13, fontWeight: FontWeight.w600)),
                  const Text('Sign in to start your duty', style: TextStyle(color: AppColors.mut, fontSize: 14)),
                  const SizedBox(height: 28),
                  TextField(controller: _phone, keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(labelText: 'Phone number', prefixIcon: Icon(Icons.phone))),
                  const SizedBox(height: 14),
                  TextField(controller: _pass, obscureText: true, onSubmitted: (_) => _login(),
                      decoration: const InputDecoration(labelText: 'Password', prefixIcon: Icon(Icons.lock))),
                  if (_err != null) Padding(padding: const EdgeInsets.only(top: 14),
                      child: Text(_err!, style: const TextStyle(color: AppColors.bad))),
                  const SizedBox(height: 24),
                  GradButton(label: 'Sign in', icon: Icons.login, busy: _busy, onTap: _login),
                  const SizedBox(height: 32),
                  const Center(
                    child: Column(
                      children: [
                        Text('Created by GOPL IT', style: TextStyle(color: Color(0xff94a3b8), fontSize: 12, fontWeight: FontWeight.bold)),
                        SizedBox(height: 3),
                        Text('Gal-Oya Plantations (Pvt) Limited • v1.2.0', style: TextStyle(color: Color(0xff64748b), fontSize: 11)),
                      ],
                    ),
                  ),
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
