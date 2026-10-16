<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/_common.php';
require_admin();

$pdo = db();

// Auto-ensure required tables and columns exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `geofence_groups` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `category` VARCHAR(50) NOT NULL DEFAULT 'Plantation',
      `name` VARCHAR(120) NOT NULL,
      `code` VARCHAR(50) NULL,
      `color` VARCHAR(20) NOT NULL DEFAULT '#10b981',
      `description` VARCHAR(255) NULL,
      `active` TINYINT(1) NOT NULL DEFAULT 1,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY `uniq_cat_name` (`category`, `name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    @$pdo->exec("ALTER TABLE `geofences` ADD COLUMN `category` VARCHAR(50) NOT NULL DEFAULT 'Plantation' AFTER `id`");
    @$pdo->exec("ALTER TABLE `geofences` ADD COLUMN `group_name` VARCHAR(120) NULL AFTER `category`");
    @$pdo->exec("ALTER TABLE `geofences` ADD COLUMN `block_name` VARCHAR(100) NULL AFTER `group_name`");
} catch (Throwable $e) {}

// Handle AJAX: Fetch geofence coordinates for a specific group or all active
if (isset($_GET['ajax']) && $_GET['ajax'] === 'group_coords') {
    header('Content-Type: application/json; charset=utf-8');
    $groupName = trim($_GET['group'] ?? '');
    $cat = trim($_GET['cat'] ?? '');
    
    $where = ["active = 1"];
    $params = [];
    if ($groupName !== '') {
        $where[] = "group_name = ?";
        $params[] = $groupName;
    }
    if ($cat !== '' && $cat !== 'All') {
        $where[] = "category = ?";
        $params[] = $cat;
    }
    
    $sql = "SELECT id, category, group_name, block_name, name, code, type, coordinates, color, description 
            FROM geofences WHERE " . implode(' AND ', $where) . " ORDER BY name ASC";
    $rows = q($sql, $params)->fetchAll();
    
    // Decode coordinates for JSON output
    foreach ($rows as &$r) {
        $r['coords'] = json_decode($r['coordinates'], true);
        unset($r['coordinates']);
    }
    echo json_encode($rows);
    exit;
}

// Handle AJAX: Fetch single geofence details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'single_gf') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $r = q("SELECT id, category, group_name, block_name, name, code, type, coordinates, color, description FROM geofences WHERE id=?", [$id])->fetch();
    if ($r) {
        $r['coords'] = json_decode($r['coordinates'], true);
        unset($r['coordinates']);
        echo json_encode($r);
    } else {
        echo json_encode(null);
    }
    exit;
}

// Handle POST: Create or Update Group / Zone
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_group') {
    check_csrf();
    $cat = trim($_POST['category'] ?? 'Plantation');
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $color = trim($_POST['color'] ?? '#10b981');
    $desc = trim($_POST['description'] ?? '');
    $groupId = (int)($_POST['group_id'] ?? 0);

    if ($name) {
        if ($groupId > 0) {
            // Update existing group
            $oldGroup = q("SELECT name FROM geofence_groups WHERE id=?", [$groupId])->fetchColumn();
            q("UPDATE geofence_groups SET category=?, name=?, code=?, color=?, description=? WHERE id=?",
              [$cat, $name, $code, $color, $desc, $groupId]);
            if ($oldGroup && $oldGroup !== $name) {
                q("UPDATE geofences SET group_name=?, category=?, color=? WHERE group_name=?", [$name, $cat, $color, $oldGroup]);
            } else {
                q("UPDATE geofences SET color=?, category=? WHERE group_name=?", [$color, $cat, $name]);
            }
            flash("Zone '$name' updated successfully!");
        } else {
            // Insert new group
            q("INSERT INTO geofence_groups (category, name, code, color, description, active) VALUES (?, ?, ?, ?, ?, 1)
               ON DUPLICATE KEY UPDATE code=VALUES(code), color=VALUES(color), description=VALUES(description)",
              [$cat, $name, $code, $color, $desc]);
            flash("Zone / Group '$name' created under category '$cat'!");
        }
    }
    header('Location: geofences.php?tab=groups');
    exit;
}

// Handle POST: Delete Group
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_group') {
    check_csrf();
    $groupId = (int)($_POST['group_id'] ?? 0);
    $gName = q("SELECT name FROM geofence_groups WHERE id=?", [$groupId])->fetchColumn();
    if ($gName) {
        q("DELETE FROM geofence_groups WHERE id=?", [$groupId]);
        if (!empty($_POST['delete_sectors'])) {
            q("DELETE FROM geofences WHERE group_name=?", [$gName]);
            flash("Deleted zone '$gName' and all its geofence sectors.");
        } else {
            q("UPDATE geofences SET group_name=NULL WHERE group_name=?", [$gName]);
            flash("Deleted zone '$gName'. Geofences unassigned.");
        }
    }
    header('Location: geofences.php?tab=groups');
    exit;
}

// Handle POST: Create Single Geofence
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_geofence') {
    check_csrf();
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? $name);
    $cat = trim($_POST['category'] ?? 'Plantation');
    $groupName = trim($_POST['group_name'] ?? '');
    $blockName = trim($_POST['block_name'] ?? '');
    $color = trim($_POST['color'] ?? '#10b981');
    $desc = trim($_POST['description'] ?? '');
    $coords_raw = trim($_POST['coordinates'] ?? '[]');

    if ($name && $coords_raw) {
        q("INSERT INTO geofences (category, group_name, block_name, name, code, type, coordinates, color, description, active) 
           VALUES (?, ?, ?, ?, ?, 'polygon', ?, ?, ?, 1)",
          [$cat, $groupName ?: null, $blockName ?: null, $name, $code, $coords_raw, $color, $desc]);
        flash('Geofence sector "' . $name . '" created successfully!');
    }
    header('Location: geofences.php');
    exit;
}

// Helper: Parse KML string into geofences
function parse_kml_to_geofences(string $kmlContent, string $fallbackName = 'Sector'): array {
    $zones = [];
    preg_match_all('/<Placemark\b[^>]*>(.*?)<\/Placemark>/is', $kmlContent, $placemarkMatches);

    if (!empty($placemarkMatches[1])) {
        foreach ($placemarkMatches[1] as $idx => $pm) {
            $name = '';
            if (preg_match('/<name\b[^>]*>(.*?)<\/name>/is', $pm, $nm)) {
                $name = trim(strip_tags($nm[1]));
            }
            if (!$name) $name = $fallbackName . ' ' . ($idx + 1);

            $desc = '';
            if (preg_match('/<description\b[^>]*>(.*?)<\/description>/is', $pm, $dm)) {
                $desc = trim(strip_tags($dm[1]));
            }

            if (preg_match_all('/<coordinates\b[^>]*>(.*?)<\/coordinates>/is', $pm, $coordMatches)) {
                foreach ($coordMatches[1] as $rawCoordStr) {
                    $tokens = preg_split('/[\s\r\n]+/', trim($rawCoordStr));
                    $points = [];
                    foreach ($tokens as $tk) {
                        $tk = trim($tk);
                        if (!$tk) continue;
                        $c = explode(',', $tk);
                        if (count($c) >= 2) {
                            $lng = (float)$c[0];
                            $lat = (float)$c[1];
                            if ($lat != 0 && $lng != 0 && abs($lat) <= 90 && abs($lng) <= 180) {
                                $points[] = [round($lat, 7), round($lng, 7)];
                            }
                        }
                    }
                    if (count($points) >= 3) {
                        $zones[] = [
                            'name' => $name,
                            'code' => $name,
                            'type' => 'polygon',
                            'coordinates' => json_encode($points),
                            'description' => $desc,
                        ];
                        break;
                    }
                }
            }
        }
    }
    return $zones;
}

// Handle bulk KML / KMZ import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_kml') {
    check_csrf();
    $cat = trim($_POST['category'] ?? 'Plantation');
    $targetGroup = trim($_POST['target_group'] ?? '');
    $customColor = trim($_POST['color'] ?? '');
    $files = $_FILES['kml_files'] ?? null;
    $importedCount = 0;
    $importedNames = [];

    // Ensure group exists if specified
    if ($targetGroup) {
        q("INSERT IGNORE INTO geofence_groups (category, name, code, color, description) 
           VALUES (?, ?, ?, ?, 'Imported Zone')",
          [$cat, $targetGroup, strtoupper(substr($targetGroup, 0, 3)), $customColor ?: '#10b981']);
    }

    if ($files && is_array($files['name'])) {
        for ($fIdx = 0; $fIdx < count($files['name']); $fIdx++) {
            if ($files['error'][$fIdx] !== UPLOAD_ERR_OK) continue;
            $tmpPath = $files['tmp_name'][$fIdx];
            $origName = $files['name'][$fIdx];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $blockName = pathinfo($origName, PATHINFO_FILENAME);

            $fileZones = [];
            if ($ext === 'kmz' && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($tmpPath) === true) {
                    for ($z = 0; $z < $zip->numFiles; $z++) {
                        $entryName = $zip->getNameIndex($z);
                        if (preg_match('/\.kml$/i', $entryName)) {
                            $kmlData = $zip->getFromIndex($z);
                            $fileZones = array_merge($fileZones, parse_kml_to_geofences($kmlData, $blockName));
                        }
                    }
                    $zip->close();
                }
            } elseif ($ext === 'kml' || $ext === 'xml') {
                $kmlData = file_get_contents($tmpPath);
                $fileZones = parse_kml_to_geofences($kmlData, $blockName);
            }

            // Determine zone name
            $assignedGroup = $targetGroup ?: $blockName;
            $color = $customColor ?: '#10b981';

            foreach ($fileZones as $z) {
                if (!empty($_POST['overwrite'])) {
                    q("DELETE FROM geofences WHERE name=? AND group_name=?", [$z['name'], $assignedGroup]);
                }
                q("INSERT INTO geofences (category, group_name, block_name, name, code, type, coordinates, color, description, active) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                   ON DUPLICATE KEY UPDATE coordinates=VALUES(coordinates), group_name=VALUES(group_name), block_name=VALUES(block_name), color=VALUES(color)",
                  [$cat, $assignedGroup, $blockName, $z['name'], $z['code'], $z['type'], $z['coordinates'], $color, $z['description'] ?: ($assignedGroup . ' | ' . $blockName)]);
                $importedCount++;
                if (count($importedNames) < 5) $importedNames[] = $z['name'];
            }
        }
    }

    if ($importedCount > 0) {
        $extra = count($importedNames) ? " (" . implode(', ', $importedNames) . (count($importedNames) < $importedCount ? '...' : '') . ")" : "";
        flash("🎉 Successfully imported $importedCount Geofence sector(s)$extra into '$targetGroup'!");
    } else {
        flash("⚠️ No valid polygon zones found in the uploaded KML file(s).");
    }
    header('Location: geofences.php');
    exit;
}

// Handle delete single geofence
if (isset($_GET['delete'])) {
    check_csrf();
    q("DELETE FROM geofences WHERE id=?", [(int)$_GET['delete']]);
    flash('Geofence deleted.');
    header('Location: geofences.php');
    exit;
}

// Fetch all Groups with count of geofences
try {
    $groupsData = q("
        SELECT gg.*, COUNT(g.id) AS sector_count 
        FROM geofence_groups gg
        LEFT JOIN geofences g ON g.group_name = gg.name AND g.active = 1
        WHERE gg.active = 1
        GROUP BY gg.id
        ORDER BY 
          CASE gg.category 
            WHEN 'Plantation' THEN 1 
            WHEN 'Outgrower' THEN 2 
            ELSE 3 
          END, 
          gg.name ASC
    ")->fetchAll();
} catch (Throwable $e) {
    $groupsData = [];
}

// If groups table was empty, fallback or group from geofences
if (empty($groupsData)) {
    $fallbackGroups = q("SELECT DISTINCT group_name, category, color, COUNT(*) as sector_count FROM geofences WHERE group_name IS NOT NULL AND active=1 GROUP BY group_name, category, color ORDER BY group_name ASC")->fetchAll();
    foreach ($fallbackGroups as $fg) {
        $groupsData[] = [
            'id' => 0,
            'category' => $fg['category'] ?: 'Plantation',
            'name' => $fg['group_name'],
            'code' => strtoupper(substr($fg['group_name'], 0, 3)),
            'color' => $fg['color'] ?: '#10b981',
            'description' => '',
            'sector_count' => (int)$fg['sector_count']
        ];
    }
}

// Fetch initial geofences list (limit 500 for fast page load, search filters dynamically)
$geofences = q("SELECT id, category, group_name, block_name, name, code, type, color, description FROM geofences WHERE active=1 ORDER BY name ASC LIMIT 500")->fetchAll();
$totalGeofences = (int)q("SELECT COUNT(*) FROM geofences WHERE active=1")->fetchColumn();

// Fetch active field officers/vehicles to show live on map
$liveUnits = q("
    SELECT u.id, u.name, u.role, u.vehicle_plate, u.sos_active, u.duty_status,
           l.lat, l.lng, l.speed, l.accuracy, l.created_at AS last_time
    FROM users u
    JOIN locations l ON l.id = (SELECT id FROM locations WHERE user_id = u.id ORDER BY id DESC LIMIT 1)
    WHERE u.active = 1
")->fetchAll();

$activeTab = $_GET['tab'] ?? 'geofences';

layout_start('Geofences · Galoya Telematics', 'geofences.php', true);
?>
<style>
/* Wialon-Style Geofences Manager Layout */
.w-gf-layout {
  display: flex;
  height: calc(100vh - 56px);
  overflow: hidden;
  position: relative;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}
.w-gf-sidebar {
  width: 380px;
  min-width: 360px;
  max-width: 440px;
  background: #ffffff;
  border-right: 1px solid #cbd5e1;
  display: flex;
  flex-direction: column;
  z-index: 100;
  box-shadow: 2px 0 8px rgba(0,0,0,0.06);
}
.w-gf-nav-tabs {
  display: flex;
  background: #f1f5f9;
  border-bottom: 2px solid #e2e8f0;
}
.w-gf-tab-btn {
  flex: 1;
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 700;
  color: #64748b;
  border: none;
  background: transparent;
  cursor: pointer;
  border-bottom: 3px solid transparent;
  transition: all 0.2s;
}
.w-gf-tab-btn:hover {
  color: #1e293b;
  background: #e2e8f0;
}
.w-gf-tab-btn.active {
  color: #0284c7;
  background: #ffffff;
  border-bottom-color: #0284c7;
}

/* Toolbar */
.w-gf-toolbar {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 10px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  flex-wrap: wrap;
}
.btn-wialon-blue {
  background: #0284c7;
  color: #ffffff;
  font-size: 12px;
  font-weight: 700;
  border: none;
  padding: 5px 12px;
  border-radius: 4px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.1);
}
.btn-wialon-blue:hover { background: #0369a1; }
.btn-wialon-light {
  background: #f8fafc;
  border: 1px solid #cbd5e1;
  color: #334155;
  font-size: 11.5px;
  font-weight: 600;
  padding: 5px 9px;
  border-radius: 4px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.btn-wialon-light:hover { background: #e2e8f0; }

.w-filter-select {
  padding: 5px 8px;
  font-size: 12px;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #fff;
  color: #1e293b;
  outline: none;
  font-weight: 600;
}
.w-search-box {
  flex: 1;
  min-width: 90px;
  position: relative;
  display: flex;
  align-items: center;
}
.w-search-box input {
  width: 100%;
  padding: 5px 24px 5px 8px;
  font-size: 11.5px;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  outline: none;
}
.w-search-box input:focus { border-color: #0284c7; }
.w-search-clear {
  position: absolute;
  right: 6px;
  color: #ef4444;
  font-size: 11px;
  font-weight: bold;
  cursor: pointer;
}

/* List Content */
.w-gf-list-area {
  flex: 1;
  overflow-y: auto;
  background: #ffffff;
}

/* Tree & Groups */
.w-cat-header {
  background: #f1f5f9;
  padding: 6px 12px;
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  color: #475569;
  letter-spacing: 0.5px;
  border-top: 1px solid #e2e8f0;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.w-group-item {
  border-bottom: 1px solid #f1f5f9;
  transition: background 0.15s;
}
.w-group-item:hover { background: #f8fafc; }
.w-group-main {
  display: flex;
  align-items: center;
  padding: 7px 10px;
  gap: 8px;
  cursor: pointer;
  user-select: none;
}
.w-expand-btn {
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  color: #64748b;
  border-radius: 3px;
  transition: transform 0.2s;
}
.w-expand-btn.open { transform: rotate(90deg); color: #0284c7; }
.w-color-badge {
  width: 13px;
  height: 13px;
  border-radius: 3px;
  flex-shrink: 0;
  border: 1px solid rgba(0,0,0,0.15);
  box-shadow: inset 0 0 2px rgba(0,0,0,0.2);
}
.w-group-title {
  flex: 1;
  font-size: 12.5px;
  font-weight: 600;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.w-group-count {
  font-size: 11px;
  color: #64748b;
  font-weight: 500;
}
.w-actions-cell {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-left: auto;
}
.w-act-btn {
  background: transparent;
  border: none;
  color: #94a3b8;
  padding: 2px 4px;
  cursor: pointer;
  border-radius: 3px;
  font-size: 12px;
}
.w-act-btn:hover { color: #0284c7; background: #e0f2fe; }
.w-act-btn.del:hover { color: #ef4444; background: #fee2e2; }

/* Sublist inside an expanded group */
.w-sublist {
  display: none;
  background: #f8fafc;
  border-top: 1px dashed #cbd5e1;
  max-height: 320px;
  overflow-y: auto;
  padding-left: 28px;
}
.w-sublist.open { display: block; }
.w-sub-item {
  display: flex;
  align-items: center;
  padding: 5px 10px 5px 0;
  border-bottom: 1px solid #f1f5f9;
  font-size: 11.5px;
  gap: 8px;
}
.w-sub-item:hover { background: #f1f5f9; }

/* Map Canvas */
.w-gf-map-wrap {
  flex: 1;
  position: relative;
  height: 100%;
}
#map {
  width: 100%;
  height: 100%;
}

/* Wialon High-Contrast Map Labels */
.sector-label {
  background: transparent !important;
  border: none !important;
  box-shadow: none !important;
  font-size: 10.5px !important;
  font-weight: 800 !important;
  color: #d97706 !important; /* Orange amber */
  text-shadow: 
    -1px -1px 0 #ffffff,
     1px -1px 0 #ffffff,
    -1px  1px 0 #ffffff,
     1px  1px 0 #ffffff,
     0px  0px 4px #ffffff !important;
  white-space: nowrap;
  pointer-events: none;
}

/* HUD Bar on Map bottom */
.w-gf-hud {
  position: absolute;
  bottom: 12px;
  right: 14px;
  background: rgba(15, 23, 42, 0.85);
  backdrop-filter: blur(4px);
  color: #f8fafc;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 11.5px;
  font-family: monospace;
  font-weight: 600;
  z-index: 1000;
  box-shadow: 0 4px 12px rgba(0,0,0,0.3);
  display: flex;
  align-items: center;
  gap: 12px;
}

/* Modal Styling */
.w-modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.65);
  backdrop-filter: blur(3px);
  z-index: 2500;
  align-items: center;
  justify-content: center;
}
.w-modal-card {
  background: #ffffff;
  border-radius: 10px;
  width: 440px;
  max-width: 95vw;
  box-shadow: 0 20px 40px rgba(0,0,0,0.3);
  overflow: hidden;
  border: 1px solid #e2e8f0;
}
.w-modal-header {
  background: #f8fafc;
  padding: 14px 18px;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.w-modal-header h3 { margin: 0; font-size: 15px; color: #0f172a; font-weight: 700; }
.w-modal-body { padding: 18px; }
.w-modal-footer {
  background: #f8fafc;
  padding: 12px 18px;
  border-top: 1px solid #e2e8f0;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.w-form-group { margin-bottom: 14px; }
.w-form-group label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #334155;
  margin-bottom: 5px;
}
.w-form-control {
  width: 100%;
  padding: 7px 10px;
  font-size: 12.5px;
  border: 1px solid #cbd5e1;
  border-radius: 5px;
  outline: none;
  box-sizing: border-box;
}
.w-form-control:focus { border-color: #0284c7; box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15); }
</style>

<div class="w-gf-layout">
  <!-- Left Sidebar (Wialon Matching) -->
  <aside class="w-gf-sidebar">
    <!-- Sub-tabs: Geofences | Groups -->
    <div class="w-gf-nav-tabs">
      <button type="button" class="w-gf-tab-btn <?= $activeTab === 'geofences' ? 'active' : '' ?>" id="tab-btn-geofences" onclick="switchMainTab('geofences')">
        📍 Geofences (<span id="count-gf"><?= $totalGeofences ?></span>)
      </button>
      <button type="button" class="w-gf-tab-btn <?= $activeTab === 'groups' ? 'active' : '' ?>" id="tab-btn-groups" onclick="switchMainTab('groups')">
        📁 Groups / Zones (<span id="count-gr"><?= count($groupsData) ?></span>)
      </button>
    </div>

    <!-- Top Action Toolbar -->
    <div class="w-gf-toolbar">
      <button type="button" class="btn-wialon-blue" id="btn-toolbar-new" onclick="handleToolbarNew()">
        + New
      </button>
      <button type="button" class="btn-wialon-light" onclick="openKmlModal()" title="Bulk Upload Google Earth KML / KMZ files">
        📥 Import KML
      </button>
      <button type="button" class="btn-wialon-light" onclick="toggleSortOrder()" title="Sort A-Z">
        A↓
      </button>
      
      <!-- Category Filter -->
      <select id="sel-category-filter" class="w-filter-select" onchange="filterListAndMap()">
        <option value="All">All Categories</option>
        <option value="Plantation">🌴 Plantation</option>
        <option value="Outgrower">🚜 Outgrower</option>
        <option value="Other">🏢 Other</option>
      </select>

      <!-- Search Input -->
      <div class="w-search-box">
        <input type="text" id="gf-search-input" placeholder="Search..." oninput="handleSearch(this.value)">
        <span class="w-search-clear" onclick="clearSearch()">✕</span>
      </div>
    </div>

    <!-- Mode banner for manual polygon drawing -->
    <div id="draw-banner" style="display:none;background:#fef3c7;border-bottom:1px solid #fde68a;padding:8px 12px;font-size:11.5px;color:#92400e">
      <strong>Drawing Mode:</strong> Click on map to add boundary points.
      <div style="margin-top:6px;display:flex;gap:6px">
        <button type="button" class="btn-wialon-blue" style="padding:2px 8px;font-size:11px" onclick="finishDrawing()">Finish Zone</button>
        <button type="button" class="btn-wialon-light" style="padding:2px 8px;font-size:11px" onclick="cancelDrawing()">Cancel</button>
      </div>
    </div>

    <!-- Fast Sync Banner if geofences empty or need seed -->
    <?php if ($totalGeofences < 100): ?>
      <div style="background:#e0f2fe;border-bottom:1px solid #bae6fd;padding:8px 12px;font-size:11.5px;color:#0369a1;display:flex;align-items:center;justify-content:space-between">
        <span>⚡ <strong>5,034 Agriculture Sectors</strong> ready to sync!</span>
        <a href="sync_agriculture_kml.php?key=galoya2026" class="btn-wialon-blue" style="font-size:10px;padding:3px 8px">Fast Sync</a>
      </div>
    <?php endif; ?>

    <!-- TAB 1: GEOFENCES LIST -->
    <div id="panel-geofences" class="w-gf-list-area" style="<?= $activeTab === 'groups' ? 'display:none' : '' ?>">
      <div style="padding:6px 10px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#64748b">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
          <input type="checkbox" id="chk-select-all-gf" onchange="toggleSelectAllGf(this.checked)">
          <span>Select All Visible</span>
        </label>
        <span id="gf-visible-count">Showing <?= count($geofences) ?> of <?= $totalGeofences ?></span>
      </div>

      <div id="gf-list-container">
        <?php foreach ($geofences as $g): ?>
          <div class="w-group-item gf-row-item" 
               data-id="<?= $g['id'] ?>" 
               data-name="<?= strtolower(h($g['name'])) ?>"
               data-group="<?= h($g['group_name'] ?? '') ?>"
               data-category="<?= h($g['category'] ?? 'Plantation') ?>"
               data-color="<?= h($g['color'] ?? '#10b981') ?>">
            <div class="w-group-main">
              <input type="checkbox" class="chk-gf-single" onchange="toggleSingleGf(<?= $g['id'] ?>, this.checked)">
              <div class="w-color-badge" style="background:<?= h($g['color']) ?>" title="Group: <?= h($g['group_name']) ?>"></div>
              <div class="w-group-title" onclick="zoomToSingleGf(<?= $g['id'] ?>)">
                <strong><?= h($g['name']) ?></strong>
                <?php if ($g['group_name']): ?>
                  <span style="font-size:10.5px;color:#64748b">(<?= h($g['group_name']) ?>)</span>
                <?php endif; ?>
              </div>
              <div class="w-actions-cell">
                <span title="Units inside" style="font-size:11px;color:#64748b;display:inline-flex;align-items:center;gap:2px">🚍 0</span>
                <button type="button" class="w-act-btn" onclick="zoomToSingleGf(<?= $g['id'] ?>)" title="View on map">🔍</button>
                <a href="geofences.php?delete=<?= $g['id'] ?>&csrf=<?= csrf() ?>" onclick="return confirm('Delete geofence <?= h($g['name']) ?>?')" class="w-act-btn del" title="Delete">✕</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- TAB 2: GROUPS / ZONES TREE (Wialon Screenshot Matching) -->
    <div id="panel-groups" class="w-gf-list-area" style="<?= $activeTab === 'geofences' ? 'display:none' : '' ?>">
      <div style="padding:6px 10px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#64748b">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
          <input type="checkbox" id="chk-select-all-groups" onchange="toggleAllGroups(this.checked)">
          <span>Show All Zones on Map</span>
        </label>
        <span><?= count($groupsData) ?> Zones</span>
      </div>

      <div id="groups-tree-container">
        <?php
        $currentCat = null;
        foreach ($groupsData as $gr):
          if ($gr['category'] !== $currentCat):
            $currentCat = $gr['category'];
            $catIcon = $currentCat === 'Plantation' ? '🌴' : ($currentCat === 'Outgrower' ? '🚜' : '🏢');
        ?>
          <div class="w-cat-header cat-sec-<?= strtolower($currentCat) ?>">
            <span><?= $catIcon ?> <?= h($currentCat) ?> Division</span>
          </div>
        <?php endif; ?>

          <div class="w-group-item gr-row-item" 
               data-group-id="<?= $gr['id'] ?>" 
               data-group-name="<?= h($gr['name']) ?>"
               data-category="<?= h($gr['category']) ?>"
               data-color="<?= h($gr['color']) ?>">
            <div class="w-group-main">
              <span class="w-expand-btn" id="expand-icon-<?= $gr['id'] ?>" onclick="toggleGroupSublist(<?= $gr['id'] ?>, '<?= h(addslashes($gr['name'])) ?>')" title="Expand sectors">▶</span>
              <input type="checkbox" class="chk-group-vis" id="chk-grp-<?= $gr['id'] ?>" onchange="toggleGroupVisibility('<?= h(addslashes($gr['name'])) ?>', '<?= h($gr['color']) ?>', this.checked)">
              <div class="w-color-badge" style="background:<?= h($gr['color']) ?>" title="Click to change color" onclick="openEditGroupModal(<?= htmlspecialchars(json_encode($gr)) ?>)"></div>
              
              <div class="w-group-title" onclick="toggleGroupSublist(<?= $gr['id'] ?>, '<?= h(addslashes($gr['name'])) ?>')">
                <span><?= h($gr['name']) ?></span>
                <span class="w-group-count">(<?= $gr['sector_count'] ?>)</span>
              </div>

              <div class="w-actions-cell">
                <button type="button" class="w-act-btn" onclick="openKmlModalForGroup('<?= h(addslashes($gr['name'])) ?>', '<?= h($gr['category']) ?>')" title="Import KML into this zone">📥</button>
                <button type="button" class="w-act-btn" onclick="openEditGroupModal(<?= htmlspecialchars(json_encode($gr)) ?>)" title="Zone Properties & Color">⚙</button>
                <button type="button" class="w-act-btn del" onclick="confirmDeleteGroup(<?= $gr['id'] ?>, '<?= h(addslashes($gr['name'])) ?>')" title="Delete Zone">✕</button>
              </div>
            </div>

            <!-- Inline expanded sectors sublist -->
            <div class="w-sublist" id="sublist-<?= $gr['id'] ?>">
              <div style="padding:6px;font-size:11px;color:#94a3b8;font-style:italic">Loading sectors...</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Bottom Footer Stats -->
    <div style="padding:8px 12px;background:#f8fafc;border-top:1px solid #cbd5e1;font-size:11px;color:#64748b;display:flex;justify-content:space-between;align-items:center">
      <span>Total Sectors: <strong id="lbl-total-gf" style="color:#1e293b"><?= $totalGeofences ?></strong></span>
      <span>Total Zones: <strong id="lbl-total-gr" style="color:#1e293b"><?= count($groupsData) ?></strong></span>
    </div>
  </aside>

  <!-- Right Map View -->
  <div class="w-gf-map-wrap">
    <div id="map"></div>
    <!-- HUD Coordinate display -->
    <div class="w-gf-hud" id="hud-bar">
      <span id="hud-coords">N 07° 12.352' &nbsp; E 081° 39.547'</span>
      <span>•</span>
      <span id="hud-rendered-count">Active Zones on Map: 0</span>
    </div>
  </div>
</div>

<!-- MODAL: ADD / EDIT ZONE (GROUP) -->
<div id="modal-group" class="w-modal-overlay">
  <div class="w-modal-card">
    <div class="w-modal-header">
      <h3 id="modal-group-title">➕ Add New Zone / Group</h3>
      <span style="cursor:pointer;font-size:16px;color:#94a3b8" onclick="closeModal('modal-group')">✕</span>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_group">
      <input type="hidden" name="group_id" id="grp-input-id" value="0">
      
      <div class="w-modal-body">
        <div class="w-form-group">
          <label>Category Division</label>
          <select name="category" id="grp-input-cat" class="w-form-control" required>
            <option value="Plantation">🌴 Main Plantation (Estate Zones)</option>
            <option value="Outgrower">🚜 Outgrower Farmers (Private Plots)</option>
            <option value="Other">🏢 Other (Factory, Mill, Buffer, Conservation)</option>
          </select>
        </div>

        <div class="w-form-group">
          <label>Zone / Group Name</label>
          <input type="text" name="name" id="grp-input-name" class="w-form-control" placeholder="e.g. Deegawapiya Zone or Ampara Outgrower Zone" required>
        </div>

        <div style="display:flex;gap:12px">
          <div class="w-form-group" style="flex:1">
            <label>Zone Code</label>
            <input type="text" name="code" id="grp-input-code" class="w-form-control" placeholder="e.g. DGZ, GMZ">
          </div>
          <div class="w-form-group" style="width:120px">
            <label>Map Color</label>
            <input type="color" name="color" id="grp-input-color" class="w-form-control" value="#10b981" style="height:36px;padding:2px">
          </div>
        </div>

        <div class="w-form-group">
          <label>Description / Notes</label>
          <textarea name="description" id="grp-input-desc" class="w-form-control" rows="2" placeholder="Zone office, field officers, or acreage details..."></textarea>
        </div>
      </div>

      <div class="w-modal-footer">
        <button type="button" class="btn-wialon-light" onclick="closeModal('modal-group')">Cancel</button>
        <button type="submit" class="btn-wialon-blue" id="modal-group-submit">Save Zone</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: BULK KML / KMZ IMPORT -->
<div id="modal-kml" class="w-modal-overlay">
  <div class="w-modal-card" style="width:480px">
    <div class="w-modal-header">
      <h3>📥 Bulk Import Google Earth KML / KMZ</h3>
      <span style="cursor:pointer;font-size:16px;color:#94a3b8" onclick="closeModal('modal-kml')">✕</span>
    </div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import_kml">

      <div class="w-modal-body">
        <div class="w-form-group">
          <label>Select Category Division</label>
          <select name="category" id="kml-target-cat" class="w-form-control" required>
            <option value="Plantation">🌴 Main Plantation</option>
            <option value="Outgrower">🚜 Outgrower</option>
            <option value="Other">🏢 Other</option>
          </select>
        </div>

        <div class="w-form-group">
          <label>Target Zone / Group</label>
          <select name="target_group" id="kml-target-group" class="w-form-control">
            <option value="">-- Auto-Assign by KML Block Filename --</option>
            <?php foreach ($groupsData as $g): ?>
              <option value="<?= h($g['name']) ?>" data-color="<?= h($g['color']) ?>"><?= h($g['name']) ?> (<?= h($g['category']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="w-form-group">
          <label>Select KML or KMZ Files (Multi-Select Allowed)</label>
          <div style="border:2px dashed #cbd5e1;border-radius:6px;padding:20px;text-align:center;background:#f8fafc;cursor:pointer" onclick="document.getElementById('kml-file-input').click()">
            <div style="font-size:28px">📂</div>
            <div style="font-size:12.5px;font-weight:600;color:#1e293b;margin-top:6px" id="kml-file-label">Click to choose KML/KMZ files or Drag & Drop here</div>
            <div style="font-size:11px;color:#64748b;margin-top:4px">Supports multiple .kml, .kmz files with polygons and placemarks</div>
            <input type="file" id="kml-file-input" name="kml_files[]" multiple accept=".kml,.kmz,.xml" style="display:none" onchange="updateKmlFilesLabel(this)">
          </div>
        </div>

        <div style="display:flex;gap:12px;align-items:center">
          <div class="w-form-group" style="flex:1">
            <label>Override Zone Color (Optional)</label>
            <input type="color" name="color" id="kml-color-input" class="w-form-control" value="#10b981" style="height:34px;padding:2px">
          </div>
          <div class="w-form-group" style="flex:1;padding-top:16px">
            <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;cursor:pointer">
              <input type="checkbox" name="overwrite" value="1" checked>
              <span>Update existing sectors</span>
            </label>
          </div>
        </div>
      </div>

      <div class="w-modal-footer" style="justify-content:space-between">
        <a href="sync_agriculture_kml.php?key=galoya2026" class="btn-wialon-light" style="color:#0284c7">⚡ Fast Sync Agriculture Folder</a>
        <div style="display:flex;gap:8px">
          <button type="button" class="btn-wialon-light" onclick="closeModal('modal-kml')">Cancel</button>
          <button type="submit" class="btn-wialon-blue">Upload & Import</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: DELETE GROUP CONFIRM -->
<form id="form-delete-group" method="post" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete_group">
  <input type="hidden" name="group_id" id="del-group-id">
  <input type="hidden" name="delete_sectors" id="del-group-sectors" value="1">
</form>

<script>
// Global State
let currentTab = '<?= $activeTab ?>';
let map, baseLayers, activeZoneLayers = {}, singleGfLayers = {};
let drawingMode = false, drawnPoints = [], drawPolyline = null, drawMarkers = [];
let liveUnits = <?= json_encode($liveUnits) ?>;

document.addEventListener('DOMContentLoaded', function() {
  initMap();
  renderLiveUnits();
  
  // By default, if on groups tab, check the first zone or let user toggle
  const firstZoneChk = document.querySelector('.chk-group-vis');
  if (firstZoneChk) {
    firstZoneChk.checked = true;
    firstZoneChk.dispatchEvent(new Event('change'));
  }
});

// Map Initialization
function initMap() {
  const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap contributors'
  });

  const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 19,
    attribution: '© Esri Satellite'
  });

  const cartoLight = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    attribution: '© CartoDB'
  });

  map = L.map('map', {
    center: [7.2050, 81.6580],
    zoom: 13,
    layers: [osm]
  });

  baseLayers = {
    "OpenStreetMap": osm,
    "Esri Satellite": satellite,
    "Clean Light": cartoLight
  };
  L.control.layers(baseLayers, null, { position: 'topright' }).addTo(map);

  // HUD Mouse Movement Coordinates
  map.on('mousemove', function(e) {
    const lat = e.latlng.lat;
    const lng = e.latlng.lng;
    const latStr = (lat >= 0 ? 'N ' : 'S ') + Math.abs(lat).toFixed(4) + '°';
    const lngStr = (lng >= 0 ? 'E ' : 'W ') + Math.abs(lng).toFixed(4) + '°';
    const hud = document.getElementById('hud-coords');
    if (hud) hud.innerHTML = `${latStr} &nbsp; ${lngStr}`;
  });

  // Map click for drawing
  map.on('click', function(e) {
    if (!drawingMode) return;
    const p = [e.latlng.lat, e.latlng.lng];
    drawnPoints.push(p);

    const m = L.circleMarker(p, { radius: 5, color: '#0284c7', fillColor: '#fff', fillOpacity: 1 }).addTo(map);
    drawMarkers.push(m);

    if (drawnPoints.length > 1) {
      if (!drawPolyline) {
        drawPolyline = L.polyline(drawnPoints, { color: '#0284c7', weight: 2, dashArray: '4, 4' }).addTo(map);
      } else {
        drawPolyline.setLatLngs(drawnPoints);
      }
    }
  });
}

// Render Live Vehicle / Officer Units
function renderLiveUnits() {
  if (!liveUnits || !liveUnits.length) return;
  liveUnits.forEach(u => {
    if (!u.lat || !u.lng) return;
    const isSos = parseInt(u.sos_active) === 1;
    const iconHtml = `
      <div style="background:${isSos ? '#ef4444' : '#0284c7'};color:#fff;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,0.3);border:2px solid #fff;font-size:12px;font-weight:bold">
        ${u.vehicle_plate ? '🚜' : '👤'}
      </div>
    `;
    const icon = L.divIcon({ html: iconHtml, className: '', iconSize: [26, 26], iconAnchor: [13, 13] });
    const marker = L.marker([parseFloat(u.lat), parseFloat(u.lng)], { icon: icon }).addTo(map);
    marker.bindPopup(`
      <div style="font-size:12px;line-height:1.4">
        <strong style="font-size:13px;color:#0f172a">${u.name}</strong><br>
        <span style="color:#64748b">Role: ${u.role || 'Staff'} ${u.vehicle_plate ? '(' + u.vehicle_plate + ')' : ''}</span><br>
        Speed: <strong>${Math.round(u.speed || 0)} km/h</strong><br>
        Last Signal: ${u.last_time || 'Just now'}
      </div>
    `);
  });
}

// Toggle Group / Zone Visibility on Map
function toggleGroupVisibility(groupName, groupColor, isChecked) {
  if (isChecked) {
    if (activeZoneLayers[groupName]) {
      map.addLayer(activeZoneLayers[groupName]);
      updateHudCount();
      return;
    }

    // Fetch coordinates via AJAX
    fetch(`geofences.php?ajax=group_coords&group=${encodeURIComponent(groupName)}`)
      .then(res => res.json())
      .then(sectors => {
        if (!sectors || !sectors.length) return;
        const layerGroup = L.featureGroup();

        sectors.forEach(s => {
          if (!s.coords || s.coords.length < 3) return;
          const poly = L.polygon(s.coords, {
            color: '#ffffff',
            weight: 1.5,
            fillColor: groupColor || s.color || '#10b981',
            fillOpacity: 0.65
          });

          // Permanent centered label matching Wialon Screenshot
          poly.bindTooltip(s.name, {
            permanent: true,
            direction: 'center',
            className: 'sector-label'
          });

          // Popup on click
          poly.bindPopup(`
            <div style="font-size:12.5px;min-width:180px">
              <strong style="color:#0284c7;font-size:14px">${s.name}</strong>
              <div style="color:#64748b;font-size:11px;margin:2px 0 6px">
                Zone: <strong>${s.group_name || 'N/A'}</strong> | Cat: <strong>${s.category}</strong>
              </div>
              <div style="font-size:11px;color:#334155">${s.description || 'Sugar Cane Sector'}</div>
              <div style="margin-top:8px;font-size:11px">🚍 Units Inside: <strong>0</strong></div>
            </div>
          `);

          // Hover highlight
          poly.on('mouseover', function() { this.setStyle({ weight: 3, color: '#f59e0b' }); });
          poly.on('mouseout', function() { this.setStyle({ weight: 1.5, color: '#ffffff' }); });

          layerGroup.addLayer(poly);
        });

        activeZoneLayers[groupName] = layerGroup;
        map.addLayer(layerGroup);
        map.fitBounds(layerGroup.getBounds(), { padding: [30, 30], maxZoom: 15 });
        updateHudCount();
      });
  } else {
    if (activeZoneLayers[groupName]) {
      map.removeLayer(activeZoneLayers[groupName]);
      updateHudCount();
    }
  }
}

// Toggle All Groups
function toggleAllGroups(checked) {
  document.querySelectorAll('.chk-group-vis').forEach(chk => {
    if (chk.checked !== checked) {
      chk.checked = checked;
      chk.dispatchEvent(new Event('change'));
    }
  });
}

// Toggle Inline Sublist of a Group
function toggleGroupSublist(groupId, groupName) {
  const sublist = document.getElementById(`sublist-${groupId}`);
  const icon = document.getElementById(`expand-icon-${groupId}`);
  if (!sublist) return;

  const isOpen = sublist.classList.contains('open');
  if (isOpen) {
    sublist.classList.remove('open');
    if (icon) icon.classList.remove('open');
  } else {
    sublist.classList.add('open');
    if (icon) icon.classList.add('open');

    // Load sectors if not already populated
    if (sublist.getAttribute('data-loaded') !== 'true') {
      fetch(`geofences.php?ajax=group_coords&group=${encodeURIComponent(groupName)}`)
        .then(res => res.json())
        .then(sectors => {
          sublist.setAttribute('data-loaded', 'true');
          if (!sectors.length) {
            sublist.innerHTML = `<div style="padding:6px;font-size:11px;color:#94a3b8">No sectors in this zone yet.</div>`;
            return;
          }
          let html = '';
          sectors.forEach(s => {
            html += `
              <div class="w-sub-item">
                <input type="checkbox" onchange="toggleSingleGf(${s.id}, this.checked)">
                <span style="font-weight:600;color:#1e293b;flex:1;cursor:pointer" onclick="zoomToSingleGf(${s.id})">${s.name}</span>
                <span style="font-size:10px;color:#64748b">🚍 0</span>
              </div>
            `;
          });
          sublist.innerHTML = html;
        });
    }
  }
}

// Single Geofence Toggle
function toggleSingleGf(id, checked) {
  if (checked) {
    if (singleGfLayers[id]) {
      map.addLayer(singleGfLayers[id]);
      return;
    }
    fetch(`geofences.php?ajax=single_gf&id=${id}`)
      .then(res => res.json())
      .then(s => {
        if (!s || !s.coords) return;
        const poly = L.polygon(s.coords, {
          color: '#ffffff',
          weight: 2,
          fillColor: s.color || '#10b981',
          fillOpacity: 0.7
        }).addTo(map);

        poly.bindTooltip(s.name, { permanent: true, direction: 'center', className: 'sector-label' });
        poly.bindPopup(`<strong>${s.name}</strong><br>Zone: ${s.group_name || 'N/A'}`);
        singleGfLayers[id] = poly;
        map.fitBounds(poly.getBounds(), { padding: [40, 40], maxZoom: 16 });
      });
  } else {
    if (singleGfLayers[id]) {
      map.removeLayer(singleGfLayers[id]);
      delete singleGfLayers[id];
    }
  }
}

// Zoom to Single Geofence
function zoomToSingleGf(id) {
  toggleSingleGf(id, true);
  const chk = document.querySelector(`.gf-row-item[data-id="${id}"] .chk-gf-single`);
  if (chk) chk.checked = true;
}

// HUD active zones count
function updateHudCount() {
  let count = Object.keys(activeZoneLayers).filter(k => map.hasLayer(activeZoneLayers[k])).length;
  const hud = document.getElementById('hud-rendered-count');
  if (hud) hud.textContent = `Active Zones on Map: ${count}`;
}

// Switch between Geofences & Groups Tabs
function switchMainTab(tab) {
  currentTab = tab;
  const btnGf = document.getElementById('tab-btn-geofences');
  const btnGr = document.getElementById('tab-btn-groups');
  const panelGf = document.getElementById('panel-geofences');
  const panelGr = document.getElementById('panel-groups');

  if (tab === 'geofences') {
    btnGf.classList.add('active');
    btnGr.classList.remove('active');
    panelGf.style.display = 'block';
    panelGr.style.display = 'none';
  } else {
    btnGr.classList.add('active');
    btnGf.classList.remove('active');
    panelGr.style.display = 'block';
    panelGf.style.display = 'none';
  }
}

// Toolbar "+ New" Handler
function handleToolbarNew() {
  if (currentTab === 'groups') {
    openAddGroupModal();
  } else {
    startDrawingMode();
  }
}

// Add/Edit Group Modal Handlers
function openAddGroupModal() {
  document.getElementById('modal-group-title').textContent = '➕ Add New Zone / Group';
  document.getElementById('grp-input-id').value = '0';
  document.getElementById('grp-input-cat').value = 'Plantation';
  document.getElementById('grp-input-name').value = '';
  document.getElementById('grp-input-code').value = '';
  document.getElementById('grp-input-color').value = '#10b981';
  document.getElementById('grp-input-desc').value = '';
  document.getElementById('modal-group-submit').textContent = 'Create Zone';
  openModal('modal-group');
}

function openEditGroupModal(gr) {
  document.getElementById('modal-group-title').textContent = '⚙ Edit Zone: ' + gr.name;
  document.getElementById('grp-input-id').value = gr.id;
  document.getElementById('grp-input-cat').value = gr.category || 'Plantation';
  document.getElementById('grp-input-name').value = gr.name;
  document.getElementById('grp-input-code').value = gr.code || '';
  document.getElementById('grp-input-color').value = gr.color || '#10b981';
  document.getElementById('grp-input-desc').value = gr.description || '';
  document.getElementById('modal-group-submit').textContent = 'Update Zone & Color';
  openModal('modal-group');
}

function confirmDeleteGroup(id, name) {
  if (confirm(`Delete Zone "${name}"?\nClick OK to delete.`)) {
    const delSectors = confirm(`Also delete all sectors belonging to "${name}"?\n(Cancel will leave sectors unassigned)`);
    document.getElementById('del-group-id').value = id;
    document.getElementById('del-group-sectors').value = delSectors ? '1' : '0';
    document.getElementById('form-delete-group').submit();
  }
}

// KML Modal Handlers
function openKmlModal() {
  openModal('modal-kml');
}

function openKmlModalForGroup(groupName, cat) {
  document.getElementById('kml-target-cat').value = cat || 'Plantation';
  document.getElementById('kml-target-group').value = groupName;
  openModal('modal-kml');
}

function updateKmlFilesLabel(input) {
  const lbl = document.getElementById('kml-file-label');
  if (input.files && input.files.length > 0) {
    lbl.textContent = `Selected ${input.files.length} file(s): ` + Array.from(input.files).slice(0, 3).map(f => f.name).join(', ') + (input.files.length > 3 ? '...' : '');
    lbl.style.color = '#0284c7';
  } else {
    lbl.textContent = 'Click to choose KML/KMZ files or Drag & Drop here';
    lbl.style.color = '#1e293b';
  }
}

// Category & Search Filters
function filterListAndMap() {
  const cat = document.getElementById('sel-category-filter').value;
  const q = document.getElementById('gf-search-input').value.toLowerCase().trim();

  // Filter Geofences list
  let visibleGf = 0;
  document.querySelectorAll('.gf-row-item').forEach(el => {
    const matchCat = (cat === 'All' || el.getAttribute('data-category') === cat);
    const matchQ = (!q || el.getAttribute('data-name').includes(q) || (el.getAttribute('data-group')||'').toLowerCase().includes(q));
    if (matchCat && matchQ) {
      el.style.display = 'block';
      visibleGf++;
    } else {
      el.style.display = 'none';
    }
  });
  document.getElementById('gf-visible-count').textContent = `Showing ${visibleGf} of <?= $totalGeofences ?>`;

  // Filter Groups list
  document.querySelectorAll('.gr-row-item').forEach(el => {
    const matchCat = (cat === 'All' || el.getAttribute('data-category') === cat);
    const matchQ = (!q || el.getAttribute('data-group-name').toLowerCase().includes(q));
    el.style.display = (matchCat && matchQ) ? 'block' : 'none';
  });

  // Filter Category headers
  document.querySelectorAll('.w-cat-header').forEach(el => {
    if (cat === 'All') el.style.display = 'flex';
    else if (el.classList.contains('cat-sec-' + cat.toLowerCase())) el.style.display = 'flex';
    else el.style.display = 'none';
  });
}

function handleSearch(val) { filterListAndMap(); }
function clearSearch() {
  document.getElementById('gf-search-input').value = '';
  filterListAndMap();
}

// Drawing Mode
function startDrawingMode() {
  drawingMode = true;
  drawnPoints = [];
  document.getElementById('draw-banner').style.display = 'block';
  map.getContainer().style.cursor = 'crosshair';
}
function cancelDrawing() {
  drawingMode = false;
  drawnPoints = [];
  drawMarkers.forEach(m => map.removeLayer(m));
  drawMarkers = [];
  if (drawPolyline) { map.removeLayer(drawPolyline); drawPolyline = null; }
  document.getElementById('draw-banner').style.display = 'none';
  map.getContainer().style.cursor = '';
}
function finishDrawing() {
  if (drawnPoints.length < 3) {
    alert('Please place at least 3 points on the map to create a zone.');
    return;
  }
  const name = prompt('Enter a name for this new Geofence:');
  if (!name) return;

  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_geofence">
    <input type="hidden" name="name" value="${encodeURIComponent(name)}">
    <input type="hidden" name="coordinates" value='${JSON.stringify(drawnPoints)}'>
  `;
  document.body.appendChild(form);
  form.submit();
}

// Modal generic helpers
function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.style.display = 'flex';
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.style.display = 'none';
}
</script>

<?php layout_end(); ?>
