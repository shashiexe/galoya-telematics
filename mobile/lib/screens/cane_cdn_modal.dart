import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:image_picker/image_picker.dart';

import '../api.dart';
import '../db.dart';
import '../sync.dart';

/// Modal bottom sheet for issuing and tracking Cane Delivery Notes (CDN)
/// Phase 2: Dual Cane Types (CC/SC), Live Weighbridge Net Weight MT Sync,
/// 100% Mandatory Photos, Strict Duplicate Prevention, 30-Min Edit/Cancel Grace Window,
/// Final Harvest Load (LstHvstTonnage) tracking & Elastic Multi-Crop Display.
class CaneCdnModalSheet extends StatefulWidget {
  final Position? currentPosition;
  final Map<String, dynamic>? prefillPlot;
  final String? userCode;
  final String? userName;

  const CaneCdnModalSheet({
    super.key,
    this.currentPosition,
    this.prefillPlot,
    this.userCode,
    this.userName,
  });

  @override
  State<CaneCdnModalSheet> createState() => _CaneCdnModalSheetState();
}

class _CaneCdnModalSheetState extends State<CaneCdnModalSheet> {
  int _activeTab = 0; // 0: Issue CDN, 1: Weighbridge Tracker

  // Dual Cane Types: CC (Commercial Cane) vs SC (Seed Cane)
  String _caneType = 'CC'; // 'CC' or 'SC'

  // Category Configuration
  final List<Map<String, String>> _ccCategories = const [
    {'code': 'CAT1', 'name': 'Green Cane (කොළ උක්)'},
    {'code': 'CAT2', 'name': 'Burnt Cane (ගිනි තැබූ උක්)'},
    {'code': 'CAT6', 'name': 'Machine Harvested (මැෂින් අස්වනු)'},
  ];

  final List<Map<String, String>> _scCategories = const [
    {'code': 'CAT3', 'name': 'Seed Cane (බිත්තර උක්)'},
    {'code': 'CAT4', 'name': 'Seed Cane Top Cutting (කරටි)'},
  ];

  List<Map<String, String>> get _currentCategories =>
      _caneType == 'CC' ? _ccCategories : _scCategories;

  String _selectedCategory = 'CAT1';

  // Form Controllers
  final _formKey = GlobalKey<FormState>();
  final _cdnCtrl = TextEditingController();
  final _plotCtrl = TextEditingController();
  final _farmerNameCtrl = TextEditingController();
  final _farmerNoCtrl = TextEditingController();
  final _sectorCtrl = TextEditingController();
  final _landAreaCtrl = TextEditingController();
  final _vehicleCtrl = TextEditingController();
  final _trailerCtrl = TextEditingController();
  final _cropCycleCtrl = TextEditingController(text: 'R1');

  // System Plot No vs Manual Code
  String? _selectedSystemPlotNo;
  String? _selectedCropCycleId;

  // Final Harvest Load
  bool _isFinalHarvestLoad = false;

  // 100% Mandatory Verification Photos
  File? _cdnPhoto; // Slip
  File? _tractorPhoto; // Tractor Front & Number Plate
  File? _trailerPhoto; // Trailer Plate
  File? _loadPhoto; // Loaded Cane (Optional 4th photo)

  bool _submitting = false;

  // Active Vehicle & Trailer Auto-suggest
  List<String> _availableVehicles = [];
  List<String> _availableTrailers = [];

  // Active Crop Verification (Status = 1)
  List<Map<String, dynamic>> _activeCrops = [];
  bool _loadingCrops = false;
  bool _hasNoActiveCrop = false;
  String? _selectedCropCycle;

  // Tracker State
  DateTime _trackerDate = DateTime.now();
  String _statusFilter = 'COMPLETED'; // 'COMPLETED', 'PENDING', 'IN_TRANSIT_ALL', 'CANCELLED', 'ALL'
  final _trackerSearchCtrl = TextEditingController();
  List<Map<String, dynamic>> _cdnList = [];
  Map<String, dynamic> _summary = {
    'total_count': 0,
    'completed_count': 0,
    'pending_count': 0,
    'cancelled_count': 0,
    'total_tonnage': 0.0,
  };
  bool _loadingTracker = false;

  // Plot Autocomplete & Suggestions State
  List<Map<String, dynamic>> _plotSuggestions = [];
  bool _searchingPlots = false;
  Timer? _debouncePlotSearch;

  @override
  void initState() {
    super.initState();
    _initPrefill();
    _loadTrackerData();
    _loadVehiclesAndTrailers();
    if (widget.prefillPlot != null) {
      final code = (widget.prefillPlot!['plot_manual_code'] ??
              widget.prefillPlot!['name'] ??
              widget.prefillPlot!['plot_no'] ??
              '')
          .toString();
      _selectedSystemPlotNo = widget.prefillPlot!['plot_no']?.toString();
      if (code.isNotEmpty) _loadPlotCrops(code);
    }
  }

  void _onCaneTypeChanged(String type) {
    if (_caneType == type) return;
    setState(() {
      _caneType = type;
      _selectedCategory = type == 'CC' ? 'CAT1' : 'CAT3';
    });
  }

  Future<void> _loadVehiclesAndTrailers() async {
    try {
      final vList = await LocalDb.getActiveVehicleList();
      final tList = await LocalDb.getTrailerList();
      if (mounted) {
        setState(() {
          _availableVehicles = vList;
          _availableTrailers = tList;
        });
      }
      if (vList.isEmpty || tList.isEmpty) {
        await Sync.refreshMasterVehiclesAndTrailers();
        final vList2 = await LocalDb.getActiveVehicleList();
        final tList2 = await LocalDb.getTrailerList();
        if (mounted) {
          setState(() {
            _availableVehicles = vList2;
            _availableTrailers = tList2;
          });
        }
      }
    } catch (_) {}
  }

  Future<void> _loadPlotCrops(String plotCode) async {
    final clean = plotCode.trim();
    if (clean.isEmpty) return;
    if (mounted) setState(() => _loadingCrops = true);
    try {
      // 1. Check local master plot
      final master = await LocalDb.getMasterPlotByCode(clean);
      if (master != null && mounted) {
        setState(() {
          _selectedSystemPlotNo = master['plot_no']?.toString();
          if (_farmerNameCtrl.text.isEmpty && (master['farmer_name'] ?? '').toString().isNotEmpty) {
            _farmerNameCtrl.text = master['farmer_name']!;
          }
          if (_farmerNoCtrl.text.isEmpty && (master['farmer_no'] ?? '').toString().isNotEmpty) {
            _farmerNoCtrl.text = master['farmer_no']!;
          }
          if (_landAreaCtrl.text.isEmpty &&
              master['land_area'] != null &&
              master['land_area'].toString().isNotEmpty &&
              master['land_area'].toString() != '0.0') {
            _landAreaCtrl.text = '${master['land_area']} Ha';
          }
          if (_sectorCtrl.text.isEmpty && (master['sector_no'] ?? '').toString().isNotEmpty) {
            _sectorCtrl.text = master['sector_no']!;
          }
        });
      }

      // 2. Local SQLite crops check (100% offline)
      var crops = await LocalDb.getActiveCrops(clean);
      if (crops.isEmpty) {
        // 3. Cloud lookup if not found locally
        final res = await Api.get('get_plot_crops', params: {
          'plot_no': clean,
          'key': 'galoya2026',
        }).timeout(const Duration(milliseconds: 3000));
        if (res['ok'] == true && mounted) {
          if (res['plot'] is Map) {
            final p = (res['plot'] as Map).cast<String, dynamic>();
            _selectedSystemPlotNo = p['plot_no']?.toString();
            if (_farmerNameCtrl.text.isEmpty && (p['farmer_name'] ?? '').toString().isNotEmpty) {
              _farmerNameCtrl.text = p['farmer_name']!;
            }
            if (_farmerNoCtrl.text.isEmpty && (p['farmer_no'] ?? '').toString().isNotEmpty) {
              _farmerNoCtrl.text = p['farmer_no']!;
            }
            if (_landAreaCtrl.text.isEmpty &&
                p['land_area'] != null &&
                p['land_area'].toString().isNotEmpty &&
                p['land_area'].toString() != '0.0') {
              _landAreaCtrl.text = '${p['land_area']} Ha';
            }
            if (_sectorCtrl.text.isEmpty && (p['sector_no'] ?? '').toString().isNotEmpty) {
              _sectorCtrl.text = p['sector_no']!;
            }
            await LocalDb.saveMasterPlots([p]);
          }
          if (res['crops'] is List) {
            crops = (res['crops'] as List).cast<Map<String, dynamic>>();
            await LocalDb.saveCrops(crops);
          }
        }
      }

      if (mounted) {
        setState(() {
          _loadingCrops = false;
          _activeCrops = crops;
          if (crops.isEmpty) {
            _hasNoActiveCrop = true;
            _selectedCropCycle = null;
            _selectedCropCycleId = null;
            _cropCycleCtrl.text = '';
          } else {
            _hasNoActiveCrop = false;
            final first = crops.first;
            _selectedCropCycle = first['crop_cycle']?.toString() ?? 'P';
            _selectedCropCycleId = (first['crop_cycle_id'] ?? first['ID'] ?? '').toString();
            _cropCycleCtrl.text = _selectedCropCycle!;
          }
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loadingCrops = false);
    }
  }

  void _initPrefill() {
    _cdnCtrl.text = ''; // Empty by default for manual paper slip scanning or input
    if (widget.prefillPlot != null) {
      final p = widget.prefillPlot!;
      _plotCtrl.text = (p['plot_manual_code'] ?? p['name'] ?? p['plot_no'] ?? '').toString();
      _selectedSystemPlotNo = p['plot_no']?.toString();
      _farmerNameCtrl.text = (p['farmer_name'] ?? '').toString();
      _farmerNoCtrl.text = (p['farmer_no'] ?? '').toString();
      _sectorCtrl.text = (p['sector_no'] ?? '').toString();
      final area = p['land_area'];
      if (area != null && area.toString().isNotEmpty && area.toString() != '0.0') {
        _landAreaCtrl.text = '$area Ha';
      }
    }
  }

  void _clearForm() {
    _cdnCtrl.clear();
    _plotCtrl.clear();
    _farmerNameCtrl.clear();
    _farmerNoCtrl.clear();
    _sectorCtrl.clear();
    _landAreaCtrl.clear();
    _vehicleCtrl.clear();
    _trailerCtrl.clear();
    _cropCycleCtrl.clear();
    setState(() {
      _cdnPhoto = null;
      _tractorPhoto = null;
      _trailerPhoto = null;
      _loadPhoto = null;
      _selectedSystemPlotNo = null;
      _selectedCropCycle = null;
      _selectedCropCycleId = null;
      _isFinalHarvestLoad = false;
      _activeCrops = [];
      _plotSuggestions = [];
      _hasNoActiveCrop = false;
      _selectedCategory = _caneType == 'CC' ? 'CAT1' : 'CAT3';
    });
  }

  @override
  void dispose() {
    _debouncePlotSearch?.cancel();
    _cdnCtrl.dispose();
    _plotCtrl.dispose();
    _farmerNameCtrl.dispose();
    _farmerNoCtrl.dispose();
    _sectorCtrl.dispose();
    _landAreaCtrl.dispose();
    _vehicleCtrl.dispose();
    _trailerCtrl.dispose();
    _cropCycleCtrl.dispose();
    _trackerSearchCtrl.dispose();
    super.dispose();
  }

  Future<void> _scanCdnSlip() async {
    try {
      final picker = ImagePicker();
      final picked = await picker.pickImage(source: ImageSource.camera, imageQuality: 85);
      if (picked == null) return;
      final file = File(picked.path);
      setState(() {
        _cdnPhoto = file;
      });

      final inputImage = InputImage.fromFilePath(picked.path);
      final textRecognizer = TextRecognizer();
      final recognizedText = await textRecognizer.processImage(inputImage);
      await textRecognizer.close();

      String? detectedCdn;
      final allText = recognizedText.text;

      final matchCdnNo = RegExp(r'CDN\s*NO[^\w]*([A-Za-z])\s*(\d{4,7})', caseSensitive: false).firstMatch(allText);
      if (matchCdnNo != null) {
        final pfx = (matchCdnNo.group(1) ?? '').toUpperCase();
        final numPart = matchCdnNo.group(2) ?? '';
        detectedCdn = '$pfx$numPart';
      } else {
        final matchGeneral = RegExp(r'\b([A-Z])\s*(\d{4,7})\b').firstMatch(allText);
        if (matchGeneral != null) {
          final pfx = (matchGeneral.group(1) ?? '').toUpperCase();
          final numPart = matchGeneral.group(2) ?? '';
          detectedCdn = '$pfx$numPart';
        }
      }

      if (detectedCdn != null && detectedCdn.isNotEmpty) {
        detectedCdn = detectedCdn.replaceAll(RegExp(r'\s+'), '').toUpperCase();
        setState(() {
          _cdnCtrl.text = detectedCdn!;
        });
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              backgroundColor: const Color(0xff059669),
              content: Text('✅ CDN Slip අංකය හඳුනාගන්නා ලදී: $detectedCdn'),
            ),
          );
        }
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              backgroundColor: Color(0xffd97706),
              content: Text('📷 CDN ඡායාරූපය ඇතුළත් විය. කරුණාකර අංකය type කරන්න.'),
            ),
          );
        }
      }
    } catch (_) {}
  }

  Future<void> _pickVerificationPhoto(int type) async {
    // 1 = Tractor Front / Plate, 2 = Trailer No, 3 = Cane Load
    try {
      final picker = ImagePicker();
      final picked = await picker.pickImage(source: ImageSource.camera, imageQuality: 75);
      if (picked != null && mounted) {
        setState(() {
          if (type == 1) _tractorPhoto = File(picked.path);
          if (type == 2) _trailerPhoto = File(picked.path);
          if (type == 3) _loadPhoto = File(picked.path);
        });
      }
    } catch (_) {}
  }

  void _onPlotInputChanged(String val) {
    _debouncePlotSearch?.cancel();
    final q = val.trim();
    if (q.isEmpty) {
      if (mounted) {
        setState(() {
          _plotSuggestions = [];
          _searchingPlots = false;
        });
      }
      return;
    }
    _debouncePlotSearch = Timer(const Duration(milliseconds: 250), () => _searchPlotsLocalAndOnline(q));
  }

  Future<void> _searchPlotsLocalAndOnline(String query) async {
    final q = query.trim();
    if (q.isEmpty) return;

    if (mounted) setState(() => _searchingPlots = true);
    final resultsMap = <String, Map<String, dynamic>>{};

    try {
      // 1. Instant local SQLite geofences search
      final gfList = await LocalDb.searchGeofences(
        q,
        userLat: widget.currentPosition?.latitude,
        userLng: widget.currentPosition?.longitude,
        limit: 15,
      );
      for (final g in gfList) {
        final code = (g['name'] ?? g['code'] ?? '').toString().trim();
        if (code.isEmpty) continue;
        resultsMap[code.toUpperCase()] = {
          'plot_manual_code': code,
          'plot_no': g['code']?.toString() ?? code,
          'farmer_name': g['farmer_name']?.toString() ?? '',
          'farmer_no': g['farmer_no']?.toString() ?? '',
          'sector_no': g['sector_no']?.toString() ?? '',
          'land_area': g['land_area'],
          'has_geofence': 1,
          'distance': g['distance'],
        };
      }

      // 2. Local SQLite master plots search
      final localMaster = await LocalDb.searchMasterPlots(q, limit: 15);
      for (final m in localMaster) {
        final code = (m['plot_manual_code'] ?? m['plot_no'] ?? '').toString().trim();
        if (code.isEmpty) continue;
        final upper = code.toUpperCase();
        if (resultsMap.containsKey(upper)) {
          final existing = resultsMap[upper]!;
          if ((existing['farmer_name'] ?? '').toString().isEmpty) {
            existing['farmer_name'] = m['farmer_name'];
          }
          if ((existing['farmer_no'] ?? '').toString().isEmpty) {
            existing['farmer_no'] = m['farmer_no'];
          }
          if ((existing['sector_no'] ?? '').toString().isEmpty) {
            existing['sector_no'] = m['sector_no'];
          }
          if (existing['land_area'] == null) {
            existing['land_area'] = m['land_area'];
          }
        } else {
          resultsMap[upper] = {
            'plot_manual_code': code,
            'plot_no': m['plot_no']?.toString() ?? code,
            'farmer_name': m['farmer_name']?.toString() ?? '',
            'farmer_no': m['farmer_no']?.toString() ?? '',
            'sector_no': m['sector_no']?.toString() ?? '',
            'land_area': m['land_area'],
            'has_geofence': m['has_geofence'] ?? 0,
            'distance': null,
          };
        }
      }

      // Show instant local results
      if (mounted) {
        setState(() {
          _plotSuggestions = resultsMap.values.toList();
        });
      }

      // 3. Online cloud lookup if available
      try {
        final onlineRes = await Api.get('search_plots_master', params: {
          'query': q,
          'limit': '25',
          'key': 'galoya2026',
        }).timeout(const Duration(milliseconds: 3000));

        if (onlineRes['ok'] == true && onlineRes['plots'] is List && mounted) {
          final serverPlots = (onlineRes['plots'] as List).cast<Map<String, dynamic>>();
          if (serverPlots.isNotEmpty) {
            await LocalDb.saveMasterPlots(serverPlots);
            for (final sp in serverPlots) {
              final code = (sp['plot_manual_code'] ?? sp['plot_no'] ?? '').toString().trim();
              if (code.isEmpty) continue;
              final upper = code.toUpperCase();
              if (resultsMap.containsKey(upper)) {
                final ex = resultsMap[upper]!;
                if ((ex['farmer_name'] ?? '').toString().isEmpty) ex['farmer_name'] = sp['farmer_name'];
                if ((ex['farmer_no'] ?? '').toString().isEmpty) ex['farmer_no'] = sp['farmer_no'];
                if ((ex['sector_no'] ?? '').toString().isEmpty) ex['sector_no'] = sp['sector_no'];
                if (ex['land_area'] == null) ex['land_area'] = sp['land_area'];
                if (sp['has_geofence'] == 1) ex['has_geofence'] = 1;
              } else {
                resultsMap[upper] = {
                  'plot_manual_code': code,
                  'plot_no': sp['plot_no']?.toString() ?? code,
                  'farmer_name': sp['farmer_name']?.toString() ?? '',
                  'farmer_no': sp['farmer_no']?.toString() ?? '',
                  'sector_no': sp['sector_no']?.toString() ?? '',
                  'land_area': sp['land_area'],
                  'has_geofence': sp['has_geofence'] ?? 0,
                  'distance': null,
                };
              }
            }
            if (mounted) {
              setState(() {
                _plotSuggestions = resultsMap.values.toList();
              });
            }
          }
        }
      } catch (_) {}
    } finally {
      if (mounted) setState(() => _searchingPlots = false);
    }
  }

  void _selectPlotSuggestion(Map<String, dynamic> plot) {
    final manualCode = (plot['plot_manual_code'] ?? plot['plot_no'] ?? '').toString();
    final systemPlotNo = plot['plot_no']?.toString();
    final farmerName = (plot['farmer_name'] ?? '').toString();
    final farmerNo = (plot['farmer_no'] ?? '').toString();
    final sectorNo = (plot['sector_no'] ?? '').toString();
    final landArea = (plot['land_area'] != null &&
            plot['land_area'].toString().isNotEmpty &&
            plot['land_area'].toString() != '0.0')
        ? '${plot['land_area']} Ha'
        : '';

    setState(() {
      _plotCtrl.text = manualCode;
      _selectedSystemPlotNo = systemPlotNo;
      _farmerNameCtrl.text = farmerName;
      _farmerNoCtrl.text = farmerNo;
      _sectorCtrl.text = sectorNo;
      _landAreaCtrl.text = landArea;
      _plotSuggestions = [];
      _searchingPlots = false;
    });
    FocusScope.of(context).unfocus();
    _loadPlotCrops(manualCode);
  }

  Future<void> _pickNearestGpsPlot() async {
    if (widget.currentPosition == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          backgroundColor: Color(0xffd97706),
          content: Text('GPS ස්ථානය තවමත් සූදානම් නැත. කරුණාකර GPS සක්‍රීය කර ඇත්දැයි බලන්න.'),
        ),
      );
      return;
    }
    final lat = widget.currentPosition!.latitude;
    final lng = widget.currentPosition!.longitude;
    try {
      final nearby = await LocalDb.getNearbyGeofences(lat, lng, limit: 1);
      if (nearby.isNotEmpty) {
        final p = nearby.first;
        final name = (p['name'] ?? p['code'] ?? '').toString();
        final dist = (p['distance'] as num?)?.round() ?? 0;
        _plotCtrl.text = name;
        _selectedSystemPlotNo = p['code']?.toString();
        _loadPlotCrops(name);
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              backgroundColor: const Color(0xff059669),
              content: Text('📍 ආසන්නතම ඉඩම තෝරා ගන්නා ලදී: $name (දුර: $dist m)'),
            ),
          );
        }
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              backgroundColor: Color(0xffd97706),
              content: Text('වත්මන් GPS ස්ථානයට ආසන්නව කිසිදු ඉඩමක් හමු නොවීය.'),
            ),
          );
        }
      }
    } catch (_) {}
  }

  Future<void> _submitCdn() async {
    if (!_formKey.currentState!.validate()) return;
    final cdnNo = _cdnCtrl.text.trim().replaceAll(RegExp(r'\s+'), '').toUpperCase();
    final plotManualCode = _plotCtrl.text.trim();
    final systemPlotNo = _selectedSystemPlotNo ?? plotManualCode;
    final vehicleNo = _vehicleCtrl.text.trim().toUpperCase();
    final trailerNo = _trailerCtrl.text.trim().toUpperCase();

    // 1. Strict Duplicate Prevention Check
    final isDup = await LocalDb.isCdnDuplicate(cdnNo);
    if (isDup) {
      if (mounted) {
        showDialog(
          context: context,
          builder: (ctx) => AlertDialog(
            backgroundColor: const Color(0xff0f172a),
            title: const Row(
              children: [
                Icon(Icons.warning, color: Colors.red),
                SizedBox(width: 8),
                Text('අනුපිටපත් CDN අංකයකි!', style: TextStyle(color: Colors.white, fontSize: 16)),
              ],
            ),
            content: Text(
              'CDN අංකය "$cdnNo" දැනටමත් පද්ධතියේ ලියාපදිංචි කර ඇත.\nඑකම CDN අංකය නැවත ඇතුළත් කළ නොහැක.',
              style: const TextStyle(color: Colors.white70, fontSize: 13),
            ),
            actions: [
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xff0284c7)),
                onPressed: () => Navigator.pop(ctx),
                child: const Text('හරි (OK)'),
              ),
            ],
          ),
        );
      }
      return;
    }

    // 2. Strict Crop Check
    if (_hasNoActiveCrop || _activeCrops.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          backgroundColor: Colors.red,
          content: Text('🛑 මෙම ඉඩම සඳහා සක්‍රීය බෝගයක් වාර්තා වී නොමැත. CDN නිකුත් කළ නොහැක!'),
        ),
      );
      return;
    }

    // 3. 100% Mandatory Verification Photos Check
    if (_cdnPhoto == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          backgroundColor: Colors.red,
          content: Text('⚠️ CDN Slip ඡායාරූපය (Scan Slip) අනිවාර්ය වේ!'),
        ),
      );
      return;
    }
    if (_tractorPhoto == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          backgroundColor: Colors.red,
          content: Text('⚠️ Tractor Front & Number Plate ඡායාරූපය අනිවාර්ය වේ!'),
        ),
      );
      return;
    }
    if (_trailerPhoto == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          backgroundColor: Colors.red,
          content: Text('⚠️ Trailer Number ඡායාරූපය අනිවාර්ය වේ!'),
        ),
      );
      return;
    }

    // 4. Final Harvest Load Smart Reminder
    if (!_isFinalHarvestLoad) {
      final confirmFinal = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: const Color(0xff0f172a),
          title: const Row(
            children: [
              Icon(Icons.lightbulb, color: Color(0xfffbbf24)),
              SizedBox(width: 8),
              Text('💡 මතක් කිරීමක් (Reminder)', style: TextStyle(color: Colors.white, fontSize: 15)),
            ],
          ),
          content: Text(
            'මෙය ඉඩම $plotManualCode සඳහා අවසාන ට්‍රැක්ටර් ලෝඩ් එකද?\n(Is this the final cane load for this plot?)',
            style: const TextStyle(color: Colors.white70, fontSize: 13),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('නැත, තව ලෝඩ් ඇත', style: TextStyle(color: Colors.white70)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xff10b981), foregroundColor: Colors.black),
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('ඔව්, අවසාන ලෝඩ් එකයි 🏁'),
            ),
          ],
        ),
      );
      if (confirmFinal == true) {
        setState(() => _isFinalHarvestLoad = true);
      }
    }

    setState(() => _submitting = true);

    try {
      final nowIso = DateTime.now().toIso8601String();
      final cropCycle = _cropCycleCtrl.text.trim();
      final faNo = (widget.userCode != null && widget.userCode!.trim().isNotEmpty) ? widget.userCode!.trim() : 'FA61';
      final faName = widget.userName ?? 'Field Officer';

      final record = <String, dynamic>{
        'cdn': cdnNo,
        'plot_no': systemPlotNo, // MSSQL requires System Plot No e.g. P1002
        'plot_manual_code': plotManualCode, // Mobile displays human manual code e.g. V/07/16
        'farmer_no': _farmerNoCtrl.text.trim(),
        'farmer_name': _farmerNameCtrl.text.trim(),
        'field_asst_no': faNo,
        'field_asst_name': faName,
        'type': _caneType, // 'CC' or 'SC'
        'cane_category_code': _selectedCategory,
        'vehicle_no': vehicleNo,
        'trailer_no': trailerNo,
        'crop_cycle': cropCycle,
        'crop_cycle_id': _selectedCropCycleId,
        'lst_hvst_tonnage': _isFinalHarvestLoad ? 1 : 0,
        'cdn_photo': _cdnPhoto?.path,
        'tractor_photo': _tractorPhoto?.path,
        'trailer_photo': _trailerPhoto?.path,
        'load_photo': _loadPhoto?.path,
        'lat': widget.currentPosition?.latitude,
        'lng': widget.currentPosition?.longitude,
        'create_user': faName,
        'create_time': nowIso,
        'weige_ticket_no': null,
        'net_weight': 0.0,
        'tonnage_received': 0.0,
        'gross_weight': 0.0,
        'tare_weight': 0.0,
        'weighed_date': null,
        'wbt_create_time': null,
        'status': 'PENDING',
        'is_deleted': 0,
        'cancel_reason': null,
        'modify_user': null,
        'modify_date': null,
        'synced': 0,
        'synced_to_mssql': 0,
      };

      // 1. Immediately write to SQLite (< 5ms offline guarantee)
      await LocalDb.saveCdnRecord(record);

      // 2. Enqueue into background sync queue
      await LocalDb.enqueue(
        'cdn_issue',
        jsonEncode({
          'cdn': cdnNo,
          'plot_no': systemPlotNo,
          'plot_manual_code': plotManualCode,
          'farmer_no': _farmerNoCtrl.text.trim(),
          'farmer_name': _farmerNameCtrl.text.trim(),
          'field_asst_no': faNo,
          'field_asst_name': faName,
          'type': _caneType,
          'cane_category_code': _selectedCategory,
          'vehicle_no': vehicleNo,
          'trailer_no': trailerNo,
          'crop_cycle': cropCycle,
          'crop_cycle_id': _selectedCropCycleId,
          'lst_hvst_tonnage': _isFinalHarvestLoad ? 1 : 0,
          'cdn_photo': _cdnPhoto?.path,
          'tractor_photo': _tractorPhoto?.path,
          'trailer_photo': _trailerPhoto?.path,
          'load_photo': _loadPhoto?.path,
          'lat': widget.currentPosition?.latitude,
          'lng': widget.currentPosition?.longitude,
          'create_user': faName,
        }),
        file: _cdnPhoto?.path,
      );

      // 3. Trigger immediate sync
      Sync.run();

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: const Color(0xff059669),
            duration: const Duration(seconds: 4),
            content: Row(
              children: [
                const Icon(Icons.check_circle, color: Colors.white),
                const SizedBox(width: 8),
                Expanded(child: Text('🌾 CDN $cdnNo සාර්ථකව නිකුත් කරන ලදී!')),
              ],
            ),
          ),
        );

        // Auto-Reset & Clear Form for next CDN
        _clearForm();

        // Switch to Tracker view to see the new entry
        setState(() {
          _submitting = false;
          _activeTab = 1;
        });
        _loadTrackerData();
      }
    } catch (e) {
      if (mounted) {
        setState(() => _submitting = false);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(backgroundColor: Colors.red, content: Text('Error issuing CDN: $e')),
        );
      }
    }
  }

  String _formatDate(DateTime d) =>
      "${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}";

  Future<void> _loadTrackerData() async {
    setState(() => _loadingTracker = true);
    final dateStr = _formatDate(_trackerDate);
    final search = _trackerSearchCtrl.text.trim();

    try {
      // 1. Instant load from local SQLite cache
      final localItems = await LocalDb.getCdnRecords(
        date: dateStr,
        status: _statusFilter,
        search: search.isNotEmpty ? search : null,
      );
      final localSummary = await LocalDb.getCdnSummary(date: dateStr);

      if (mounted) {
        setState(() {
          _cdnList = localItems;
          _summary = localSummary;
          _loadingTracker = false;
        });
      }

      // 2. Background cloud sync to retrieve weighbridge completions & net weights
      final res = await Api.get('cdn_list', params: {
        'date': dateStr,
        'status': _statusFilter,
        if (search.isNotEmpty) 'search': search,
      });

      if (res['ok'] == true && res['items'] is List && mounted) {
        final serverItems = res['items'] as List;
        await LocalDb.saveCdnBatch(serverItems);

        final updatedItems = await LocalDb.getCdnRecords(
          date: dateStr,
          status: _statusFilter,
          search: search.isNotEmpty ? search : null,
        );
        final updatedSummary = res['summary'] is Map
            ? Map<String, dynamic>.from(res['summary'] as Map)
            : await LocalDb.getCdnSummary(date: dateStr);

        setState(() {
          _cdnList = updatedItems;
          _summary = updatedSummary;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loadingTracker = false);
    }
  }

  void _changeDate(int days) {
    setState(() {
      _trackerDate = _trackerDate.add(Duration(days: days));
    });
    _loadTrackerData();
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _trackerDate,
      firstDate: DateTime(2025, 1, 1),
      lastDate: DateTime.now().add(const Duration(days: 30)),
      builder: (ctx, child) => Theme(
        data: ThemeData.dark().copyWith(
          colorScheme: const ColorScheme.dark(
            primary: Color(0xfff59e0b),
            onPrimary: Colors.black,
            surface: Color(0xff1e293b),
            onSurface: Colors.white,
          ),
        ),
        child: child!,
      ),
    );
    if (picked != null) {
      setState(() => _trackerDate = picked);
      _loadTrackerData();
    }
  }

  // --- FULL CARD INSPECTION & ZOOM MODAL ---
  void _showCdnDetailsDialog(Map<String, dynamic> item) {
    final cdn = item['cdn']?.toString() ?? '';
    final plotManual = item['plot_manual_code']?.toString() ?? '';
    final plotNo = item['plot_no']?.toString() ?? '';
    final farmer = item['farmer_name']?.toString() ?? '';
    final farmerNo = item['farmer_no']?.toString() ?? '';
    final vehicle = item['vehicle_no']?.toString() ?? '';
    final trailer = item['trailer_no']?.toString() ?? '';
    final type = (item['type'] ?? 'CC').toString().toUpperCase();
    final cat = item['cane_category_code']?.toString() ?? '';
    final crop = item['crop_cycle']?.toString() ?? '';
    final cropCycleId = item['crop_cycle_id']?.toString() ?? '';
    final isFinal = (item['lst_hvst_tonnage'] == 1 || item['lst_hvst_tonnage'] == true || item['lst_hvst_tonnage'] == '1');
    final wbtNo = item['weige_ticket_no']?.toString() ?? '';
    final netWeight = (item['net_weight'] is num)
        ? (item['net_weight'] as num).toDouble()
        : (double.tryParse(item['net_weight']?.toString() ?? '') ?? 0.0);
    final tonnage = netWeight > 0
        ? (netWeight / 1000.0)
        : ((item['tonnage_received'] is num) ? (item['tonnage_received'] as num).toDouble() : 0.0);
    final gross = (item['gross_weight'] is num) ? (item['gross_weight'] as num).toDouble() : 0.0;
    final tare = (item['tare_weight'] is num) ? (item['tare_weight'] as num).toDouble() : 0.0;
    final createTime = item['create_time']?.toString() ?? '';
    final weighedDate = item['weighed_date']?.toString() ?? item['wbt_create_time']?.toString() ?? '';
    final status = (item['status'] ?? 'PENDING').toString().toUpperCase();
    final isCompleted = status == 'COMPLETED';
    final isDeleted = (item['is_deleted'] == 1 || item['is_deleted'] == true || item['is_deleted'] == '1');
    final cancelReason = item['cancel_reason']?.toString() ?? '';
    final createUser = item['create_user']?.toString() ?? '';
    final faNo = item['field_asst_no']?.toString() ?? '';
    final isSyncedMssql = (item['synced_to_mssql'] == 1 || item['synced_to_mssql'] == true || item['synced_to_mssql'] == '1');

    // 30-Minute Grace Window Check
    DateTime? cDate = DateTime.tryParse(createTime);
    final isWithin30 = cDate != null && DateTime.now().difference(cDate).inMinutes < 30;
    final isWeighed = isCompleted || netWeight > 0 || wbtNo.isNotEmpty;
    final canEditOrCancel = isWithin30 && !isWeighed && !isDeleted;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        height: MediaQuery.of(context).size.height * 0.88,
        decoration: const BoxDecoration(
          color: Color(0xff0b1120),
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
          border: Border(top: BorderSide(color: Color(0xff38bdf8), width: 2)),
        ),
        child: Column(
          children: [
            Container(
              margin: const EdgeInsets.only(top: 10, bottom: 8),
              width: 44,
              height: 4,
              decoration: BoxDecoration(color: const Color(0xff475569), borderRadius: BorderRadius.circular(2)),
            ),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              child: Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: const Color(0xff0284c7).withOpacity(0.2),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: const Icon(Icons.receipt_long, color: Color(0xff38bdf8), size: 22),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('CDN: $cdn', style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
                        Text(
                          isDeleted ? '❌ CANCELLED' : (isCompleted ? '✅ WEIGHED' : '⏳ IN-TRANSIT'),
                          style: TextStyle(
                            color: isDeleted ? Colors.redAccent : (isCompleted ? const Color(0xff34d399) : const Color(0xfffbbf24)),
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close, color: Colors.white70),
                    onPressed: () => Navigator.pop(ctx),
                  ),
                ],
              ),
            ),
            const Divider(color: Color(0xff334155), height: 1),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  // Sync Status Pills
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                        decoration: BoxDecoration(
                          color: const Color(0xff065f46),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: const Text('☁️ Cloud Synced ✅', style: TextStyle(color: Color(0xffa7f3d0), fontSize: 10, fontWeight: FontWeight.bold)),
                      ),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                        decoration: BoxDecoration(
                          color: isSyncedMssql ? const Color(0xff0369a1) : const Color(0xff78350f),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          isSyncedMssql ? '🏢 Factory ERP Synced ✅' : '🏢 Sending to Factory ⏳',
                          style: TextStyle(
                            color: isSyncedMssql ? const Color(0xffbae6fd) : const Color(0xfffde68a),
                            fontSize: 10,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                      if (isFinal) ...[
                        const SizedBox(width: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: const Color(0xff581c87),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: const Text('🏁 FINAL LOAD', style: TextStyle(color: Color(0xfff5d0fe), fontSize: 10, fontWeight: FontWeight.bold)),
                        ),
                      ],
                    ],
                  ),
                  const SizedBox(height: 12),
                  // Weight Card (If weighed)
                  if (isCompleted || netWeight > 0)
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: const Color(0xff064e3b).withOpacity(0.3),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: const Color(0xff059669)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              const Icon(Icons.scale, color: Color(0xff34d399), size: 20),
                              const SizedBox(width: 8),
                              Text('WBT Ticket: $wbtNo', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13)),
                              const Spacer(),
                              Text(
                                '${tonnage.toStringAsFixed(2)} MT',
                                style: const TextStyle(color: Color(0xff34d399), fontWeight: FontWeight.bold, fontSize: 20),
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Text(
                            'Net Weight: ${netWeight.toStringAsFixed(0)} kg | Gross: ${gross.toStringAsFixed(0)} kg | Tare: ${tare.toStringAsFixed(0)} kg',
                            style: const TextStyle(color: Color(0xffcbd5e1), fontSize: 11),
                          ),
                          if (weighedDate.isNotEmpty)
                            Padding(
                              padding: const EdgeInsets.only(top: 4),
                              child: Text('🕒 Weighbridge Time: $weighedDate', style: const TextStyle(color: Colors.white60, fontSize: 10)),
                            ),
                        ],
                      ),
                    ),
                  const SizedBox(height: 12),
                  // Plot & Farmer Information Card
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: const Color(0xff1e293b),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xff334155)),
                    ),
                    child: Column(
                      children: [
                        _buildDetailRow('Manual Plot Code', plotManual),
                        _buildDetailRow('System Plot No', plotNo),
                        _buildDetailRow('Farmer Name', farmer),
                        if (farmerNo.isNotEmpty) _buildDetailRow('Farmer No', farmerNo),
                        _buildDetailRow('Cane Type', type == 'SC' ? '🌱 Seed Cane (SC)' : '🌾 Commercial Cane (CC)'),
                        _buildDetailRow('Cane Category', cat),
                        _buildDetailRow('Standing Crop', '$crop ${cropCycleId.isNotEmpty ? "(ID: $cropCycleId)" : ""}'),
                        _buildDetailRow('Tractor / Vehicle', vehicle),
                        _buildDetailRow('Trailer Number', trailer),
                        _buildDetailRow('Field Assistant', '$faNo ($createUser)'),
                        _buildDetailRow('Dispatched Date', createTime),
                        if (isDeleted) _buildDetailRow('Cancel Reason', cancelReason),
                      ],
                    ),
                  ),
                  const SizedBox(height: 14),
                  // Photo Gallery (Tap to view full screen)
                  const Text('VERIFICATION PHOTOS (ඡායාරූප)', style: TextStyle(color: Color(0xff38bdf8), fontWeight: FontWeight.bold, fontSize: 12)),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      _buildGalleryThumbnail('CDN Slip', item['cdn_photo']?.toString()),
                      const SizedBox(width: 8),
                      _buildGalleryThumbnail('Tractor', item['tractor_photo']?.toString()),
                      const SizedBox(width: 8),
                      _buildGalleryThumbnail('Trailer', item['trailer_photo']?.toString()),
                      const SizedBox(width: 8),
                      _buildGalleryThumbnail('Cane Load', item['load_photo']?.toString()),
                    ],
                  ),
                  const SizedBox(height: 20),
                  // Actions: Mark Final Load, Edit, Cancel
                  if (!isFinal && !isDeleted)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: SizedBox(
                        width: double.infinity,
                        height: 44,
                        child: ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xff7c3aed),
                            foregroundColor: Colors.white,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                          ),
                          icon: const Icon(Icons.flag, size: 18),
                          label: const Text('🏁 Mark as Final Load (LstHvstTonnage = 1)'),
                          onPressed: () async {
                            Navigator.pop(ctx);
                            await _markFinalLoadAction(cdn);
                          },
                        ),
                      ),
                    ),
                  if (canEditOrCancel) ...[
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(
                              foregroundColor: const Color(0xff38bdf8),
                              side: const BorderSide(color: Color(0xff38bdf8)),
                              padding: const EdgeInsets.symmetric(vertical: 12),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                            ),
                            icon: const Icon(Icons.edit, size: 16),
                            label: const Text('Edit CDN (30m)'),
                            onPressed: () {
                              Navigator.pop(ctx);
                              _showEditCdnDialog(item);
                            },
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(
                              foregroundColor: Colors.redAccent,
                              side: const BorderSide(color: Colors.redAccent),
                              padding: const EdgeInsets.symmetric(vertical: 12),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                            ),
                            icon: const Icon(Icons.delete_outline, size: 16),
                            label: const Text('Cancel CDN'),
                            onPressed: () {
                              Navigator.pop(ctx);
                              _showCancelCdnDialog(cdn);
                            },
                          ),
                        ),
                      ],
                    ),
                  ] else if (isWeighed) ...[
                    Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: const Color(0xff1e293b),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: const Color(0xff059669)),
                      ),
                      child: const Row(
                        children: [
                          Icon(Icons.lock, color: Color(0xff34d399), size: 18),
                          SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              '🔒 Factory Weighed & Locked (සංස්කරණය කළ නොහැක)',
                              style: TextStyle(color: Color(0xffa7f3d0), fontSize: 11, fontWeight: FontWeight.bold),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildDetailRow(String label, String value) {
    if (value.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 130,
            child: Text(label, style: const TextStyle(color: Color(0xff94a3b8), fontSize: 12)),
          ),
          Expanded(
            child: Text(value, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
          ),
        ],
      ),
    );
  }

  Widget _buildGalleryThumbnail(String title, String? path) {
    return Expanded(
      child: GestureDetector(
        onTap: () {
          if (path != null && path.isNotEmpty) {
            _openZoomPhoto(title, path);
          }
        },
        child: Container(
          height: 70,
          decoration: BoxDecoration(
            color: const Color(0xff0f172a),
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: path != null ? const Color(0xff38bdf8) : const Color(0xff334155)),
          ),
          child: path != null && path.isNotEmpty
              ? ClipRRect(
                  borderRadius: BorderRadius.circular(7),
                  child: path.startsWith('http')
                      ? Image.network(path, fit: BoxFit.cover, errorBuilder: (_, __, ___) => const Icon(Icons.broken_image, color: Colors.white38))
                      : Image.file(File(path), fit: BoxFit.cover, errorBuilder: (_, __, ___) => const Icon(Icons.photo, color: Colors.white38)),
                )
              : Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.camera_alt, color: Colors.white24, size: 18),
                      const SizedBox(height: 2),
                      Text(title, style: const TextStyle(color: Colors.white38, fontSize: 9)),
                    ],
                  ),
                ),
        ),
      ),
    );
  }

  void _openZoomPhoto(String title, String path) {
    showDialog(
      context: context,
      builder: (ctx) => Dialog(
        backgroundColor: Colors.black,
        insetPadding: const EdgeInsets.all(8),
        child: Stack(
          children: [
            Center(
              child: InteractiveViewer(
                minScale: 0.8,
                maxScale: 4.0,
                child: path.startsWith('http')
                    ? Image.network(path, fit: BoxFit.contain)
                    : Image.file(File(path), fit: BoxFit.contain),
              ),
            ),
            Positioned(
              top: 10,
              right: 10,
              child: IconButton(
                icon: const Icon(Icons.close, color: Colors.white, size: 28),
                onPressed: () => Navigator.pop(ctx),
              ),
            ),
            Positioned(
              bottom: 12,
              left: 16,
              child: Text(title, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14)),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _markFinalLoadAction(String cdn) async {
    try {
      await LocalDb.markCdnFinalLoad(cdn);
      await Api.post('cdn_mark_final_load', body: {'cdn': cdn});
      Sync.run();
      _loadTrackerData();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            backgroundColor: Color(0xff059669),
            content: Text('🏁 අවසාන අස්වනු ලෝඩ් එක ලෙස ලකුණු කරන ලදී! (LstHvstTonnage = 1)'),
          ),
        );
      }
    } catch (_) {}
  }

  void _showCancelCdnDialog(String cdn) {
    final reasonCtrl = TextEditingController(text: 'Wrong vehicle / slip mistake');
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xff0f172a),
        title: const Row(
          children: [
            Icon(Icons.delete_outline, color: Colors.redAccent),
            SizedBox(width: 8),
            Text('Cancel CDN', style: TextStyle(color: Colors.white)),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Are you sure you want to cancel CDN $cdn?', style: const TextStyle(color: Colors.white70, fontSize: 13)),
            const SizedBox(height: 12),
            TextField(
              controller: reasonCtrl,
              style: const TextStyle(color: Colors.white),
              decoration: const InputDecoration(
                labelText: 'Reason for Cancellation',
                labelStyle: TextStyle(color: Color(0xff94a3b8)),
                filled: true,
                fillColor: Color(0xff1e293b),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Back', style: TextStyle(color: Colors.white54))),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.redAccent),
            onPressed: () async {
              Navigator.pop(ctx);
              final r = reasonCtrl.text.trim();
              await LocalDb.markCdnCancelled(cdn, r);
              await Api.post('cdn_cancel', body: {'cdn': cdn, 'reason': r});
              Sync.run();
              _loadTrackerData();
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(backgroundColor: Colors.redAccent, content: Text('CDN $cdn has been cancelled.')),
                );
              }
            },
            child: const Text('Confirm Cancel'),
          ),
        ],
      ),
    );
  }

  void _showEditCdnDialog(Map<String, dynamic> item) {
    final cdn = item['cdn']?.toString() ?? '';
    final vCtrl = TextEditingController(text: item['vehicle_no']?.toString() ?? '');
    final tCtrl = TextEditingController(text: item['trailer_no']?.toString() ?? '');
    String cat = item['cane_category_code']?.toString() ?? 'CAT1';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDlgState) => AlertDialog(
          backgroundColor: const Color(0xff0f172a),
          title: Row(
            children: [
              const Icon(Icons.edit, color: Color(0xff38bdf8)),
              const SizedBox(width: 8),
              Text('Edit CDN: $cdn', style: const TextStyle(color: Colors.white, fontSize: 15)),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: vCtrl,
                textCapitalization: TextCapitalization.characters,
                style: const TextStyle(color: Colors.white),
                decoration: const InputDecoration(
                  labelText: 'Vehicle No',
                  labelStyle: TextStyle(color: Color(0xff94a3b8)),
                  filled: true,
                  fillColor: Color(0xff1e293b),
                ),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: tCtrl,
                textCapitalization: TextCapitalization.characters,
                style: const TextStyle(color: Colors.white),
                decoration: const InputDecoration(
                  labelText: 'Trailer No',
                  labelStyle: TextStyle(color: Color(0xff94a3b8)),
                  filled: true,
                  fillColor: Color(0xff1e293b),
                ),
              ),
              const SizedBox(height: 10),
              DropdownButtonFormField<String>(
                value: cat,
                dropdownColor: const Color(0xff1e293b),
                style: const TextStyle(color: Colors.white),
                decoration: const InputDecoration(
                  labelText: 'Category',
                  labelStyle: TextStyle(color: Color(0xff94a3b8)),
                  filled: true,
                  fillColor: Color(0xff1e293b),
                ),
                items: _currentCategories.map((c) {
                  return DropdownMenuItem<String>(
                    value: c['code'],
                    child: Text(c['name']!, style: const TextStyle(color: Colors.white, fontSize: 12)),
                  );
                }).toList(),
                onChanged: (val) {
                  if (val != null) setDlgState(() => cat = val);
                },
              ),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel', style: TextStyle(color: Colors.white54))),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xff0284c7)),
              onPressed: () async {
                Navigator.pop(ctx);
                final newV = vCtrl.text.trim().toUpperCase();
                final newT = tCtrl.text.trim().toUpperCase();
                await LocalDb.updateCdnRecord(
                  cdn: cdn,
                  vehicleNo: newV,
                  trailerNo: newT,
                  caneCategoryCode: cat,
                );
                await Api.post('cdn_edit', body: {
                  'cdn': cdn,
                  'vehicle_no': newV,
                  'trailer_no': newT,
                  'cane_category_code': cat,
                });
                Sync.run();
                _loadTrackerData();
                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(backgroundColor: const Color(0xff059669), content: Text('CDN $cdn updated successfully!')),
                  );
                }
              },
              child: const Text('Save Changes'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildFilterChip(String key, String label) {
    final active = _statusFilter == key;
    return GestureDetector(
      onTap: () {
        setState(() => _statusFilter = key);
        _loadTrackerData();
      },
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: active ? const Color(0xfff59e0b) : const Color(0xff1e293b),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: active ? const Color(0xfff59e0b) : const Color(0xff334155)),
        ),
        child: Text(
          label,
          style: TextStyle(
            color: active ? Colors.black : Colors.white70,
            fontWeight: active ? FontWeight.bold : FontWeight.normal,
            fontSize: 11,
          ),
        ),
      ),
    );
  }
}
