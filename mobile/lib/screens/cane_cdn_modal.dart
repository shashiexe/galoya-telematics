import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:image_picker/image_picker.dart';

import '../api.dart';
import '../config.dart';
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

  // 100% Mandatory Verification Photos (All 4 Required)
  File? _cdnPhoto; // Slip
  File? _tractorPhoto; // Tractor Front & Number Plate
  File? _trailerPhoto; // Trailer Plate
  File? _loadPhoto; // Loaded Cane (Mandatory)
  bool _showPhotoValidationErrors = false;

  // Dedicated FocusNodes to prevent jumping & auto-selection
  late final FocusNode _plotFocusNode = FocusNode();
  late final FocusNode _vehicleFocusNode = FocusNode();
  late final FocusNode _trailerFocusNode = FocusNode();
  final ScrollController _issueScrollCtrl = ScrollController();

  bool _submitting = false;

  // Active Vehicle & Trailer Auto-suggest
  List<String> _availableVehicles = [];
  List<String> _availableTrailers = [];

  // Active Crop Verification (Status = 1)
  List<Map<String, dynamic>> _activeCrops = [];
  bool _loadingCrops = false;
  bool _hasNoActiveCrop = false;
  bool _isUnregisteredPlot = false;
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

    // Auto-dismiss plot suggestion dropdown when vehicle or trailer fields gain focus
    _vehicleFocusNode.addListener(() {
      if (_vehicleFocusNode.hasFocus && _plotSuggestions.isNotEmpty) {
        setState(() => _plotSuggestions = []);
      }
    });
    _trailerFocusNode.addListener(() {
      if (_trailerFocusNode.hasFocus && _plotSuggestions.isNotEmpty) {
        setState(() => _plotSuggestions = []);
      }
    });

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
    if (clean.isEmpty) {
      if (mounted) {
        setState(() {
          _loadingCrops = false;
          _hasNoActiveCrop = false;
          _isUnregisteredPlot = false;
          _activeCrops = [];
          _farmerNameCtrl.text = '';
          _farmerNoCtrl.text = '';
          _landAreaCtrl.text = '';
          _sectorCtrl.text = '';
          _selectedSystemPlotNo = null;
        });
      }
      return;
    }
    if (mounted) setState(() => _loadingCrops = true);
    try {
      bool plotFound = false;

      // 1. Check local master plot (100% offline)
      final master = await LocalDb.getMasterPlotByCode(clean);
      if (master != null && mounted) {
        plotFound = true;
        setState(() {
          _isUnregisteredPlot = false;
          _selectedSystemPlotNo = master['plot_no']?.toString();
          if ((master['farmer_name'] ?? '').toString().isNotEmpty) {
            _farmerNameCtrl.text = master['farmer_name']!;
          }
          if ((master['farmer_no'] ?? '').toString().isNotEmpty) {
            _farmerNoCtrl.text = master['farmer_no']!;
          }
          if (master['land_area'] != null &&
              master['land_area'].toString().isNotEmpty &&
              master['land_area'].toString() != '0.0') {
            _landAreaCtrl.text = '${master['land_area']} Ha';
          }
          if ((master['sector_no'] ?? '').toString().isNotEmpty) {
            _sectorCtrl.text = master['sector_no']!;
          }
        });
      }

      // 2. Local SQLite crops check (100% offline instant UX)
      final localCrops = await LocalDb.getActiveCrops(clean);
      if (localCrops.isNotEmpty && mounted) {
        _applyCrops(localCrops);
      }

      // 3. Online cloud lookup to fetch latest multi-block standing crops & plot details
      try {
        final res = await Api.get('get_plot_crops', params: {
          'plot_no': clean,
          'key': 'galoya2026',
        }).timeout(const Duration(milliseconds: 3500));
        if (res['ok'] == true && mounted) {
          if (res['plot'] is Map) {
            plotFound = true;
            final p = (res['plot'] as Map).cast<String, dynamic>();
            _isUnregisteredPlot = false;
            _selectedSystemPlotNo = p['plot_no']?.toString();
            if ((p['farmer_name'] ?? '').toString().isNotEmpty) {
              _farmerNameCtrl.text = p['farmer_name']!;
            }
            if ((p['farmer_no'] ?? '').toString().isNotEmpty) {
              _farmerNoCtrl.text = p['farmer_no']!;
            }
            if (p['land_area'] != null &&
                p['land_area'].toString().isNotEmpty &&
                p['land_area'].toString() != '0.0') {
              _landAreaCtrl.text = '${p['land_area']} Ha';
            }
            if ((p['sector_no'] ?? '').toString().isNotEmpty) {
              _sectorCtrl.text = p['sector_no']!;
            }
            await LocalDb.saveMasterPlots([p]);
          } else if (!plotFound) {
            _isUnregisteredPlot = true;
          }
          if (res['crops'] is List) {
            final freshCrops = (res['crops'] as List).cast<Map<String, dynamic>>();
            await LocalDb.saveCrops(freshCrops);
            if (mounted) {
              _applyCrops(freshCrops);
            }
          }
        }
      } catch (_) {
        if (!plotFound && clean.isNotEmpty) {
          _isUnregisteredPlot = true;
        }
      }

      if (mounted) {
        setState(() {
          _loadingCrops = false;
          if (!plotFound) {
            _isUnregisteredPlot = true;
            _farmerNameCtrl.text = '';
            _farmerNoCtrl.text = '';
            _landAreaCtrl.text = '';
            _sectorCtrl.text = '';
            _selectedSystemPlotNo = null;
          }
          if (_activeCrops.isEmpty) {
            _hasNoActiveCrop = true;
            _selectedCropCycle = null;
            _selectedCropCycleId = null;
            _cropCycleCtrl.text = '';
          }
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loadingCrops = false);
    }
  }

  void _applyCrops(List<Map<String, dynamic>> crops) {
    if (!mounted) return;
    final Map<String, Map<String, dynamic>> dedupedMap = {};
    for (final c in crops) {
      final cc = (c['crop_cycle'] ?? '').toString().trim().toUpperCase();
      final cid = (c['crop_cycle_id'] ?? c['ID'] ?? '').toString().trim();
      final key = cid.isNotEmpty ? '${cc}_$cid' : cc;
      if (cc.isNotEmpty && !dedupedMap.containsKey(key)) {
        dedupedMap[key] = c;
      }
    }
    final finalCrops = dedupedMap.values.toList();

    setState(() {
      _loadingCrops = false;
      _activeCrops = finalCrops;
      if (finalCrops.isEmpty) {
        _hasNoActiveCrop = true;
        _selectedCropCycle = null;
        _selectedCropCycleId = null;
        _cropCycleCtrl.text = '';
      } else {
        _hasNoActiveCrop = false;
        final currentKey = (_selectedCropCycle != null && _selectedCropCycleId != null)
            ? '${_selectedCropCycle}_$_selectedCropCycleId'
            : _selectedCropCycle;
        final match = finalCrops.firstWhere(
          (fc) {
            final fcc = (fc['crop_cycle'] ?? '').toString().trim().toUpperCase();
            final fcid = (fc['crop_cycle_id'] ?? fc['ID'] ?? '').toString().trim();
            final k = fcid.isNotEmpty ? '${fcc}_$fcid' : fcc;
            return k == currentKey;
          },
          orElse: () => finalCrops.first,
        );
        _selectedCropCycle = match['crop_cycle']?.toString() ?? 'P';
        _selectedCropCycleId = (match['crop_cycle_id'] ?? match['ID'] ?? '').toString();
        _cropCycleCtrl.text = _selectedCropCycle!;
      }
    });
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
      _showPhotoValidationErrors = false;
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
    _plotFocusNode.dispose();
    _vehicleFocusNode.dispose();
    _trailerFocusNode.dispose();
    _issueScrollCtrl.dispose();
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
        detectedCdn = detectedCdn.replaceAll(RegExp(r'[^cbCB0-9]'), '').toUpperCase();
        if (detectedCdn.isNotEmpty) {
          setState(() {
            _cdnCtrl.text = detectedCdn!;
            if (_cdnPhoto != null && _tractorPhoto != null && _trailerPhoto != null && _loadPhoto != null) {
              _showPhotoValidationErrors = false;
            }
          });
        }
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
          if (_cdnPhoto != null && _tractorPhoto != null && _trailerPhoto != null && _loadPhoto != null) {
            _showPhotoValidationErrors = false;
          }
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
    final cdnNo = _cdnCtrl.text.trim().replaceAll(RegExp(r'\s+'), '').toUpperCase();
    if (cdnNo.isEmpty || !RegExp(r'^[CB0-9]+$').hasMatch(cdnNo)) {
      if (_issueScrollCtrl.hasClients) {
        _issueScrollCtrl.animateTo(0.0, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
      }
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          backgroundColor: Color(0xffb91c1c),
          behavior: SnackBarBehavior.floating,
          duration: Duration(seconds: 4),
          content: Row(
            children: [
              Icon(Icons.error_outline, color: Colors.white),
              SizedBox(width: 8),
              Expanded(
                child: Text('🛑 CDN අංකය ඇතුළත් කිරීම අනිවාර්යයි! C, B සහ 0-9 අංක පමණක් ඇතුළත් කරන්න.',
                  style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12),
                ),
              ),
            ],
          ),
        ),
      );
      return;
    }

    final formValid = _formKey.currentState!.validate();
    if (!formValid) {
      if (_cdnCtrl.text.trim().isEmpty || _plotCtrl.text.trim().isEmpty) {
        if (_issueScrollCtrl.hasClients) {
          _issueScrollCtrl.animateTo(0.0, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
        }
      }
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          backgroundColor: Color(0xffb91c1c),
          behavior: SnackBarBehavior.floating,
          duration: Duration(seconds: 4),
          content: Row(
            children: [
              Icon(Icons.error_outline, color: Colors.white),
              SizedBox(width: 8),
              Expanded(
                child: Text('රතු පැහැයෙන් සලකුණු කර ඇති අනිවාර්ය තොරතුරු සම්පූර්ණ කරන්න! (Please fill required fields)',
                  style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12),
                ),
              ),
            ],
          ),
        ),
      );
      return;
    }

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

    // 3. 100% Mandatory Verification Photos Check (All 4 Photos)
    if (_cdnPhoto == null || _tractorPhoto == null || _trailerPhoto == null || _loadPhoto == null) {
      setState(() => _showPhotoValidationErrors = true);

      if (_issueScrollCtrl.hasClients) {
        if (_cdnPhoto == null) {
          _issueScrollCtrl.animateTo(0.0, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
        } else {
          _issueScrollCtrl.animateTo(
            _issueScrollCtrl.position.maxScrollExtent,
            duration: const Duration(milliseconds: 400),
            curve: Curves.easeOut,
          );
        }
      }

      final List<String> missingPhotos = [];
      if (_cdnPhoto == null) missingPhotos.add('CDN Slip (ස්ලිප් පත්‍රිකාව - Scan Slip)');
      if (_tractorPhoto == null) missingPhotos.add('Tractor Plate (ට්‍රැක්ටර් අංකය)');
      if (_trailerPhoto == null) missingPhotos.add('Trailer No (ට්‍රේලර් අංකය)');
      if (_loadPhoto == null) missingPhotos.add('Loaded Cane (උක් පැටවූ බර)');

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          backgroundColor: const Color(0xffb91c1c),
          behavior: SnackBarBehavior.floating,
          duration: const Duration(seconds: 5),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Row(
                children: [
                  Icon(Icons.camera_alt, color: Colors.white, size: 20),
                  SizedBox(width: 8),
                  Text('අනිවාර්ය ඡායාරූප සම්පූර්ණ කර නොමැත!', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13)),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                'කරුණාකර පහත ඡායාරූප ලබා දෙන්න:\n• ' + missingPhotos.join('\n• '),
                style: const TextStyle(color: Color(0xfffee2e2), fontSize: 11),
              ),
            ],
          ),
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
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        var currentItem = Map<String, dynamic>.from(item);
        bool fetchedCloudPhotos = false;

        return StatefulBuilder(
          builder: (ctx, setModalState) {
            final cdn = currentItem['cdn']?.toString() ?? '';
            final plotManual = currentItem['plot_manual_code']?.toString() ?? '';
            final plotNo = currentItem['plot_no']?.toString() ?? '';
            final farmer = currentItem['farmer_name']?.toString() ?? '';
            final farmerNo = currentItem['farmer_no']?.toString() ?? '';
            final vehicle = currentItem['vehicle_no']?.toString() ?? '';
            final trailer = currentItem['trailer_no']?.toString() ?? '';
            final type = (currentItem['type'] ?? 'CC').toString().toUpperCase();
            final cat = currentItem['cane_category_code']?.toString() ?? '';
            final crop = currentItem['crop_cycle']?.toString() ?? '';
            final cropCycleId = currentItem['crop_cycle_id']?.toString() ?? '';
            final isFinal = (currentItem['lst_hvst_tonnage'] == 1 || currentItem['lst_hvst_tonnage'] == true || currentItem['lst_hvst_tonnage'] == '1');
            final wbtNo = currentItem['weige_ticket_no']?.toString() ?? '';
            final netWeight = (currentItem['net_weight'] is num)
                ? (currentItem['net_weight'] as num).toDouble()
                : (double.tryParse(currentItem['net_weight']?.toString() ?? '') ?? 0.0);
            final tonnage = netWeight > 0
                ? (netWeight / 1000.0)
                : ((currentItem['tonnage_received'] is num) ? (currentItem['tonnage_received'] as num).toDouble() : 0.0);
            final gross = (currentItem['gross_weight'] is num) ? (currentItem['gross_weight'] as num).toDouble() : 0.0;
            final tare = (currentItem['tare_weight'] is num) ? (currentItem['tare_weight'] as num).toDouble() : 0.0;
            final createTime = currentItem['create_time']?.toString() ?? '';
            final weighedDate = (currentItem['wbt_create_time'] != null && currentItem['wbt_create_time'].toString().trim().isNotEmpty)
                ? currentItem['wbt_create_time'].toString().trim()
                : (currentItem['weighed_date']?.toString() ?? '');
            final status = (currentItem['status'] ?? 'PENDING').toString().toUpperCase();
            final isCompleted = status == 'COMPLETED';
            final isDeleted = (currentItem['is_deleted'] == 1 || currentItem['is_deleted'] == true || currentItem['is_deleted'] == '1');
            final cancelReason = currentItem['cancel_reason']?.toString() ?? '';
            final createUser = currentItem['create_user']?.toString() ?? '';
            final faNo = currentItem['field_asst_no']?.toString() ?? '';
            final isSyncedMssql = (currentItem['synced_to_mssql'] == 1 || currentItem['synced_to_mssql'] == true || currentItem['synced_to_mssql'] == '1');

            // 30-Minute Grace Window Check
            DateTime? cDate = DateTime.tryParse(createTime);
            final isWithin30 = cDate != null && DateTime.now().difference(cDate).inMinutes < 30;
            final isWeighed = isCompleted || netWeight > 0 || wbtNo.isNotEmpty;
            final canEditOrCancel = isWithin30 && !isWeighed && !isDeleted;

            // Fetch live cloud photos if local files were deleted
            if (!fetchedCloudPhotos && cdn.isNotEmpty) {
              fetchedCloudPhotos = true;
              Future.microtask(() async {
                try {
                  final res = await Api.get('cdn_get', params: {'cdn': cdn}).timeout(const Duration(seconds: 4));
                  if (res['ok'] == true && res['item'] is Map) {
                    final serverData = Map<String, dynamic>.from(res['item'] as Map);
                    setModalState(() {
                      currentItem.addAll(serverData);
                    });
                    await LocalDb.updateCdnPhotos(
                      cdn: cdn,
                      cdnPhoto: serverData['cdn_photo']?.toString(),
                      tractorPhoto: serverData['tractor_photo']?.toString(),
                      trailerPhoto: serverData['trailer_photo']?.toString(),
                      loadPhoto: serverData['load_photo']?.toString(),
                    );
                  }
                } catch (_) {}
              });
            }

            return Container(
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
                          color: (item['synced'] == 1 || item['synced'] == true || item['synced'] == '1' || (item['id'] != null && item['id'] != 0))
                              ? const Color(0xff065f46)
                              : const Color(0xff78350f),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Text(
                          (item['synced'] == 1 || item['synced'] == true || item['synced'] == '1' || (item['id'] != null && item['id'] != 0))
                              ? '☁️ Cloud Synced ✅'
                              : '☁️ Sync Pending ⏳',
                          style: TextStyle(
                            color: (item['synced'] == 1 || item['synced'] == true || item['synced'] == '1' || (item['id'] != null && item['id'] != 0))
                                ? const Color(0xffa7f3d0)
                                : const Color(0xfffde68a),
                            fontSize: 10,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
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
                        _buildDetailRow('Cane Category', _formatCaneCategory(cat)),
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
                      _buildGalleryThumbnail('CDN Slip', currentItem['cdn_photo']?.toString()),
                      const SizedBox(width: 8),
                      _buildGalleryThumbnail('Tractor', currentItem['tractor_photo']?.toString()),
                      const SizedBox(width: 8),
                      _buildGalleryThumbnail('Trailer', currentItem['trailer_photo']?.toString()),
                      const SizedBox(width: 8),
                      _buildGalleryThumbnail('Cane Load', currentItem['load_photo']?.toString()),
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
      );
    },
  );
},
);
}

  String _formatCaneCategory(String code) {
    switch (code.trim().toUpperCase()) {
      case 'CAT1':
        return 'CAT1 (Commercial Green / කොළ උක්)';
      case 'CAT2':
        return 'CAT2 (Commercial Burnt / පිළිස්සූ උක්)';
      case 'CAT3':
        return 'CAT3 (Commercial Seed / බීජ උක්)';
      case 'CAT4':
        return 'CAT4 (Certified Nursery / තවාන් බීජ)';
      default:
        return code.isNotEmpty ? code : 'Standard';
    }
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

  String _resolvePhotoUrl(String path) {
    final clean = path.trim().replaceAll('\\', '/');
    if (clean.isEmpty) return '';
    if (clean.startsWith('http://') || clean.startsWith('https://')) {
      return clean;
    }
    if (clean.startsWith('uploads/')) {
      return '$kServerBase/$clean';
    }
    if (clean.startsWith('/uploads/')) {
      return '$kServerBase$clean';
    }
    if (!clean.startsWith('/') && (clean.endsWith('.jpg') || clean.endsWith('.png') || clean.endsWith('.jpeg'))) {
      return '$kServerBase/uploads/cdn/$clean';
    }
    if (clean.startsWith('/') && !File(clean).existsSync()) {
      final fname = clean.split('/').last;
      return '$kServerBase/uploads/cdn/$fname';
    }
    return clean;
  }

  Widget _buildPhotoWidget(String rawPath, {BoxFit fit = BoxFit.cover}) {
    final path = _resolvePhotoUrl(rawPath);
    if (path.startsWith('http://') || path.startsWith('https://')) {
      return Image.network(
        path,
        fit: fit,
        loadingBuilder: (ctx, child, progress) {
          if (progress == null) return child;
          return const Center(
            child: SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xff38bdf8)),
            ),
          );
        },
        errorBuilder: (_, err, ___) => const Center(
          child: Icon(Icons.broken_image, color: Colors.white38, size: 24),
        ),
      );
    }
    final f = File(path);
    if (f.existsSync()) {
      return Image.file(
        f,
        fit: fit,
        errorBuilder: (_, __, ___) => const Center(
          child: Icon(Icons.photo, color: Colors.white38, size: 24),
        ),
      );
    }
    return const Center(
      child: Icon(Icons.photo, color: Colors.white38, size: 24),
    );
  }

  Widget _buildGalleryThumbnail(String title, String? path) {
    final hasPhoto = path != null && path.trim().isNotEmpty;
    return Expanded(
      child: GestureDetector(
        onTap: () {
          if (hasPhoto) {
            _openZoomPhoto(title, path);
          }
        },
        child: Container(
          height: 70,
          decoration: BoxDecoration(
            color: const Color(0xff0f172a),
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: hasPhoto ? const Color(0xff38bdf8) : const Color(0xff334155)),
          ),
          child: hasPhoto
              ? ClipRRect(
                  borderRadius: BorderRadius.circular(7),
                  child: _buildPhotoWidget(path),
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
                child: _buildPhotoWidget(path, fit: BoxFit.contain),
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
      await Api.post('cdn_mark_final_load', {'cdn': cdn});
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
              await Api.post('cdn_cancel', {'cdn': cdn, 'reason': r});
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
                await Api.post('cdn_edit', {
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

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;

    return Container(
      height: MediaQuery.of(context).size.height * 0.90,
      decoration: const BoxDecoration(
        color: Color(0xff0b1120),
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        border: Border(top: BorderSide(color: Color(0xfff59e0b), width: 2)),
      ),
      child: Column(
        children: [
          // Drag handle
          Container(
            margin: const EdgeInsets.only(top: 10, bottom: 8),
            width: 44,
            height: 4,
            decoration: BoxDecoration(color: const Color(0xff475569), borderRadius: BorderRadius.circular(2)),
          ),
          // Title Bar with Tab Buttons
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(7),
                  decoration: BoxDecoration(
                    color: const Color(0xfff59e0b).withOpacity(0.2),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.agriculture, color: Color(0xfffbbf24), size: 20),
                ),
                const SizedBox(width: 10),
                const Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Cane Delivery Note (CDN)',
                        style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                      Text(
                        'Field Issuance & Weighbridge Tracker',
                        style: TextStyle(color: Color(0xff94a3b8), fontSize: 11),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close, color: Colors.white60, size: 20),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),
          // Tab Switcher Segment
          Container(
            margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            padding: const EdgeInsets.all(3),
            decoration: BoxDecoration(
              color: const Color(0xff1e293b),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xff334155)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: GestureDetector(
                    onTap: () => setState(() => _activeTab = 0),
                    child: Container(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      decoration: BoxDecoration(
                        color: _activeTab == 0 ? const Color(0xfff59e0b) : Colors.transparent,
                        borderRadius: BorderRadius.circular(9),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.edit_note, size: 16, color: _activeTab == 0 ? Colors.black : Colors.white70),
                          const SizedBox(width: 6),
                          Text(
                            'Issue CDN',
                            style: TextStyle(
                              color: _activeTab == 0 ? Colors.black : Colors.white70,
                              fontWeight: FontWeight.bold,
                              fontSize: 13,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                Expanded(
                  child: GestureDetector(
                    onTap: () {
                      setState(() => _activeTab = 1);
                      _loadTrackerData();
                    },
                    child: Container(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      decoration: BoxDecoration(
                        color: _activeTab == 1 ? const Color(0xff059669) : Colors.transparent,
                        borderRadius: BorderRadius.circular(9),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.scale, size: 16, color: _activeTab == 1 ? Colors.white : Colors.white70),
                          const SizedBox(width: 6),
                          Text(
                            'Weighbridge Tracker',
                            style: TextStyle(
                              color: _activeTab == 1 ? Colors.white : Colors.white70,
                              fontWeight: FontWeight.bold,
                              fontSize: 13,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const Divider(color: Color(0xff334155), height: 1),
          // Tab Body
          Expanded(
            child: _activeTab == 0 ? _buildIssueTab(bottomInset) : _buildTrackerTab(),
          ),
        ],
      ),
    );
  }

  // --- TAB 1: ISSUE CDN FORM ---
  Widget _buildPhotoSlot(int type, String title, File? file, IconData icon, bool mandatory) {
    final bool isMissing = mandatory && file == null && _showPhotoValidationErrors;
    return Expanded(
      child: GestureDetector(
        onTap: () => _pickVerificationPhoto(type),
        child: Container(
          height: 96,
          decoration: BoxDecoration(
            color: file != null
                ? const Color(0xff065f46).withOpacity(0.3)
                : (isMissing ? const Color(0xff450a0a) : const Color(0xff0f172a)),
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color: file != null
                  ? const Color(0xff10b981)
                  : (isMissing ? const Color(0xffef4444) : (mandatory ? const Color(0xffef4444) : const Color(0xff334155))),
              width: file != null ? 1.5 : (isMissing ? 2.2 : (mandatory ? 1.2 : 1.0)),
            ),
          ),
          child: file != null
              ? Stack(
                  children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(11),
                      child: Image.file(file, width: double.infinity, height: double.infinity, fit: BoxFit.cover),
                    ),
                    Positioned(
                      top: 4,
                      right: 4,
                      child: GestureDetector(
                        onTap: () {
                          setState(() {
                            if (type == 1) _tractorPhoto = null;
                            if (type == 2) _trailerPhoto = null;
                            if (type == 3) _loadPhoto = null;
                          });
                        },
                        child: Container(
                          padding: const EdgeInsets.all(2),
                          decoration: const BoxDecoration(color: Colors.black87, shape: BoxShape.circle),
                          child: const Icon(Icons.close, color: Colors.white, size: 14),
                        ),
                      ),
                    ),
                    Positioned(
                      bottom: 0,
                      left: 0,
                      right: 0,
                      child: Container(
                        padding: const EdgeInsets.symmetric(vertical: 2),
                        decoration: const BoxDecoration(
                          color: Colors.black54,
                          borderRadius: BorderRadius.vertical(bottom: Radius.circular(11)),
                        ),
                        child: Text(
                          title,
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                        ),
                      ),
                    ),
                  ],
                )
              : Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(
                      isMissing ? Icons.error : icon,
                      color: isMissing ? const Color(0xffef4444) : (mandatory ? const Color(0xfff87171) : const Color(0xff38bdf8)),
                      size: 24,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      title,
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: isMissing ? const Color(0xfffca5a5) : const Color(0xffcbd5e1),
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      isMissing ? '⚠️ අනිවාර්යයි!' : (mandatory ? '* අනිවාර්යයි' : '+ Take Photo'),
                      style: TextStyle(
                        color: isMissing ? const Color(0xffef4444) : (mandatory ? const Color(0xfff87171) : const Color(0xff38bdf8)),
                        fontSize: 9,
                        fontWeight: isMissing ? FontWeight.bold : FontWeight.normal,
                      ),
                    ),
                  ],
                ),
        ),
      ),
    );
  }

  Widget _buildIssueTab(double bottomInset) {
    return Form(
      key: _formKey,
      child: SingleChildScrollView(
        controller: _issueScrollCtrl,
        padding: EdgeInsets.only(left: 16, right: 16, top: 12, bottom: bottomInset + 30),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
          // 1. Dual Cane Types Selector: Commercial Cane (CC) vs Seed Cane (SC)
          Container(
            padding: const EdgeInsets.all(4),
            decoration: BoxDecoration(
              color: const Color(0xff1e293b),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: const Color(0xff334155)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: GestureDetector(
                    onTap: () => _onCaneTypeChanged('CC'),
                    child: Container(
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      decoration: BoxDecoration(
                        color: _caneType == 'CC' ? const Color(0xfff59e0b) : Colors.transparent,
                        borderRadius: BorderRadius.circular(9),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Text('🌾 ', style: TextStyle(fontSize: _caneType == 'CC' ? 14 : 12)),
                          Text(
                            'Commercial Cane (CC)',
                            style: TextStyle(
                              color: _caneType == 'CC' ? Colors.black : Colors.white70,
                              fontWeight: FontWeight.bold,
                              fontSize: 12,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                Expanded(
                  child: GestureDetector(
                    onTap: () => _onCaneTypeChanged('SC'),
                    child: Container(
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      decoration: BoxDecoration(
                        color: _caneType == 'SC' ? const Color(0xff10b981) : Colors.transparent,
                        borderRadius: BorderRadius.circular(9),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Text('🌱 ', style: TextStyle(fontSize: _caneType == 'SC' ? 14 : 12)),
                          Text(
                            'Seed Cane (SC)',
                            style: TextStyle(
                              color: _caneType == 'SC' ? Colors.black : Colors.white70,
                              fontWeight: FontWeight.bold,
                              fontSize: 12,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          // 2. CDN Number with Camera Scan Slip Button
          TextFormField(
            controller: _cdnCtrl,
            scrollPadding: const EdgeInsets.only(bottom: 140),
            textCapitalization: TextCapitalization.characters,
            keyboardType: TextInputType.visiblePassword,
            inputFormatters: [
              FilteringTextInputFormatter.allow(RegExp(r'[cbCB0-9]')),
              TextInputFormatter.withFunction((oldVal, newVal) {
                return newVal.copyWith(text: newVal.text.toUpperCase());
              }),
            ],
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, letterSpacing: 1.5),
            decoration: InputDecoration(
              labelText: 'CDN Number (C, B සහ අංක පමණි)',
              labelStyle: const TextStyle(color: Color(0xfffbbf24)),
              prefixIcon: const Icon(Icons.tag, color: Color(0xfff59e0b), size: 18),
              errorMaxLines: 2,
              suffixIcon: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (_cdnPhoto != null)
                    Padding(
                      padding: const EdgeInsets.only(right: 6),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(6),
                        child: Image.file(_cdnPhoto!, width: 32, height: 32, fit: BoxFit.cover),
                      ),
                    ),
                  ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: (_showPhotoValidationErrors && _cdnPhoto == null)
                          ? const Color(0xffdc2626)
                          : const Color(0xff0284c7),
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    icon: Icon((_showPhotoValidationErrors && _cdnPhoto == null) ? Icons.warning : Icons.camera_alt, size: 16),
                    label: Text(
                      (_showPhotoValidationErrors && _cdnPhoto == null) ? 'Scan Slip *' : 'Scan Slip',
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold),
                    ),
                    onPressed: _scanCdnSlip,
                  ),
                  const SizedBox(width: 8),
                ],
              ),
              filled: true,
              fillColor: const Color(0xff1e293b),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xff334155))),
              enabledBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: BorderSide(
                  color: (_showPhotoValidationErrors && _cdnPhoto == null) ? Colors.redAccent : const Color(0xff334155),
                  width: (_showPhotoValidationErrors && _cdnPhoto == null) ? 1.5 : 1.0,
                ),
              ),
            ),
            validator: (val) {
              if (val == null || val.trim().isEmpty) {
                return 'CDN අංකය අනිවාර්යයි (CDN Number is required)';
              }
              final clean = val.trim().toUpperCase();
              if (!RegExp(r'^[CB0-9]+$').hasMatch(clean)) {
                return 'වලංගු නොවන CDN අංකයකි! C, B සහ අංක (0-9) පමණක් ඇතුළත් කරන්න.';
              }
              return null;
            },
          ),
          const SizedBox(height: 12),
          // 3. Plot Manual Code & Autocomplete lookup
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              TextFormField(
                controller: _plotCtrl,
                focusNode: _plotFocusNode,
                scrollPadding: const EdgeInsets.only(bottom: 140),
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
                decoration: InputDecoration(
                  labelText: 'Plot Manual Code (e.g. V/07/16, H/179/13B)',
                  labelStyle: const TextStyle(color: Color(0xff94a3b8)),
                  prefixIcon: const Icon(Icons.terrain, color: Color(0xff34d399), size: 20),
                  suffixIcon: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (_searchingPlots)
                        const Padding(
                          padding: EdgeInsets.all(12),
                          child: SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xff38bdf8)),
                          ),
                        )
                      else if (_plotCtrl.text.isNotEmpty)
                        IconButton(
                          icon: const Icon(Icons.clear, color: Colors.white54, size: 18),
                          onPressed: () {
                            _plotCtrl.clear();
                            setState(() {
                              _plotSuggestions = [];
                              _selectedSystemPlotNo = null;
                            });
                          },
                        ),
                      IconButton(
                        tooltip: 'Detect Nearest Plot via GPS',
                        icon: const Icon(Icons.my_location, color: Color(0xff34d399), size: 20),
                        onPressed: _pickNearestGpsPlot,
                      ),
                      IconButton(
                        tooltip: 'Search Master Plots',
                        icon: const Icon(Icons.search, color: Color(0xff38bdf8), size: 20),
                        onPressed: () => _searchPlotsLocalAndOnline(_plotCtrl.text),
                      ),
                    ],
                  ),
                  filled: true,
                  fillColor: const Color(0xff1e293b),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xff334155))),
                ),
                onChanged: _onPlotInputChanged,
                validator: (val) {
                  if (val == null || val.trim().isEmpty) return 'Plot code required';
                  if (_isUnregisteredPlot) return 'කර්මාන්තශාලා ERP පද්ධතියේ ලියාපදිංචි නැති බිම් අංකයකි!';
                  return null;
                },
              ),
              if (_isUnregisteredPlot && _plotCtrl.text.trim().isNotEmpty && _plotSuggestions.isEmpty)
                Container(
                  margin: const EdgeInsets.only(top: 8),
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: const Color(0xff7f1d1d).withOpacity(0.5),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: const Color(0xffef4444)),
                  ),
                  child: const Row(
                    children: [
                      Icon(Icons.warning_amber_rounded, color: Color(0xfff87171), size: 20),
                      SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          'අවවාදයයි: මෙම බිම් කේතය (Plot Code) කර්මාන්තශාලා ERP පද්ධතියේ ලියාපදිංචි වී නොමැත. කරුණාකර නිවැරදි කේතය තෝරන්න.',
                          style: TextStyle(color: Color(0xfffca5a5), fontSize: 11, fontWeight: FontWeight.bold),
                        ),
                      ),
                    ],
                  ),
                ),
              if (_plotSuggestions.isNotEmpty) ...[
                const SizedBox(height: 6),
                Container(
                  constraints: const BoxConstraints(maxHeight: 220),
                  decoration: BoxDecoration(
                    color: const Color(0xff0f172a),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: const Color(0xff38bdf8).withOpacity(0.5), width: 1.2),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(0.4),
                        blurRadius: 8,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        child: Row(
                          children: [
                            const Icon(Icons.touch_app, size: 14, color: Color(0xff38bdf8)),
                            const SizedBox(width: 6),
                            Text(
                              'Select Plot (${_plotSuggestions.length} found)',
                              style: const TextStyle(color: Color(0xff38bdf8), fontSize: 11, fontWeight: FontWeight.bold),
                            ),
                            const Spacer(),
                            GestureDetector(
                              onTap: () => setState(() => _plotSuggestions = []),
                              child: const Icon(Icons.close, size: 16, color: Colors.white54),
                            ),
                          ],
                        ),
                      ),
                      const Divider(height: 1, color: Color(0xff334155)),
                      Flexible(
                        child: ListView.separated(
                          shrinkWrap: true,
                          padding: EdgeInsets.zero,
                          itemCount: _plotSuggestions.length,
                          separatorBuilder: (_, __) => const Divider(height: 1, color: Color(0xff1e293b)),
                          itemBuilder: (ctx, idx) {
                            final p = _plotSuggestions[idx];
                            final manualCode = (p['plot_manual_code'] ?? p['plot_no'] ?? '').toString();
                            final systemPlot = p['plot_no']?.toString() ?? '';
                            final farmer = (p['farmer_name'] ?? '').toString();
                            final sector = (p['sector_no'] ?? '').toString();
                            final area = (p['land_area'] != null &&
                                    p['land_area'].toString().isNotEmpty &&
                                    p['land_area'].toString() != '0.0')
                                ? '${p['land_area']} Ha'
                                : '';
                            final hasGf = p['has_geofence'] == 1;

                            return ListTile(
                              dense: true,
                              visualDensity: VisualDensity.compact,
                              leading: Icon(
                                hasGf ? Icons.terrain : Icons.grid_on,
                                color: hasGf ? const Color(0xff34d399) : const Color(0xff38bdf8),
                                size: 20,
                              ),
                              title: Row(
                                children: [
                                  Text(
                                    manualCode,
                                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14),
                                  ),
                                  if (systemPlot.isNotEmpty && systemPlot != manualCode) ...[
                                    const SizedBox(width: 6),
                                    Text('($systemPlot)', style: const TextStyle(color: Color(0xff94a3b8), fontSize: 11)),
                                  ],
                                  if (sector.isNotEmpty) ...[
                                    const SizedBox(width: 8),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                                      decoration: BoxDecoration(
                                        color: const Color(0xff334155),
                                        borderRadius: BorderRadius.circular(4),
                                      ),
                                      child: Text(
                                        'Sec $sector',
                                        style: const TextStyle(color: Color(0xff94a3b8), fontSize: 10, fontWeight: FontWeight.bold),
                                      ),
                                    ),
                                  ],
                                  if (area.isNotEmpty) ...[
                                    const SizedBox(width: 6),
                                    Text(area, style: const TextStyle(color: Color(0xffa7f3d0), fontSize: 10)),
                                  ],
                                ],
                              ),
                              subtitle: farmer.isNotEmpty
                                  ? Text(
                                      'Farmer: $farmer',
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(color: Color(0xffcbd5e1), fontSize: 11),
                                    )
                                  : null,
                              trailing: const Icon(Icons.arrow_forward_ios, size: 12, color: Color(0xff38bdf8)),
                              onTap: () => _selectPlotSuggestion(p),
                            );
                          },
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 12),
          // 4. Farmer Name (Read-Only ERP Master Locked)
          TextFormField(
            controller: _farmerNameCtrl,
            readOnly: true,
            scrollPadding: const EdgeInsets.only(bottom: 140),
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
            decoration: InputDecoration(
              labelText: 'Farmer Name (පද්ධතියෙන් තහවුරු කළ / Locked)',
              labelStyle: const TextStyle(color: Color(0xff94a3b8)),
              prefixIcon: const Icon(Icons.person, color: Color(0xff38bdf8), size: 18),
              suffixIcon: const Tooltip(
                message: 'ERP Master Locked',
                child: Icon(Icons.lock, color: Color(0xff94a3b8), size: 16),
              ),
              filled: true,
              fillColor: const Color(0xff1e293b),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xff334155))),
            ),
          ),
          const SizedBox(height: 12),
          // 5. Land Area (Ha) (Read-Only ERP Master Locked)
          TextFormField(
            controller: _landAreaCtrl,
            readOnly: true,
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
            decoration: InputDecoration(
              labelText: 'Plot Land Area (Ha) / වගා බිමේ ප්‍රමාණය',
              labelStyle: const TextStyle(color: Color(0xff94a3b8)),
              prefixIcon: const Icon(Icons.square_foot, color: Color(0xff34d399), size: 18),
              suffixIcon: const Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text('Ha  ', style: TextStyle(color: Color(0xff34d399), fontWeight: FontWeight.bold)),
                  Icon(Icons.lock, color: Color(0xff94a3b8), size: 16),
                  SizedBox(width: 8),
                ],
              ),
              filled: true,
              fillColor: const Color(0xff1e293b),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xff334155))),
            ),
          ),
          const SizedBox(height: 12),
          // 6. ELASTIC STANDING CROP SELECTOR (Supports 4+ Crops cleanly)
          if (_loadingCrops)
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: const Color(0xff1e293b), borderRadius: BorderRadius.circular(12)),
              child: const Row(
                children: [
                  SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xff10b981))),
                  SizedBox(width: 12),
                  Text('Checking standing crops in ERP...', style: TextStyle(color: Color(0xffcbd5e1), fontSize: 12)),
                ],
              ),
            )
          else if (_plotCtrl.text.trim().isNotEmpty && _activeCrops.isNotEmpty)
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xff1e293b),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xff059669)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Row(
                    children: [
                      Icon(Icons.eco, color: Color(0xff10b981), size: 16),
                      SizedBox(width: 6),
                      Text('STANDING CROPS (සක්‍රීය බෝගය තෝරන්න):', style: TextStyle(color: Color(0xff10b981), fontWeight: FontWeight.bold, fontSize: 11)),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: _activeCrops.map((c) {
                      final cycle = (c['crop_cycle'] ?? '').toString();
                      final cycleId = (c['crop_cycle_id'] ?? c['ID'] ?? '').toString();
                      final ext = (c['crop_extent'] != null &&
                              c['crop_extent'].toString().isNotEmpty &&
                              c['crop_extent'].toString() != '0.0')
                          ? '${c['crop_extent']} Ha'
                          : '';
                      final varName = (c['variety'] ?? '').toString();
                      final age = (c['age_month'] ?? '').toString();
                      final isSel = _selectedCropCycle == cycle;
                      return ChoiceChip(
                        selected: isSel,
                        label: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              '$cycle (${ext.isNotEmpty ? ext : ""}${varName.isNotEmpty ? " • $varName" : ""})',
                              style: TextStyle(color: isSel ? Colors.black : Colors.white, fontWeight: FontWeight.bold, fontSize: 12),
                            ),
                            if (age.isNotEmpty)
                              Padding(
                                padding: const EdgeInsets.only(top: 2),
                                child: Text(
                                  '⏳ Age: $age Mo (මාස $age)',
                                  style: TextStyle(
                                    color: isSel ? Colors.black87 : const Color(0xff38bdf8),
                                    fontSize: 10,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ),
                          ],
                        ),
                        selectedColor: const Color(0xff10b981),
                        backgroundColor: const Color(0xff0f172a),
                        onSelected: (val) {
                          if (val) {
                            setState(() {
                              _selectedCropCycle = cycle;
                              _selectedCropCycleId = cycleId;
                              _cropCycleCtrl.text = cycle;
                            });
                          }
                        },
                      );
                    }).toList(),
                  ),
                ],
              ),
            )
          else if (_plotCtrl.text.trim().isNotEmpty)
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xff7f1d1d).withOpacity(0.5),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xffef4444), width: 1.5),
              ),
              child: const Row(
                children: [
                  Icon(Icons.block, color: Color(0xfff87171), size: 28),
                  SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '🛑 මෙම ඉඩම සඳහා සක්‍රීය බෝගයක් වාර්තා වී නොමැත',
                          style: TextStyle(color: Color(0xfffca5a5), fontWeight: FontWeight.bold, fontSize: 13),
                        ),
                        SizedBox(height: 4),
                        Text(
                          'බෝගයක් නොමැති ඉඩම් සඳහා Cane Delivery Note නිකුත් කළ නොහැක.',
                          style: TextStyle(color: Colors.white70, fontSize: 11),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),,
          const SizedBox(height: 12),
          // 7. Vehicle No (Instant Autocomplete)
          RawAutocomplete<String>(
            textEditingController: _vehicleCtrl,
            focusNode: _vehicleFocusNode,
            optionsBuilder: (TextEditingValue val) {
              if (val.text.trim().isEmpty) return const Iterable<String>.empty();
              final q = val.text.trim().toUpperCase();
              return _availableVehicles.where((s) => s.toUpperCase().contains(q)).take(8);
            },
            optionsViewBuilder: (ctx, onSelected, options) {
              return Align(
                alignment: Alignment.topLeft,
                child: Material(
                  elevation: 6,
                  color: const Color(0xff0f172a),
                  borderRadius: BorderRadius.circular(12),
                  child: Container(
                    width: MediaQuery.of(context).size.width * 0.88,
                    constraints: const BoxConstraints(maxHeight: 180),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xfff97316)),
                    ),
                    child: ListView.separated(
                      padding: EdgeInsets.zero,
                      shrinkWrap: true,
                      itemCount: options.length,
                      separatorBuilder: (_, __) => const Divider(height: 1, color: Color(0xff1e293b)),
                      itemBuilder: (ctx, idx) {
                        final v = options.elementAt(idx);
                        return ListTile(
                          dense: true,
                          visualDensity: VisualDensity.compact,
                          leading: const Icon(Icons.local_shipping, color: Color(0xfff97316), size: 18),
                          title: Text(v, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13)),
                          subtitle: const Text('Active ERP Vehicle', style: TextStyle(color: Color(0xff10b981), fontSize: 10)),
                          onTap: () => onSelected(v),
                        );
                      },
                    ),
                  ),
                ),
              );
            },
            fieldViewBuilder: (ctx, ctrl, fn, onSubmitted) {
              return TextFormField(
                controller: ctrl,
                focusNode: fn,
                scrollPadding: const EdgeInsets.only(bottom: 140),
                textCapitalization: TextCapitalization.characters,
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
                decoration: InputDecoration(
                  labelText: 'Tractor / Vehicle No (e.g. 228- or DAH)',
                  labelStyle: const TextStyle(color: Color(0xff94a3b8)),
                  prefixIcon: const Icon(Icons.local_shipping, color: Color(0xfff97316), size: 18),
                  filled: true,
                  fillColor: const Color(0xff1e293b),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xff334155))),
                ),
                validator: (val) => val == null || val.trim().isEmpty ? 'Vehicle No required' : null,
              );
            },
          ),
          const SizedBox(height: 12),
          // 8. Trailer No (Instant Autocomplete)
          RawAutocomplete<String>(
            textEditingController: _trailerCtrl,
            focusNode: _trailerFocusNode,
            optionsBuilder: (TextEditingValue val) {
              if (val.text.trim().isEmpty) return const Iterable<String>.empty();
              final q = val.text.trim().toUpperCase();
              return _availableTrailers.where((s) => s.toUpperCase().contains(q)).take(8);
            },
            optionsViewBuilder: (ctx, onSelected, options) {
              return Align(
                alignment: Alignment.topLeft,
                child: Material(
                  elevation: 6,
                  color: const Color(0xff0f172a),
                  borderRadius: BorderRadius.circular(12),
                  child: Container(
                    width: MediaQuery.of(context).size.width * 0.88,
                    constraints: const BoxConstraints(maxHeight: 180),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xff38bdf8)),
                    ),
                    child: ListView.separated(
                      padding: EdgeInsets.zero,
                      shrinkWrap: true,
                      itemCount: options.length,
                      separatorBuilder: (_, __) => const Divider(height: 1, color: Color(0xff1e293b)),
                      itemBuilder: (ctx, idx) {
                        final t = options.elementAt(idx);
                        return ListTile(
                          dense: true,
                          visualDensity: VisualDensity.compact,
                          leading: const Icon(Icons.rv_hookup, color: Color(0xff38bdf8), size: 18),
                          title: Text(t, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13)),
                          subtitle: const Text('ERP Trailer Master', style: TextStyle(color: Color(0xff94a3b8), fontSize: 10)),
                          onTap: () => onSelected(t),
                        );
                      },
                    ),
                  ),
                ),
              );
            },
            fieldViewBuilder: (ctx, ctrl, fn, onSubmitted) {
              return TextFormField(
                controller: ctrl,
                focusNode: fn,
                scrollPadding: const EdgeInsets.only(bottom: 140),
                textCapitalization: TextCapitalization.characters,
                style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
                decoration: InputDecoration(
                  labelText: 'Trailer No (Type to match trailer)',
                  labelStyle: const TextStyle(color: Color(0xff94a3b8)),
                  prefixIcon: const Icon(Icons.rv_hookup, color: Color(0xffcbd5e1), size: 18),
                  filled: true,
                  fillColor: const Color(0xff1e293b),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xff334155))),
                ),
                validator: (val) => val == null || val.trim().isEmpty ? 'Trailer No required' : null,
              );
            },
          ),
          const SizedBox(height: 12),
          // 9. Cane Category Dropdown (Filtered dynamically by Cane Type CC/SC)
          DropdownButtonFormField<String>(
            value: _selectedCategory,
            dropdownColor: const Color(0xff1e293b),
            style: const TextStyle(color: Colors.white),
            decoration: InputDecoration(
              labelText: 'Cane Category (${_caneType == "CC" ? "Commercial Cane" : "Seed Cane"})',
              labelStyle: const TextStyle(color: Color(0xff94a3b8)),
              prefixIcon: const Icon(Icons.eco, color: Color(0xff10b981), size: 18),
              filled: true,
              fillColor: const Color(0xff1e293b),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xff334155))),
            ),
            items: _currentCategories.map((c) {
              return DropdownMenuItem<String>(
                value: c['code'],
                child: Text(c['name']!, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
              );
            }).toList(),
            onChanged: (val) {
              if (val != null) setState(() => _selectedCategory = val);
            },
          ),
          const SizedBox(height: 12),
          // 10. FINAL HARVEST LOAD CHECKBOX (LstHvstTonnage)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            decoration: BoxDecoration(
              color: _isFinalHarvestLoad ? const Color(0xff065f46).withOpacity(0.4) : const Color(0xff1e293b),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: _isFinalHarvestLoad ? const Color(0xff10b981) : const Color(0xff334155),
                width: 1.5,
              ),
            ),
            child: CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              value: _isFinalHarvestLoad,
              activeColor: const Color(0xff10b981),
              title: const Row(
                children: [
                  Text('🏁 ', style: TextStyle(fontSize: 16)),
                  Expanded(
                    child: Text(
                      'අවසාන අස්වනු ලෝඩ් එක (Final Harvest Load)',
                      style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                    ),
                  ),
                ],
              ),
              subtitle: const Text(
                'මෙම ඉඩමේ අස්වැන්න කපා අවසන් නම් පමණක් මෙය Tick කරන්න',
                style: TextStyle(color: Color(0xff94a3b8), fontSize: 11),
              ),
              onChanged: (val) {
                if (val == true) {
                  showDialog(
                    context: context,
                    builder: (ctx) => AlertDialog(
                      backgroundColor: const Color(0xff0f172a),
                      title: const Row(
                        children: [
                          Icon(Icons.flag, color: Color(0xff10b981)),
                          SizedBox(width: 8),
                          Text('තහවුරු කිරීම', style: TextStyle(color: Colors.white)),
                        ],
                      ),
                      content: const Text(
                        'මෙම ඉඩම සඳහා මෙය අවසාන ට්‍රැක්ටර් ලෝඩ් එක (Final Harvest Load) බව තහවුරු කරනවාද?',
                        style: TextStyle(color: Colors.white70),
                      ),
                      actions: [
                        TextButton(
                          onPressed: () => Navigator.pop(ctx),
                          child: const Text('නැත', style: TextStyle(color: Colors.white54)),
                        ),
                        ElevatedButton(
                          style: ElevatedButton.styleFrom(backgroundColor: const Color(0xff10b981), foregroundColor: Colors.black),
                          onPressed: () {
                            Navigator.pop(ctx);
                            setState(() => _isFinalHarvestLoad = true);
                          },
                          child: const Text('ඔව්, අවසාන ලෝඩ් එකයි'),
                        ),
                      ],
                    ),
                  );
                } else {
                  setState(() => _isFinalHarvestLoad = false);
                }
              },
            ),
          ),
          const SizedBox(height: 16),
          // 11. 100% MANDATORY VERIFICATION PHOTOS SECTION
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: const Color(0xff1e293b),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: const Color(0xff334155)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Row(
                  children: [
                    Icon(Icons.camera_alt, color: Color(0xff38bdf8), size: 18),
                    SizedBox(width: 8),
                    Text(
                      'VERIFICATION PHOTOS (ඡායාරූප අනිවාර්යයි)',
                      style: TextStyle(color: Color(0xff38bdf8), fontWeight: FontWeight.bold, fontSize: 12),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    _buildPhotoSlot(1, 'Tractor Plate', _tractorPhoto, Icons.local_shipping, true),
                    const SizedBox(width: 8),
                    _buildPhotoSlot(2, 'Trailer No', _trailerPhoto, Icons.rv_hookup, true),
                    const SizedBox(width: 8),
                    _buildPhotoSlot(3, 'Loaded Cane', _loadPhoto, Icons.agriculture, true),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          // 12. Submit Button
          SizedBox(
            width: double.infinity,
            height: 50,
            child: ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xfff59e0b),
                foregroundColor: Colors.black,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                elevation: 4,
              ),
              icon: _submitting
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.black))
                  : const Icon(Icons.check_circle_outline, size: 22),
              label: Text(
                _submitting ? 'ISSUING CDN...' : 'ISSUE CANE DELIVERY NOTE',
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold, letterSpacing: 0.5),
              ),
              onPressed: (_submitting || _hasNoActiveCrop) ? null : _submitCdn,
            ),
          ),
          const SizedBox(height: 16),
          ],
        ),
      ),
    );
  }

  // --- TAB 2: WEIGHBRIDGE DELIVERY TRACKER ---
  Widget _buildTrackerTab() {
    final totalTonnage = (_summary['total_tonnage'] as num?)?.toDouble() ?? 0.0;
    final completedCount = (_summary['completed_count'] as num?)?.toInt() ?? 0;
    final pendingCount = (_summary['pending_count'] as num?)?.toInt() ?? 0;
    final cancelledCount = (_summary['cancelled_count'] as num?)?.toInt() ?? 0;

    return Column(
      children: [
        // Date Selector Bar
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          color: const Color(0xff0f172a),
          child: Row(
            children: [
              IconButton(
                icon: const Icon(Icons.chevron_left, color: Colors.white70),
                onPressed: () => _changeDate(-1),
              ),
              Expanded(
                child: GestureDetector(
                  onTap: _pickDate,
                  child: Container(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    decoration: BoxDecoration(
                      color: const Color(0xff1e293b),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: const Color(0xff334155)),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Icon(Icons.calendar_today, size: 14, color: Color(0xfffbbf24)),
                        const SizedBox(width: 8),
                        Text(
                          _formatDate(_trackerDate),
                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              IconButton(
                icon: const Icon(Icons.chevron_right, color: Colors.white70),
                onPressed: () => _changeDate(1),
              ),
              IconButton(
                icon: const Icon(Icons.refresh, color: Color(0xff38bdf8)),
                onPressed: _loadTrackerData,
              ),
            ],
          ),
        ),
        // Filter Chips Row
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
          child: Row(
            children: [
              _buildFilterChip('COMPLETED', '✅ Weighed ($completedCount)'),
              const SizedBox(width: 8),
              _buildFilterChip('PENDING', '⏳ In-Transit ($pendingCount)'),
              const SizedBox(width: 8),
              _buildFilterChip('IN_TRANSIT_ALL', '🌐 All In-Transit'),
              const SizedBox(width: 8),
              _buildFilterChip('CANCELLED', '❌ Cancelled ($cancelledCount)'),
              const SizedBox(width: 8),
              _buildFilterChip('ALL', 'All'),
            ],
          ),
        ),
        // Total Tonnage Received Banner (Calculated from net_weight MT)
        Container(
          margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [Color(0xff064e3b), Color(0xff047857)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            borderRadius: BorderRadius.circular(14),
            boxShadow: [
              BoxShadow(color: const Color(0xff064e3b).withOpacity(0.5), blurRadius: 10, offset: const Offset(0, 4)),
            ],
          ),
          child: Row(
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: Colors.black.withOpacity(0.2), shape: BoxShape.circle),
                child: const Icon(Icons.scale, color: Colors.white, size: 24),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Total Tonnage Received', style: TextStyle(color: Color(0xffa7f3d0), fontSize: 11, fontWeight: FontWeight.w600)),
                    const SizedBox(height: 2),
                    Text(
                      '${totalTonnage.toStringAsFixed(2)} MT',
                      style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text('$completedCount Weighed', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13)),
                  Text('$pendingCount In-Transit', style: const TextStyle(color: Color(0xfffde68a), fontSize: 11)),
                ],
              ),
            ],
          ),
        ),
        // Search bar
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
          child: TextField(
            controller: _trackerSearchCtrl,
            style: const TextStyle(color: Colors.white, fontSize: 13),
            onChanged: (val) => _loadTrackerData(),
            decoration: InputDecoration(
              hintText: 'Filter by CDN No, Vehicle or Plot...',
              hintStyle: const TextStyle(color: Colors.white38, fontSize: 12),
              prefixIcon: const Icon(Icons.search, size: 16, color: Color(0xff94a3b8)),
              filled: true,
              fillColor: const Color(0xff1e293b),
              isDense: true,
              contentPadding: const EdgeInsets.symmetric(vertical: 8),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: Color(0xff334155))),
            ),
          ),
        ),
        // Delivery Cards List
        Expanded(
          child: _loadingTracker
              ? const Center(child: CircularProgressIndicator(color: Color(0xfff59e0b)))
              : _cdnList.isEmpty
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(32),
                        child: Text(
                          _statusFilter == 'COMPLETED'
                              ? 'No completed weighbridge tickets for this date.\nSwitch to In-Transit to view dispatched cane.'
                              : 'No CDN records found for this date.',
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: Colors.white38, fontSize: 13),
                        ),
                      ),
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                      itemCount: _cdnList.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 8),
                      itemBuilder: (ctx, i) {
                        final item = _cdnList[i];
                        final cdn = item['cdn']?.toString() ?? '';
                        final status = (item['status'] ?? 'PENDING').toString().toUpperCase();
                        final isCompleted = status == 'COMPLETED';
                        final isDeleted = (item['is_deleted'] == 1 || item['is_deleted'] == true || item['is_deleted'] == '1');
                        final isFinal = (item['lst_hvst_tonnage'] == 1 || item['lst_hvst_tonnage'] == true || item['lst_hvst_tonnage'] == '1');
                        final isSyncedMssql = (item['synced_to_mssql'] == 1 || item['synced_to_mssql'] == true || item['synced_to_mssql'] == '1');
                        final wbtNo = item['weige_ticket_no']?.toString() ?? '';

                        final netWeight = (item['net_weight'] is num)
                            ? (item['net_weight'] as num).toDouble()
                            : (double.tryParse(item['net_weight']?.toString() ?? '') ?? 0.0);
                        final tonnage = netWeight > 0
                            ? (netWeight / 1000.0)
                            : ((item['tonnage_received'] is num) ? (item['tonnage_received'] as num).toDouble() : 0.0);

                        final vehicle = item['vehicle_no']?.toString() ?? '';
                        final plotCode = item['plot_manual_code']?.toString() ?? item['plot_no']?.toString() ?? '';
                        final farmer = item['farmer_name']?.toString() ?? '';
                        final weighedDate = (item['wbt_create_time'] != null && item['wbt_create_time'].toString().trim().isNotEmpty)
        ? item['wbt_create_time'].toString().trim()
        : (item['weighed_date']?.toString() ?? '');
                        final createTime = item['create_time']?.toString() ?? '';

                        return GestureDetector(
                          onTap: () => _showCdnDetailsDialog(item),
                          child: Container(
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(
                              color: const Color(0xff1e293b),
                              borderRadius: BorderRadius.circular(14),
                              border: Border.all(
                                color: isDeleted
                                    ? Colors.redAccent
                                    : (isCompleted ? const Color(0xff059669) : const Color(0xffd97706)),
                                width: 1.2,
                              ),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                // Header Row: CDN No + Badges
                                Row(
                                  children: [
                                    Text(
                                      cdn,
                                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14),
                                    ),
                                    if (isFinal) ...[
                                      const SizedBox(width: 6),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                                        decoration: BoxDecoration(color: const Color(0xff581c87), borderRadius: BorderRadius.circular(4)),
                                        child: const Text('🏁 FINAL', style: TextStyle(color: Color(0xfff5d0fe), fontSize: 9, fontWeight: FontWeight.bold)),
                                      ),
                                    ],
                                    const Spacer(),
                                    Container(
                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                      decoration: BoxDecoration(
                                        color: isDeleted
                                            ? Colors.red.withOpacity(0.3)
                                            : (isCompleted ? const Color(0xff065f46) : const Color(0xff78350f)),
                                        borderRadius: BorderRadius.circular(6),
                                      ),
                                      child: Text(
                                        isDeleted ? 'CANCELLED' : (isCompleted ? 'WEIGHED' : 'IN-TRANSIT'),
                                        style: TextStyle(
                                          color: isDeleted
                                              ? Colors.redAccent
                                              : (isCompleted ? const Color(0xffa7f3d0) : const Color(0xfffde68a)),
                                          fontWeight: FontWeight.bold,
                                          fontSize: 10,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 6),
                                if (isCompleted) ...[
                                  // Completed Details Banner
                                  Container(
                                    padding: const EdgeInsets.all(8),
                                    decoration: BoxDecoration(
                                      color: const Color(0xff064e3b).withOpacity(0.3),
                                      borderRadius: BorderRadius.circular(8),
                                      border: Border.all(color: const Color(0xff059669).withOpacity(0.4)),
                                    ),
                                    child: Row(
                                      children: [
                                        const Icon(Icons.receipt, color: Color(0xff34d399), size: 18),
                                        const SizedBox(width: 8),
                                        Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text('WBT No: ${wbtNo.isNotEmpty ? wbtNo : "Recorded"}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12)),
                                            if (netWeight > 0)
                                              Text('Net: ${netWeight.toStringAsFixed(0)} kg', style: const TextStyle(color: Color(0xff94a3b8), fontSize: 10)),
                                          ],
                                        ),
                                        const Spacer(),
                                        Text(
                                          '${tonnage.toStringAsFixed(2)} MT',
                                          style: const TextStyle(color: Color(0xff34d399), fontWeight: FontWeight.bold, fontSize: 18),
                                        ),
                                      ],
                                    ),
                                  ),
                                ] else if (isDeleted) ...[
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                                    decoration: BoxDecoration(
                                      color: Colors.red.withOpacity(0.15),
                                      borderRadius: BorderRadius.circular(8),
                                    ),
                                    child: Row(
                                      children: [
                                        const Icon(Icons.cancel, color: Colors.redAccent, size: 16),
                                        const SizedBox(width: 8),
                                        Expanded(
                                          child: Text(
                                            'Cancelled: ${item["cancel_reason"] ?? "Mistake / Deleted"}',
                                            style: const TextStyle(color: Colors.redAccent, fontSize: 11),
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                ] else ...[
                                  // Pending Message with Live Sync Status Indicators
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                                    decoration: BoxDecoration(
                                      color: const Color(0xff78350f).withOpacity(0.2),
                                      borderRadius: BorderRadius.circular(8),
                                    ),
                                    child: Row(
                                      children: [
                                        const Icon(Icons.local_shipping, color: Color(0xfffbbf24), size: 16),
                                        const SizedBox(width: 8),
                                        const Expanded(
                                          child: Text(
                                            'Dispatched to factory • Awaiting weighbridge',
                                            style: TextStyle(color: Color(0xfffde68a), fontSize: 11),
                                          ),
                                        ),
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                          decoration: BoxDecoration(
                                            color: isSyncedMssql ? const Color(0xff0369a1) : const Color(0xff334155),
                                            borderRadius: BorderRadius.circular(4),
                                          ),
                                          child: Text(
                                            isSyncedMssql ? '🏢 Synced' : '🏢 Syncing',
                                            style: TextStyle(
                                              color: isSyncedMssql ? const Color(0xffbae6fd) : Colors.white60,
                                              fontSize: 9,
                                              fontWeight: FontWeight.bold,
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                ],
                                const SizedBox(height: 8),
                                // Vehicle, Plot & Farmer info
                                Row(
                                  children: [
                                    const Icon(Icons.directions_car, color: Color(0xff94a3b8), size: 14),
                                    const SizedBox(width: 4),
                                    Text(vehicle, style: const TextStyle(color: Colors.white70, fontSize: 11, fontWeight: FontWeight.bold)),
                                    const SizedBox(width: 10),
                                    const Icon(Icons.terrain, color: Color(0xff94a3b8), size: 14),
                                    const SizedBox(width: 4),
                                    Text(plotCode, style: const TextStyle(color: Colors.white70, fontSize: 11)),
                                    if (farmer.isNotEmpty) ...[
                                      const SizedBox(width: 10),
                                      Expanded(
                                        child: Text(
                                          '👨🌾 $farmer',
                                          style: const TextStyle(color: Color(0xff94a3b8), fontSize: 11),
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                    ],
                                  ],
                                ),
                                if (weighedDate.isNotEmpty || createTime.isNotEmpty) ...[
                                  const SizedBox(height: 4),
                                  Text(
                                    isCompleted ? '🕒 Weighed: $weighedDate' : '🕒 Dispatched: $createTime',
                                    style: const TextStyle(color: Colors.white38, fontSize: 10),
                                  ),
                                ],
                              ],
                            ),
                          ),
                        );
                      },
                    ),
        ),
      ],
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
