import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

class AppColors {
  static const bg = Color(0xFF0B1020);
  static const panel = Color(0xFF121A30);
  static const panel2 = Color(0xFF18223D);
  static const line = Color(0xFF24304F);
  static const pri = Color(0xFF6C8CFF);
  static const pri2 = Color(0xFF9B6CFF);
  static const ok = Color(0xFF2ED3A0);
  static const warn = Color(0xFFFFB547);
  static const bad = Color(0xFFFF5D7A);
  static const mut = Color(0xFF8A97BD);
  static const grad = LinearGradient(colors: [pri, pri2], begin: Alignment.topLeft, end: Alignment.bottomRight);
}

ThemeData buildTheme() {
  final base = ThemeData.dark(useMaterial3: true);
  return base.copyWith(
    scaffoldBackgroundColor: AppColors.bg,
    colorScheme: const ColorScheme.dark(primary: AppColors.pri, secondary: AppColors.pri2, surface: AppColors.panel),
    textTheme: GoogleFonts.outfitTextTheme(base.textTheme),
    appBarTheme: AppBarTheme(
      backgroundColor: AppColors.bg, elevation: 0, centerTitle: false,
      titleTextStyle: GoogleFonts.outfit(fontSize: 22, fontWeight: FontWeight.w600, color: Colors.white),
    ),
    cardTheme: CardThemeData(
      color: AppColors.panel, elevation: 0, margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: const BorderSide(color: AppColors.line)),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true, fillColor: AppColors.panel,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: AppColors.line)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: AppColors.line)),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: AppColors.pri, width: 1.5)),
    ),
  );
}

/// Gradient primary button with press animation.
class GradButton extends StatefulWidget {
  final String label;
  final IconData? icon;
  final VoidCallback? onTap;
  final bool busy;
  final Gradient gradient;
  const GradButton({super.key, required this.label, this.icon, this.onTap, this.busy = false, this.gradient = AppColors.grad});
  @override
  State<GradButton> createState() => _GradButtonState();
}

class _GradButtonState extends State<GradButton> {
  bool _down = false;
  @override
  Widget build(BuildContext context) {
    final disabled = widget.onTap == null || widget.busy;
    return GestureDetector(
      onTapDown: (_) => setState(() => _down = true),
      onTapUp: (_) => setState(() => _down = false),
      onTapCancel: () => setState(() => _down = false),
      onTap: disabled ? null : widget.onTap,
      child: AnimatedScale(
        scale: _down ? .97 : 1, duration: const Duration(milliseconds: 120),
        child: AnimatedOpacity(
          opacity: disabled ? .6 : 1, duration: const Duration(milliseconds: 200),
          child: Container(
            height: 54,
            decoration: BoxDecoration(
              gradient: widget.gradient, borderRadius: BorderRadius.circular(14),
              boxShadow: [BoxShadow(color: AppColors.pri.withOpacity(.35), blurRadius: 18, offset: const Offset(0, 8))],
            ),
            alignment: Alignment.center,
            child: widget.busy
                ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                : Row(mainAxisSize: MainAxisSize.min, children: [
                    if (widget.icon != null) ...[Icon(widget.icon, color: Colors.white), const SizedBox(width: 8)],
                    Text(widget.label, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: Colors.white)),
                  ]),
          ),
        ),
      ),
    );
  }
}

Widget statusChip(String s) {
  final m = {
    'pending': ('Pending', AppColors.warn),
    'in_progress': ('In progress', AppColors.pri),
    'done': ('Done', AppColors.ok),
    'cancelled': ('Cancelled', AppColors.bad),
  }[s] ?? (s, AppColors.mut);
  return Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
    decoration: BoxDecoration(color: m.$2.withOpacity(.15), borderRadius: BorderRadius.circular(99)),
    child: Text(m.$1, style: TextStyle(color: m.$2, fontSize: 12, fontWeight: FontWeight.w600)),
  );
}
