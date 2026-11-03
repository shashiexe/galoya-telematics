<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/_common.php';
require_admin();
require_perm('geofences', 'view');

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

// Wialon-style geofence properties (label style, icon, fill toggle, zoom visibility, circle/line metadata)
foreach ([
    "`label_color` VARCHAR(20) NULL",
    "`label_size` TINYINT UNSIGNED NULL",
    "`icon` VARCHAR(16) NULL",
    "`fill_enabled` TINYINT(1) NOT NULL DEFAULT 1",
    "`min_zoom` TINYINT UNSIGNED NOT NULL DEFAULT 1",
    "`max_zoom` TINYINT UNSIGNED NOT NULL DEFAULT 19",
    "`radius` DOUBLE NULL",
    "`line_width` DOUBLE NULL",
    "`shape_points` MEDIUMTEXT NULL",
] as $colDef) {
    try { @$pdo->exec("ALTER TABLE `geofences` ADD COLUMN $colDef"); } catch (Throwable $e) {}
}
const GF_EXTRA_COLS = "label_color, label_size, icon, fill_enabled, min_zoom, max_zoom, radius, line_width, shape_points";

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
    
    $sql = "SELECT id, category, group_name, block_name, name, code, type, coordinates, color, description, " . GF_EXTRA_COLS . "
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

// Handle AJAX: Server-side search across ALL geofences (initial list only loads 500)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    $sq = trim($_GET['q'] ?? '');
    $cat = trim($_GET['cat'] ?? '');
    if ($sq === '') { echo '[]'; exit; }
    $like = '%' . $sq . '%';
    $where = "active=1 AND (name LIKE ? OR code LIKE ? OR group_name LIKE ? OR block_name LIKE ?)";
    $params = [$like, $like, $like, $like];
    if ($cat !== '' && $cat !== 'All') { $where .= " AND category=?"; $params[] = $cat; }
    $rows = q("SELECT id, category, group_name, block_name, name, code, type, color, description FROM geofences WHERE $where ORDER BY name ASC LIMIT 300", $params)->fetchAll();
    echo json_encode($rows);
    exit;
}

// Handle AJAX: Fetch single geofence details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'single_gf') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $r = q("SELECT id, category, group_name, block_name, name, code, type, coordinates, color, description, " . GF_EXTRA_COLS . " FROM geofences WHERE id=?", [$id])->fetch();
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
    $groupId = (int)($_POST['group_id'] ?? 0);
    require_perm('geofences', $groupId > 0 ? 'edit' : 'create');

    $cat = trim($_POST['category'] ?? 'Plantation');
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $color = trim($_POST['color'] ?? '#10b981');
    $desc = trim($_POST['description'] ?? '');

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
    require_perm('geofences', 'delete');
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

// Handle POST: Create or Update Single Geofence (Wialon properties supported)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_geofence') {
    check_csrf();
    $gfId = (int)($_POST['gf_id'] ?? 0);
    require_perm('geofences', $gfId > 0 ? 'edit' : 'create');
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? $name) ?: $name;
    $cat = trim($_POST['category'] ?? 'Plantation');
    $groupName = trim($_POST['group_name'] ?? '');
    $blockName = trim($_POST['block_name'] ?? '');
    $color = trim($_POST['color'] ?? '#10b981');
    $desc = trim($_POST['description'] ?? '');
    $type = in_array($_POST['type'] ?? '', ['polygon', 'circle', 'line'], true) ? $_POST['type'] : 'polygon';
    $coords_raw = trim($_POST['coordinates'] ?? '[]');
    $labelColor = trim($_POST['label_color'] ?? '#f97316') ?: '#f97316';
    $labelSize = max(8, min(28, (int)($_POST['label_size'] ?? 12)));
    $icon = mb_substr(trim($_POST['icon'] ?? ''), 0, 12) ?: null;
    $fillEnabled = isset($_POST['fill_enabled']) && $_POST['fill_enabled'] === '0' ? 0 : 1;
    $minZoom = max(1, min(21, (int)($_POST['min_zoom'] ?? 1)));
    $maxZoom = max($minZoom, min(21, (int)($_POST['max_zoom'] ?? 19)));
    $radius = isset($_POST['radius']) && $_POST['radius'] !== '' ? (float)$_POST['radius'] : null;
    $lineWidth = isset($_POST['line_width']) && $_POST['line_width'] !== '' ? (float)$_POST['line_width'] : null;
    $shapePoints = trim($_POST['shape_points'] ?? '') ?: null;

    $pts = json_decode($coords_raw, true);
    $cLat = null; $cLng = null;
    if (is_array($pts) && count($pts) >= 1) {
        $sLat = 0; $sLng = 0; $n = 0;
        foreach ($pts as $pt) { if (isset($pt[0], $pt[1])) { $sLat += (float)$pt[0]; $sLng += (float)$pt[1]; $n++; } }
        if ($n > 0) { $cLat = round($sLat / $n, 6); $cLng = round($sLng / $n, 6); }
    }

    if ($name && $coords_raw && $coords_raw !== '[]') {
        $vals = [$cat, $groupName ?: null, $blockName ?: null, $name, $code, $type, $coords_raw, $color, $desc,
                 $labelColor, $labelSize, $icon, $fillEnabled, $minZoom, $maxZoom, $radius, $lineWidth, $shapePoints];
        if ($gfId > 0) {
            q("UPDATE geofences SET category=?, group_name=?, block_name=?, name=?, code=?, type=?, coordinates=?, color=?, description=?,
                 label_color=?, label_size=?, icon=?, fill_enabled=?, min_zoom=?, max_zoom=?, radius=?, line_width=?, shape_points=?
               WHERE id=?", array_merge($vals, [$gfId]));
            if ($cLat !== null) { try { @q("UPDATE geofences SET center_lat=?, center_lng=? WHERE id=?", [$cLat, $cLng, $gfId]); } catch (Throwable $e) {} }
            flash('Geofence "' . $name . '" updated successfully!');
        } else {
            q("INSERT INTO geofences (category, group_name, block_name, name, code, type, coordinates, color, description,
                 label_color, label_size, icon, fill_enabled, min_zoom, max_zoom, radius, line_width, shape_points, active)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)", $vals);
            $newId = (int)$pdo->lastInsertId();
            if ($newId > 0 && $cLat !== null) { try { @q("UPDATE geofences SET center_lat=?, center_lng=? WHERE id=?", [$cLat, $cLng, $newId]); } catch (Throwable $e) {} }
            flash('Geofence "' . $name . '" created successfully!');
        }
    }
    header('Location: geofences.php');
    exit;
}

// Helper: Parse KML string into geofences (supports CDATA, ExtendedData/SimpleData, Folders, MultiGeometry)
function parse_kml_to_geofences(string $kmlContent, string $fallbackName = 'Sector'): array {
    $zones = [];
    $cleanText = static function (string $s): string {
        $s = preg_replace('/<!\[CDATA\[(.*?)\]\]>/is', '$1', $s);
        return trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    };

    preg_match_all('/<Placemark\b[^>]*>(.*?)<\/Placemark>/is', $kmlContent, $placemarkMatches);
    if (!empty($placemarkMatches[1])) {
        foreach ($placemarkMatches[1] as $idx => $pm) {
            $name = '';
            if (preg_match('/<name\b[^>]*>(.*?)<\/name>/is', $pm, $nm)) {
                $name = $cleanText($nm[1]);
            }
            if (!$name && preg_match_all('/<(?:SimpleData|Data)\b[^>]*name=["\']([^"\']+)["\'][^>]*>(.*?)<\/(?:SimpleData|Data)>/is', $pm, $sd, PREG_SET_ORDER)) {
                foreach ($sd as $m) {
                    if (preg_match('/^(plot|plot_no|field|sector|name|code|id|lot|block)/i', $m[1])) {
                        $v = $cleanText($m[2]);
                        if ($v !== '') { $name = $v; break; }
                    }
                }
            }
            if (!$name) $name = $fallbackName . ' ' . ($idx + 1);

            $desc = '';
            if (preg_match('/<description\b[^>]*>(.*?)<\/description>/is', $pm, $dm)) {
                $desc = mb_substr($cleanText($dm[1]), 0, 250);
            }

            // Prefer outerBoundaryIs coordinates if present, otherwise all <coordinates>
            $coordBlocks = [];
            if (preg_match_all('/<outerBoundaryIs\b[^>]*>.*?<coordinates\b[^>]*>(.*?)<\/coordinates>.*?<\/outerBoundaryIs>/is', $pm, $ob) && !empty($ob[1])) {
                $coordBlocks = $ob[1];
            } elseif (preg_match_all('/<coordinates\b[^>]*>(.*?)<\/coordinates>/is', $pm, $coordMatches)) {
                $coordBlocks = $coordMatches[1];
            }

            $partIdx = 0;
            foreach ($coordBlocks as $rawCoordStr) {
                $tokens = preg_split('/[\s\r\n]+/', trim($rawCoordStr));
                $points = [];
                $sLat = 0.0; $sLng = 0.0;
                foreach ($tokens as $tk) {
                    $tk = trim($tk);
                    if ($tk === '') continue;
                    $c = explode(',', $tk);
                    if (count($c) >= 2 && is_numeric($c[0]) && is_numeric($c[1])) {
                        $lng = (float)$c[0];
                        $lat = (float)$c[1];
                        if ($lat != 0 && $lng != 0 && abs($lat) <= 90 && abs($lng) <= 180) {
                            $points[] = [round($lat, 7), round($lng, 7)];
                            $sLat += $lat; $sLng += $lng;
                        }
                    }
                }
                if (count($points) >= 3) {
                    $partIdx++;
                    $n = count($points);
                    $zName = $partIdx > 1 ? ($name . ' (' . $partIdx . ')') : $name;
                    $zones[] = [
                        'name' => $zName,
                        'code' => $name,
                        'type' => 'polygon',
                        'coordinates' => json_encode($points),
                        'center_lat' => round($sLat / $n, 6),
                        'center_lng' => round($sLng / $n, 6),
                        'description' => $desc,
                    ];
                }
            }
        }
    }
    return $zones;
}

// Handle bulk KML / KMZ import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_kml') {
    check_csrf();
    require_perm('geofences', 'create');
    @set_time_limit(300);
    $cat = trim($_POST['category'] ?? 'Plantation');
    $targetGroup = trim($_POST['target_group'] ?? '');
    if ($targetGroup === '__new__') $targetGroup = '';
    $newGroupName = trim($_POST['new_group_name'] ?? '');
    if ($newGroupName !== '') $targetGroup = $newGroupName;
    $overrideColor = !empty($_POST['override_color']);
    $customColor = $overrideColor ? trim($_POST['color'] ?? '') : '';
    $files = $_FILES['kml_files'] ?? null;
    $importedCount = 0;
    $importedNames = [];
    $groupsTouched = [];

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

            if (empty($fileZones)) continue;

            // Determine zone name & ensure group exists in geofence_groups
            $assignedGroup = $targetGroup ?: $blockName;
            $existingGrpColor = '';
            if ($assignedGroup) {
                $existingGrpColor = (string)(q("SELECT color FROM geofence_groups WHERE name=? LIMIT 1", [$assignedGroup])->fetchColumn() ?: '');
            }
            $color = $customColor ?: ($existingGrpColor ?: '#10b981');
            if ($assignedGroup && empty($groupsTouched[$assignedGroup])) {
                q("INSERT IGNORE INTO geofence_groups (category, name, code, color, description, active) 
                   VALUES (?, ?, ?, ?, 'Imported KML Zone', 1)",
                  [$cat, $assignedGroup, strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $assignedGroup), 0, 4) ?: 'ZON'), $color]);
                if ($customColor !== '') {
                    q("UPDATE geofence_groups SET color=? WHERE name=?", [$customColor, $assignedGroup]);
                }
                $groupsTouched[$assignedGroup] = true;
            }

            foreach ($fileZones as $z) {
                $sectorColor = $customColor ?: ($existingGrpColor ?: ($z['color'] ?? '#10b981'));
                if (!empty($_POST['overwrite'])) {
                    q("DELETE FROM geofences WHERE name=? AND group_name=?", [$z['name'], $assignedGroup]);
                }
                q("INSERT INTO geofences (category, group_name, block_name, name, code, type, coordinates, color, description, active) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                   ON DUPLICATE KEY UPDATE coordinates=VALUES(coordinates), group_name=VALUES(group_name), block_name=VALUES(block_name), color=VALUES(color)",
                  [$cat, $assignedGroup, $blockName, $z['name'], $z['code'], $z['type'], $z['coordinates'], $sectorColor, $z['description'] ?: ($assignedGroup . ' | ' . $blockName)]);
                $insId = (int)$pdo->lastInsertId();
                if ($insId > 0 && isset($z['center_lat'], $z['center_lng'])) {
                    try { @q("UPDATE geofences SET center_lat=?, center_lng=? WHERE id=?", [$z['center_lat'], $z['center_lng'], $insId]); } catch (Throwable $e) {}
                } elseif (!empty($_POST['overwrite']) && isset($z['center_lat'], $z['center_lng'])) {
                    try { @q("UPDATE geofences SET center_lat=?, center_lng=? WHERE name=? AND group_name=?", [$z['center_lat'], $z['center_lng'], $z['name'], $assignedGroup]); } catch (Throwable $e) {}
                }
                $importedCount++;
                if (count($importedNames) < 5) $importedNames[] = $z['name'];
            }
        }
    }

    if ($importedCount > 0) {
        $zoneList = implode(', ', array_keys($groupsTouched));
        $extra = count($importedNames) ? " (" . implode(', ', $importedNames) . (count($importedNames) < $importedCount ? '...' : '') . ")" : "";
        flash("🎉 Successfully imported $importedCount Geofence sector(s)$extra into Zone '$zoneList'!");
    } else {
        flash("⚠️ No valid polygon zones found in the uploaded KML/KMZ file(s). Please check that the file contains Polygon or LineString coordinates.");
    }
    header('Location: geofences.php');
    exit;
}

// Handle delete single geofence (supports GET ?delete=&csrf= and POST)
if (isset($_GET['delete'])) {
    $tok = (string)($_POST['csrf'] ?? ($_GET['csrf'] ?? ''));
    if (!hash_equals(csrf(), $tok)) { http_response_code(403); exit('Invalid request'); }
    require_perm('geofences', 'delete');
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

// Fetch active field officers/vehicles to show live on map (scoped to user's subordinates)
try {
    $secScopeU = user_section_scope_sql('u');
    $liveUnits = q("
        SELECT u.id, u.name, u.role, u.code, u.unit_type, u.sos_active, u.on_duty,
               l.lat, l.lng, l.speed, l.accuracy, l.created_at AS last_time
        FROM users u
        JOIN locations l ON l.id = (SELECT id FROM locations WHERE user_id = u.id ORDER BY id DESC LIMIT 1)
        WHERE u.active = 1 AND $secScopeU
    ")->fetchAll();
} catch (Throwable $e) {
    $liveUnits = [];
}

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

/* Wialon Left Floating Map Toolbar (Screenshot Matching) */
.w-map-tools {
  position: absolute;
  top: 12px;
  left: 12px;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.w-mt-group {
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.18);
  display: flex;
  flex-direction: column;
}
.w-mt-btn {
  width: 30px;
  height: 30px;
  border: none;
  border-bottom: 1px solid #e2e8f0;
  background: #ffffff;
  color: #334155;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  position: relative;
  padding: 0;
}
.w-mt-btn:last-child { border-bottom: none; }
.w-mt-btn:hover, .w-mt-btn.active { background: #eff6ff; color: #0284c7; }
.w-mt-popup {
  display: none;
  position: absolute;
  left: 34px;
  top: 0;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  box-shadow: 0 4px 14px rgba(0,0,0,0.22);
  min-width: 175px;
  padding: 4px 0;
  z-index: 1005;
  text-align: left;
}
.w-mt-popup.open { display: block; }
.w-mt-item {
  padding: 6px 12px;
  font-size: 12px;
  color: #1e293b;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: space-between;
  white-space: nowrap;
}
.w-mt-item:hover { background: #f1f5f9; }
.w-mt-item.selected { background: #e2e8f0; font-weight: 700; color: #0f172a; }
.w-zoom-right {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  z-index: 1000;
}
.w-zoom-right .w-mt-btn { font-size: 18px; font-weight: 700; }

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
      <?php if (can('geofences', 'create')): ?>
        <button type="button" class="btn-wialon-blue" id="btn-toolbar-new" onclick="handleToolbarNew()">
          + New
        </button>
        <button type="button" class="btn-wialon-light" onclick="openKmlModal()" title="Bulk Upload Google Earth KML / KMZ files">
          📥 Import KML
        </button>
      <?php endif; ?>
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

    <!-- Wialon-style Geofence Properties Panel (shown on + New or Edit) -->
    <form method="POST" id="wialon-gf-props" style="display:none;background:#eff3f8;border-bottom:2px solid #cbd5e1;font-size:12px;color:#1e293b">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_geofence">
      <input type="hidden" name="gf_id" id="wgp-id" value="0">
      <input type="hidden" name="type" id="wgp-type" value="polygon">
      <input type="hidden" name="coordinates" id="wgp-coords" value="[]">
      <input type="hidden" name="shape_points" id="wgp-shape-points" value="">
      <input type="hidden" name="fill_enabled" id="wgp-fill-hidden" value="1">
      <div style="background:#dde5ef;padding:5px 10px;font-weight:700;font-size:12px;color:#1e293b;border-bottom:1px solid #cbd5e1" id="wgp-header">Geofence properties</div>
      <div style="padding:8px 10px;display:grid;grid-template-columns:86px 1fr;row-gap:6px;column-gap:6px;align-items:center">
        <label>Name: <span style="color:#ef4444">*</span></label>
        <div style="display:flex;gap:4px;align-items:center">
          <input type="text" name="name" id="wgp-name" class="w-form-control" value="New geofence" required style="flex:1;padding:3px 6px;font-size:12px">
          <input type="color" name="label_color" id="wgp-label-color" value="#f97316" title="Label text color" style="width:26px;height:24px;padding:0;border:1px solid #cbd5e1;border-radius:3px;cursor:pointer">
          <select name="label_size" id="wgp-label-size" class="w-form-control" style="width:62px;padding:2px 4px;font-size:11px">
            <option value="10">10 px</option><option value="12" selected>12 px</option><option value="14">14 px</option><option value="16">16 px</option><option value="18">18 px</option>
          </select>
        </div>

        <label>Resource:</label>
        <select name="category" id="wgp-cat" class="w-form-control" style="padding:3px 6px;font-size:12px" onchange="wgpSyncGroups()">
          <option value="Plantation">Galoya Plantations (Pvt) Ltd — Plantation</option>
          <option value="Outgrower">Galoya Plantations (Pvt) Ltd — Outgrower</option>
          <option value="Other">Galoya Plantations (Pvt) Ltd — Other</option>
        </select>

        <label style="align-self:start;padding-top:3px">Description:</label>
        <textarea name="description" id="wgp-desc" class="w-form-control" rows="2" style="padding:3px 6px;font-size:12px;resize:vertical"></textarea>

        <label>Group:</label>
        <select name="group_name" id="wgp-group" class="w-form-control" style="padding:3px 6px;font-size:12px" onchange="wgpGroupChanged()"></select>

        <label>Type:</label>
        <div style="display:flex;align-items:center;gap:6px">
          <div style="display:inline-flex;border:1px solid #cbd5e1;border-radius:4px;overflow:hidden;background:#fff">
            <button type="button" class="wgp-type-btn" data-t="circle" onclick="wgpSetType('circle')" title="Circle (click center, set radius)" style="border:0;background:#fff;padding:4px 9px;cursor:pointer;font-size:13px">●</button>
            <button type="button" class="wgp-type-btn" data-t="polygon" onclick="wgpSetType('polygon')" title="Polygon (click boundary points)" style="border:0;border-left:1px solid #cbd5e1;border-right:1px solid #cbd5e1;background:#dbeafe;padding:4px 9px;cursor:pointer;font-size:13px;color:#1d4ed8">⬟</button>
            <button type="button" class="wgp-type-btn" data-t="line" onclick="wgpSetType('line')" title="Line / Corridor" style="border:0;background:#fff;padding:4px 9px;cursor:pointer;font-size:13px">〰</button>
          </div>
          <span id="wgp-radius-wrap" style="display:none;font-size:11px">R (m): <input type="number" name="radius" id="wgp-radius" value="100" min="5" step="5" style="width:62px;padding:2px 4px;font-size:11px" oninput="wgpRebuildShape()"></span>
          <span id="wgp-width-wrap" style="display:none;font-size:11px">W (m): <input type="number" name="line_width" id="wgp-width" value="20" min="2" step="2" style="width:54px;padding:2px 4px;font-size:11px" oninput="wgpRebuildShape()"></span>
        </div>

        <label>Area:</label>
        <div id="wgp-area" style="font-weight:600;color:#334155">0.000 ha</div>

        <label>Perimeter:</label>
        <div id="wgp-perim" style="font-weight:600;color:#334155">0.000 km, (0 m)</div>

        <label>Icon:</label>
        <div style="display:flex;align-items:center;gap:8px">
          <div id="wgp-icon-box" style="width:36px;height:36px;border:1px solid #cbd5e1;background:#f8fafc;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#94a3b8">✕</div>
          <input type="hidden" name="icon" id="wgp-icon" value="">
          <button type="button" class="btn-wialon-light" style="padding:4px 10px;font-size:11.5px;color:#2563eb" onclick="wgpToggleIcons()">Icon library</button>
          <button type="button" class="btn-wialon-light" style="padding:4px 6px;font-size:11px" onclick="wgpPickIcon('')" title="Clear icon">Clear</button>
        </div>
        <div id="wgp-icon-picker" style="display:none;grid-column:1 / -1;background:#fff;border:1px solid #cbd5e1;border-radius:4px;padding:5px;gap:4px;flex-wrap:wrap">
          <?php foreach (['🌾','🌴','🚜','🏭','🏢','🏠','⛽','💧','🚧','⚠️','📍','🚩','🛡️','📦','🔧','🌳'] as $ic): ?>
            <button type="button" onclick="wgpPickIcon('<?= $ic ?>')" style="border:1px solid #e2e8f0;background:#f8fafc;border-radius:4px;width:28px;height:28px;cursor:pointer;font-size:16px"><?= $ic ?></button>
          <?php endforeach; ?>
        </div>

        <label>Color:</label>
        <div style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" id="wgp-fill-chk" checked onchange="document.getElementById('wgp-fill-hidden').value=this.checked?'1':'0';wgpUpdatePreviewStyle()">
          <input type="color" name="color" id="wgp-color" value="#15803d" onchange="wgpUpdatePreviewStyle()" style="width:32px;height:24px;padding:0;border:1px solid #cbd5e1;border-radius:3px;cursor:pointer">
        </div>

        <label>Visibility:</label>
        <div style="display:flex;align-items:center;gap:6px;font-size:11.5px">
          <span>from:</span>
          <input type="number" name="min_zoom" id="wgp-min-zoom" value="1" min="1" max="21" style="width:46px;padding:2px 4px;border:1px solid #cbd5e1;border-radius:3px">
          <span>to:</span>
          <input type="number" name="max_zoom" id="wgp-max-zoom" value="19" min="1" max="21" style="width:46px;padding:2px 4px;border:1px solid #cbd5e1;border-radius:3px">
        </div>
      </div>
      <div style="padding:4px 10px 6px;font-size:10.5px;color:#475569" id="wgp-hint">Click on map to add polygon points. Double-click a point to remove it.</div>
      <div style="padding:6px 10px 10px;display:flex;justify-content:center;gap:8px;border-top:1px solid #cbd5e1;background:#e5ecf4">
        <button type="button" class="btn-wialon-light" style="min-width:68px" onclick="cancelDrawing()">Cancel</button>
        <button type="button" class="btn-wialon-light" style="min-width:68px" onclick="wgpClearPoints()">Clear</button>
        <button type="submit" class="btn-wialon-blue" style="min-width:74px" onclick="return wgpBeforeSubmit()">Save</button>
      </div>
    </form>

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
                <?php if (can('geofences', 'edit')): ?>
                  <button type="button" class="w-act-btn" onclick="editGeofence(<?= $g['id'] ?>)" title="Edit properties">🔧</button>
                <?php endif; ?>
                <?php if (can('geofences', 'create')): ?>
                  <button type="button" class="w-act-btn" onclick="editGeofence(<?= $g['id'] ?>, true)" title="Copy geofence">❐</button>
                <?php endif; ?>
                <?php if (can('geofences', 'delete')): ?>
                  <a href="geofences.php?delete=<?= $g['id'] ?>&csrf=<?= csrf() ?>" onclick="return confirm('Delete geofence <?= h($g['name']) ?>?')" class="w-act-btn del" title="Delete">✕</a>
                <?php endif; ?>
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
                <?php if (can('geofences', 'create')): ?>
                  <button type="button" class="w-act-btn" onclick="openKmlModalForGroup('<?= h(addslashes($gr['name'])) ?>', '<?= h($gr['category']) ?>')" title="Import KML into this zone">📥</button>
                <?php endif; ?>
                <?php if (can('geofences', 'edit')): ?>
                  <button type="button" class="w-act-btn" onclick="openEditGroupModal(<?= htmlspecialchars(json_encode($gr)) ?>)" title="Zone Properties & Color">⚙</button>
                <?php endif; ?>
                <?php if (can('geofences', 'delete')): ?>
                  <button type="button" class="w-act-btn del" onclick="confirmDeleteGroup(<?= $gr['id'] ?>, '<?= h(addslashes($gr['name'])) ?>')" title="Delete Zone">✕</button>
                <?php endif; ?>
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

    <!-- Wialon Left Floating Map Toolbar (Screenshot Matching) -->
    <div class="w-map-tools">
      <div class="w-mt-group">
        <button type="button" class="w-mt-btn" title="Search on map" onclick="wmtTogglePopup('wmt-search-pop')">🔍</button>
        <div class="w-mt-popup" id="wmt-search-pop" style="padding:6px;min-width:210px">
          <input type="text" class="w-form-control" placeholder="Find geofence or lat,lng..." style="font-size:11.5px;padding:4px 7px" onkeydown="if(event.key==='Enter'){event.preventDefault();wmtQuickFind(this.value)}">
        </div>
      </div>

      <div class="w-mt-group">
        <button type="button" class="w-mt-btn active" id="wmt-btn-vis" title="Visible layers" onclick="wmtTogglePopup('wmt-vis-pop')">👁</button>
        <div class="w-mt-popup" id="wmt-vis-pop">
          <label class="w-mt-item"><input type="checkbox" checked onchange="wmtToggleUnitsLayer(this.checked)"> <span style="flex:1;margin-left:6px">Units on map</span></label>
          <label class="w-mt-item"><input type="checkbox" checked onchange="wmtToggleLabelsLayer(this.checked)"> <span style="flex:1;margin-left:6px">Geofence labels</span></label>
        </div>

        <button type="button" class="w-mt-btn" id="wmt-btn-maps" title="Select map (Gurtam / Google / Satellite)" onclick="wmtTogglePopup('wmt-maps-pop')">🗺️</button>
        <div class="w-mt-popup" id="wmt-maps-pop">
          <div class="w-mt-item selected" data-map="Gurtam Maps" onclick="wmtSelectBaseMap('Gurtam Maps', this)">Gurtam Maps</div>
          <div class="w-mt-item" data-map="Gurtam Maps modern" onclick="wmtSelectBaseMap('Gurtam Maps modern', this)">Gurtam Maps modern</div>
          <div style="border-top:1px solid #e2e8f0;margin:3px 0"></div>
          <div class="w-mt-item" data-map="Google Streets" onclick="wmtSelectBaseMap('Google Streets', this)">Google Streets</div>
          <div class="w-mt-item" data-map="Google Hybrid" onclick="wmtSelectBaseMap('Google Hybrid', this)">Google Hybrid</div>
          <div class="w-mt-item" data-map="Google Satellite" onclick="wmtSelectBaseMap('Google Satellite', this)">Google Satellite</div>
          <div class="w-mt-item" data-map="Google Terrain" onclick="wmtSelectBaseMap('Google Terrain', this)">Google Terrain</div>
          <div class="w-mt-item" data-map="Esri Satellite" onclick="wmtSelectBaseMap('Esri Satellite', this)">Esri Satellite</div>
        </div>

        <button type="button" class="w-mt-btn active" id="wmt-btn-units" title="Toggle live units" onclick="wmtQuickToggleUnits(this)">🚍</button>
        <button type="button" class="w-mt-btn active" id="wmt-btn-labels" title="Toggle sector labels" onclick="wmtQuickToggleLabels(this)">🏷️</button>
      </div>

      <div class="w-mt-group">
        <button type="button" class="w-mt-btn" id="wmt-btn-measure" title="Measure distance & area" onclick="wmtToggleMeasure(this)">📏</button>
        <button type="button" class="w-mt-btn" title="Fit active zones on screen" onclick="wmtFitAll()">⛶</button>
        <button type="button" class="w-mt-btn" title="Reset view to Galoya Estate" onclick="map.setView([7.2050, 81.6580], 13)">🎯</button>
      </div>
    </div>

    <!-- Wialon Right-Center Zoom +/- Buttons -->
    <div class="w-zoom-right">
      <div class="w-mt-group">
        <button type="button" class="w-mt-btn" title="Zoom in" onclick="map.zoomIn()">+</button>
        <button type="button" class="w-mt-btn" title="Zoom out" onclick="map.zoomOut()">−</button>
      </div>
    </div>

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
          <select name="target_group" id="kml-target-group" class="w-form-control" onchange="onKmlTargetGroupChange(this)">
            <option value="" data-color="#10b981">-- Auto-Assign by KML Filename --</option>
            <option value="__new__" data-color="#10b981">➕ Create New Zone / Group...</option>
            <?php foreach ($groupsData as $g): ?>
              <option value="<?= h($g['name']) ?>" data-color="<?= h($g['color'] ?: '#10b981') ?>"><?= h($g['name']) ?> (<?= h($g['category']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="w-form-group" id="kml-new-group-wrap" style="display:none">
          <label>New Zone / Group Name</label>
          <input type="text" name="new_group_name" id="kml-new-group-input" class="w-form-control" placeholder="e.g. Hingurana Block B">
        </div>

        <div class="w-form-group">
          <label>Select KML or KMZ Files (Multi-Select Allowed)</label>
          <div id="kml-drop-zone"
               style="border:2px dashed #cbd5e1;border-radius:6px;padding:20px;text-align:center;background:#f8fafc;cursor:pointer;transition:all .15s"
               onclick="document.getElementById('kml-file-input').click()"
               ondragover="event.preventDefault();this.style.borderColor='#0284c7';this.style.background='#eff6ff'"
               ondragleave="this.style.borderColor='#cbd5e1';this.style.background='#f8fafc'"
               ondrop="event.preventDefault();this.style.borderColor='#cbd5e1';this.style.background='#f8fafc';if(event.dataTransfer.files.length){const inp=document.getElementById('kml-file-input');inp.files=event.dataTransfer.files;updateKmlFilesLabel(inp);}">
            <div style="font-size:28px">📂</div>
            <div style="font-size:12.5px;font-weight:600;color:#1e293b;margin-top:6px" id="kml-file-label">Click to choose KML/KMZ files or Drag & Drop here</div>
            <div style="font-size:11px;color:#64748b;margin-top:4px">Supports Google Earth / QGIS .kml & .kmz files (Polygons, MultiGeometry, ExtendedData)</div>
            <input type="file" id="kml-file-input" name="kml_files[]" multiple accept=".kml,.kmz,.xml" style="display:none" onchange="updateKmlFilesLabel(this)">
          </div>
        </div>

        <div style="display:flex;gap:14px;align-items:center;justify-content:space-between;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:#334155;font-weight:600" title="Current color of the selected Target Zone">
              <span>Current Color:</span>
              <span id="kml-current-color-swatch" style="display:inline-block;width:18px;height:18px;border-radius:4px;background:#10b981;border:1px solid #94a3b8;vertical-align:middle"></span>
              <span id="kml-current-color-hex" style="font-family:monospace;font-size:11px;color:#64748b">#10b981</span>
            </div>
            <span style="color:#cbd5e1">|</span>
            <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:#334155;font-weight:600;cursor:pointer;margin:0">
              <input type="checkbox" name="override_color" id="kml-override-color-chk" value="1" onchange="toggleKmlOverrideColor(this.checked)">
              <span>Override Zone Color (Optional)</span>
            </label>
            <input type="color" name="color" id="kml-color-input" value="#10b981"
                   onchange="document.getElementById('kml-override-color-chk').checked=true;toggleKmlOverrideColor(true)"
                   style="width:38px;height:28px;padding:1px;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;opacity:0.45"
                   title="Pick a new color to override the zone color">
          </div>
          <div>
            <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;color:#334155;cursor:pointer;margin:0;white-space:nowrap">
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

  const gOpts = { maxZoom: 21, maxNativeZoom: 20, subdomains: ['mt0','mt1','mt2','mt3'], attribution: '© Google' };
  const gRoad = L.tileLayer('https://{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', gOpts);
  const gSat = L.tileLayer('https://{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}', gOpts);
  const gHybrid = L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', gOpts);
  const gTerrain = L.tileLayer('https://{s}.google.com/vt/lyrs=p&x={x}&y={y}&z={z}', gOpts);

  map = L.map('map', {
    center: [7.2050, 81.6580],
    zoom: 13,
    zoomControl: false,
    layers: [osm]
  });

  L.control.scale({ position: 'bottomleft', imperial: true, metric: true }).addTo(map);

  baseLayers = {
    "Gurtam Maps": osm,
    "Gurtam Maps modern": cartoLight,
    "Google Streets": gRoad,
    "Google Satellite": gSat,
    "Google Hybrid": gHybrid,
    "Google Terrain": gTerrain,
    "Esri Satellite": satellite
  };
  window._currentBaseLayer = osm;

  // Close floating map popups when clicking on map canvas
  map.on('click', function() {
    document.querySelectorAll('.w-mt-popup.open').forEach(p => p.classList.remove('open'));
  });

  // HUD Mouse Movement Coordinates (Wialon Degrees & Decimal Minutes format: N 07° 16.5422' E 081° 38.6147')
  map.on('mousemove', function(e) {
    const fmtDM = (v, pos, neg) => {
      const a = Math.abs(v), d = Math.floor(a), m = ((a - d) * 60).toFixed(4).padStart(7, '0');
      return `${v >= 0 ? pos : neg} ${String(d).padStart(pos === 'E' ? 3 : 2, '0')}° ${m}'`;
    };
    const hud = document.getElementById('hud-coords');
    if (hud) hud.innerHTML = `${fmtDM(e.latlng.lat, 'N', 'S')} &nbsp; ${fmtDM(e.latlng.lng, 'E', 'W')}`;
  });

  // Map click for Wialon drawing (Polygon / Circle / Line) or Measure tool
  map.on('click', function(e) {
    if (_measureOn && _measureLayer) {
      const p = [e.latlng.lat, e.latlng.lng];
      _measurePts.push(p);
      _measureLayer.clearLayers();
      _measurePts.forEach(pt => L.circleMarker(pt, { radius: 4, color: '#dc2626', fillColor: '#fff', fillOpacity: 1 }).addTo(_measureLayer));
      if (_measurePts.length >= 2) {
        const dM = wgpPerimeterM(_measurePts, false);
        const aM2 = _measurePts.length >= 3 ? wgpPolygonAreaM2(_measurePts) : 0;
        const pl = L.polyline(_measurePts, { color: '#dc2626', weight: 2, dashArray: '4,4' }).addTo(_measureLayer);
        pl.bindTooltip(`Dist: ${(dM/1000).toFixed(3)} km (${Math.round(dM)} m)` + (aM2 ? ` • Area: ${(aM2/10000).toFixed(3)} ha` : ''), { permanent: true }).openTooltip();
      }
      return;
    }
    if (!drawingMode) return;
    wgpAddPoint([e.latlng.lat, e.latlng.lng]);
  });
  map.on('zoomend', function() {
    if (typeof wgpApplyZoomVisibility === 'function') wgpApplyZoomVisibility();
  });
}

// Render Live Vehicle / Officer Units
let unitMarkersLayer = null;
function renderLiveUnits() {
  if (!unitMarkersLayer) unitMarkersLayer = L.layerGroup().addTo(map);
  unitMarkersLayer.clearLayers();
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
    const marker = L.marker([parseFloat(u.lat), parseFloat(u.lng)], { icon: icon }).addTo(unitMarkersLayer);
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

// Wialon Left Floating Map Toolbar Helpers
function wmtTogglePopup(id) {
  document.querySelectorAll('.w-mt-popup').forEach(p => { if (p.id !== id) p.classList.remove('open'); });
  const el = document.getElementById(id);
  if (el) el.classList.toggle('open');
}
function wmtSelectBaseMap(name, itemEl) {
  const next = baseLayers[name];
  if (!next) return;
  if (window._currentBaseLayer) map.removeLayer(window._currentBaseLayer);
  map.addLayer(next);
  window._currentBaseLayer = next;
  document.querySelectorAll('#wmt-maps-pop .w-mt-item').forEach(i => i.classList.remove('selected'));
  if (itemEl) itemEl.classList.add('selected');
  document.getElementById('wmt-maps-pop').classList.remove('open');
}
function wmtToggleUnitsLayer(show) {
  if (!unitMarkersLayer) return;
  if (show) map.addLayer(unitMarkersLayer); else map.removeLayer(unitMarkersLayer);
  const b = document.getElementById('wmt-btn-units');
  if (b) b.classList.toggle('active', show);
}
function wmtQuickToggleUnits(btn) {
  const show = !btn.classList.contains('active');
  wmtToggleUnitsLayer(show);
}
let _labelsHidden = false;
function wmtToggleLabelsLayer(show) {
  _labelsHidden = !show;
  document.querySelectorAll('.sector-label').forEach(el => { el.style.display = show ? '' : 'none'; });
  const b = document.getElementById('wmt-btn-labels');
  if (b) b.classList.toggle('active', show);
}
function wmtQuickToggleLabels(btn) {
  wmtToggleLabelsLayer(_labelsHidden);
}
function wmtFitAll() {
  const bounds = L.latLngBounds([]);
  Object.values(activeZoneLayers).forEach(lg => { if (map.hasLayer(lg) && lg.getBounds().isValid()) bounds.extend(lg.getBounds()); });
  Object.values(singleGfLayers).forEach(ly => { if (map.hasLayer(ly) && ly.getBounds?.().isValid()) bounds.extend(ly.getBounds()); });
  if (bounds.isValid()) map.fitBounds(bounds, { padding: [30, 30] });
}
function wmtQuickFind(val) {
  val = (val || '').trim();
  if (!val) return;
  const m = val.match(/^\s*(-?\d+(?:\.\d+)?)\s*[,\s]\s*(-?\d+(?:\.\d+)?)\s*$/);
  if (m) {
    map.setView([parseFloat(m[1]), parseFloat(m[2])], 16);
    document.getElementById('wmt-search-pop').classList.remove('open');
    return;
  }
  const inp = document.getElementById('gf-search-input');
  inp.value = val;
  handleSearch(val);
  document.getElementById('wmt-search-pop').classList.remove('open');
}
let _measureOn = false, _measurePts = [], _measureLayer = null;
function wmtToggleMeasure(btn) {
  _measureOn = !_measureOn;
  btn.classList.toggle('active', _measureOn);
  if (_measureLayer) { map.removeLayer(_measureLayer); _measureLayer = null; }
  _measurePts = [];
  if (_measureOn) {
    _measureLayer = L.layerGroup().addTo(map);
    map.getContainer().style.cursor = 'crosshair';
  } else if (!drawingMode) {
    map.getContainer().style.cursor = '';
  }
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
        const fillOn = String(s.fill_enabled ?? '1') !== '0';
        const col = s.color || '#10b981';
        const poly = L.polygon(s.coords, {
          color: fillOn ? '#ffffff' : col,
          weight: 2,
          fillColor: col,
          fillOpacity: fillOn ? 0.7 : 0
        }).addTo(map);
        poly._gfMeta = { minZ: parseInt(s.min_zoom || 1), maxZ: parseInt(s.max_zoom || 19) };
        const lblCol = _escHtml(s.label_color || '#ffffff');
        const lblSz = parseInt(s.label_size || 12);
        const ic = s.icon ? (_escHtml(s.icon) + ' ') : '';
        poly.bindTooltip(`<span style="color:${lblCol};font-size:${lblSz}px;font-weight:700;text-shadow:0 1px 3px rgba(0,0,0,.85)">${ic}${_escHtml(s.name)}</span>`, { permanent: true, direction: 'center', className: 'sector-label' });
        poly.bindPopup(`<strong>${ic}${_escHtml(s.name)}</strong><br>Zone: ${_escHtml(s.group_name || 'N/A')}`);
        singleGfLayers[id] = poly;
        map.fitBounds(poly.getBounds(), { padding: [40, 40], maxZoom: 16 });
        if (typeof wgpApplyZoomVisibility === 'function') wgpApplyZoomVisibility();
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
function onKmlTargetGroupChange(sel) {
  if (!sel) sel = document.getElementById('kml-target-group');
  const wrap = document.getElementById('kml-new-group-wrap');
  if (wrap) wrap.style.display = (sel.value === '__new__' ? 'block' : 'none');

  const opt = sel.options[sel.selectedIndex];
  const curColor = (opt && opt.getAttribute('data-color')) ? opt.getAttribute('data-color') : '#10b981';
  const swatch = document.getElementById('kml-current-color-swatch');
  const hexLbl = document.getElementById('kml-current-color-hex');
  const colorInp = document.getElementById('kml-color-input');
  const chk = document.getElementById('kml-override-color-chk');

  if (swatch) swatch.style.background = curColor;
  if (hexLbl) hexLbl.textContent = curColor;
  if (colorInp && (!chk || !chk.checked)) {
    colorInp.value = curColor;
  }
}

function toggleKmlOverrideColor(checked) {
  const colorInp = document.getElementById('kml-color-input');
  if (colorInp) {
    colorInp.style.opacity = checked ? '1' : '0.45';
    colorInp.style.borderColor = checked ? '#0284c7' : '#cbd5e1';
  }
  if (!checked) {
    onKmlTargetGroupChange(document.getElementById('kml-target-group'));
  }
}

function openKmlModal() {
  const chk = document.getElementById('kml-override-color-chk');
  if (chk) chk.checked = false;
  toggleKmlOverrideColor(false);
  onKmlTargetGroupChange(document.getElementById('kml-target-group'));
  openModal('modal-kml');
}

function openKmlModalForGroup(groupName, cat) {
  document.getElementById('kml-target-cat').value = cat || 'Plantation';
  document.getElementById('kml-target-group').value = groupName;
  const chk = document.getElementById('kml-override-color-chk');
  if (chk) chk.checked = false;
  toggleKmlOverrideColor(false);
  onKmlTargetGroupChange(document.getElementById('kml-target-group'));
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

// Server-side search: fetch matches beyond the first 500 preloaded rows and append them
let _searchTimer = null;
const GF_CAN_DELETE = <?= can('geofences', 'delete') ? 'true' : 'false' ?>;
const GF_CAN_EDIT = <?= can('geofences', 'edit') ? 'true' : 'false' ?>;
const GF_CAN_CREATE = <?= can('geofences', 'create') ? 'true' : 'false' ?>;
const GF_CSRF = <?= json_encode(csrf()) ?>;
const GF_ZONES = <?= json_encode(array_map(fn($g) => ['name' => $g['name'], 'category' => $g['category'] ?? 'Plantation', 'color' => $g['color'] ?? '#10b981'], $groupsData)) ?>;
function _escHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function serverSearch() {
  const q = document.getElementById('gf-search-input').value.trim();
  const cat = document.getElementById('sel-category-filter').value;
  if (q.length < 2) return;
  fetch(`geofences.php?ajax=search&q=${encodeURIComponent(q)}&cat=${encodeURIComponent(cat)}`)
    .then(r => r.json())
    .then(rows => {
      const box = document.getElementById('gf-list-container');
      rows.forEach(g => {
        if (box.querySelector(`.gf-row-item[data-id="${g.id}"]`)) return;
        const div = document.createElement('div');
        div.className = 'w-group-item gf-row-item';
        div.setAttribute('data-id', g.id);
        div.setAttribute('data-name', String(g.name || '').toLowerCase());
        div.setAttribute('data-group', g.group_name || '');
        div.setAttribute('data-category', g.category || 'Plantation');
        div.setAttribute('data-color', g.color || '#10b981');
        const details = [
          `Zone: <b>${_escHtml(g.group_name || 'No Zone')}</b>`,
          `Cat: <b>${_escHtml(g.category || 'Plantation')}</b>`,
          g.block_name ? `Block: <b>${_escHtml(g.block_name)}</b>` : '',
          (g.code && g.code !== g.name) ? `Code: <b>${_escHtml(g.code)}</b>` : '',
        ].filter(Boolean).join(' | ');
        const editBtn = GF_CAN_EDIT ? `<button type="button" class="w-act-btn" onclick="editGeofence(${g.id})" title="Edit properties">🔧</button>` : '';
        const copyBtn = GF_CAN_CREATE ? `<button type="button" class="w-act-btn" onclick="editGeofence(${g.id}, true)" title="Copy geofence">❐</button>` : '';
        const delBtn = GF_CAN_DELETE
          ? `<a href="geofences.php?delete=${g.id}&csrf=${encodeURIComponent(GF_CSRF)}" class="w-act-btn del" title="Delete"
               onclick="return confirm('Delete geofence ' + ${_escHtml(JSON.stringify(String(g.name || '')))} + '?')">✕</a>`
          : '';
        div.innerHTML = `<div class="w-group-main">
            <input type="checkbox" class="chk-gf-single" onchange="toggleSingleGf(${g.id}, this.checked)">
            <div class="w-color-badge" style="background:${_escHtml(g.color || '#10b981')}"></div>
            <div class="w-group-title" onclick="zoomToSingleGf(${g.id})">
              <strong>${_escHtml(g.name)}</strong>
              <div style="font-size:10.5px;color:#64748b;margin-top:2px">${details}</div>
              ${g.description ? `<div style="font-size:10px;color:#94a3b8">${_escHtml(g.description)}</div>` : ''}
            </div>
            <div class="w-actions-cell">
              <button type="button" class="w-act-btn" onclick="zoomToSingleGf(${g.id})" title="View on map">🔍</button>
              ${editBtn}${copyBtn}${delBtn}
            </div>
          </div>`;
        box.prepend(div);
      });
      filterListAndMap();
    })
    .catch(() => {});
}
function handleSearch(val) {
  filterListAndMap();
  clearTimeout(_searchTimer);
  _searchTimer = setTimeout(serverSearch, 350);
}
function clearSearch() {
  document.getElementById('gf-search-input').value = '';
  filterListAndMap();
}

// ============================================================================
// Wialon Geofence Properties & Drawing Engine (Polygon, Circle, Line/Corridor)
// ============================================================================
let wgpShapeType = 'polygon';
let wgpPolygonPoints = []; // final polygon [lat,lng] ring saved to `coordinates`

function wgpSyncGroups(selectedGroup) {
  const cat = document.getElementById('wgp-cat').value;
  const sel = document.getElementById('wgp-group');
  const prev = selectedGroup !== undefined ? selectedGroup : sel.value;
  sel.innerHTML = '<option value="">None</option>';
  GF_ZONES.filter(z => z.category === cat).forEach(z => {
    const o = document.createElement('option');
    o.value = z.name; o.textContent = z.name; o.dataset.color = z.color;
    if (z.name === prev) o.selected = true;
    sel.appendChild(o);
  });
}
function wgpGroupChanged() {
  const c = document.getElementById('wgp-group').selectedOptions[0]?.dataset.color;
  if (c) { document.getElementById('wgp-color').value = c; wgpUpdatePreviewStyle(); }
}
function wgpToggleIcons() {
  const p = document.getElementById('wgp-icon-picker');
  p.style.display = p.style.display === 'flex' ? 'none' : 'flex';
}
function wgpPickIcon(ic) {
  document.getElementById('wgp-icon').value = ic;
  const box = document.getElementById('wgp-icon-box');
  box.textContent = ic || '✕';
  box.style.color = ic ? '#0f172a' : '#94a3b8';
  document.getElementById('wgp-icon-picker').style.display = 'none';
}
function wgpSetType(t) {
  wgpShapeType = t;
  document.getElementById('wgp-type').value = t;
  document.querySelectorAll('.wgp-type-btn').forEach(b => {
    const on = b.dataset.t === t;
    b.style.background = on ? '#dbeafe' : '#fff';
    b.style.color = on ? '#1d4ed8' : '#1e293b';
  });
  document.getElementById('wgp-radius-wrap').style.display = t === 'circle' ? 'inline' : 'none';
  document.getElementById('wgp-width-wrap').style.display = t === 'line' ? 'inline' : 'none';
  document.getElementById('wgp-hint').textContent =
    t === 'circle' ? 'Click on map to set circle center (or click a second point to set radius).' :
    t === 'line' ? 'Click points along the route/corridor. Width (W) sets buffer in meters.' :
    'Click on map to add polygon points. Double-click a point marker to remove it.';
  if (t === 'circle' && drawnPoints.length > 1) drawnPoints = [drawnPoints[0]];
  wgpRebuildShape();
}

function wgpDistM(a, b) {
  const R = 6371000, toR = Math.PI / 180;
  const dLat = (b[0] - a[0]) * toR, dLng = (b[1] - a[1]) * toR;
  const x = Math.sin(dLat/2)**2 + Math.cos(a[0]*toR)*Math.cos(b[0]*toR)*Math.sin(dLng/2)**2;
  return 2 * R * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
}
function wgpDestPoint(pt, brngRad, distM) {
  const R = 6371000, toR = Math.PI / 180, toD = 180 / Math.PI;
  const d = distM / R, lat1 = pt[0] * toR, lng1 = pt[1] * toR;
  const lat2 = Math.asin(Math.sin(lat1)*Math.cos(d) + Math.cos(lat1)*Math.sin(d)*Math.cos(brngRad));
  const lng2 = lng1 + Math.atan2(Math.sin(brngRad)*Math.sin(d)*Math.cos(lat1), Math.cos(d) - Math.sin(lat1)*Math.sin(lat2));
  return [+(lat2 * toD).toFixed(7), + (lng2 * toD).toFixed(7)];
}
function wgpCircleRing(center, rM, n = 36) {
  const pts = [];
  for (let i = 0; i < n; i++) pts.push(wgpDestPoint(center, (2 * Math.PI * i) / n, rM));
  return pts;
}
function wgpLineBufferRing(pts, widthM) {
  if (pts.length < 2) return [];
  const half = Math.max(1, widthM / 2), left = [], right = [];
  for (let i = 0; i < pts.length; i++) {
    const a = pts[Math.max(0, i - 1)], b = pts[Math.min(pts.length - 1, i + 1)];
    const mLat = ((a[0] + b[0]) / 2) * Math.PI / 180;
    const dy = b[0] - a[0], dx = (b[1] - a[1]) * Math.cos(mLat);
    const brng = Math.atan2(dx, dy);
    left.push(wgpDestPoint(pts[i], brng - Math.PI / 2, half));
    right.unshift(wgpDestPoint(pts[i], brng + Math.PI / 2, half));
  }
  return left.concat(right);
}
function wgpPolygonAreaM2(pts) {
  if (!pts || pts.length < 3) return 0;
  const R = 6371000, toR = Math.PI / 180, lat0 = pts[0][0] * toR, cos0 = Math.cos(lat0);
  let sum = 0;
  for (let i = 0; i < pts.length; i++) {
    const p1 = pts[i], p2 = pts[(i + 1) % pts.length];
    const x1 = p1[1] * toR * R * cos0, y1 = p1[0] * toR * R;
    const x2 = p2[1] * toR * R * cos0, y2 = p2[0] * toR * R;
    sum += (x1 * y2 - x2 * y1);
  }
  return Math.abs(sum) / 2;
}
function wgpPerimeterM(pts, closed = true) {
  if (!pts || pts.length < 2) return 0;
  let d = 0;
  for (let i = 0; i < pts.length - 1; i++) d += wgpDistM(pts[i], pts[i + 1]);
  if (closed && pts.length > 2) d += wgpDistM(pts[pts.length - 1], pts[0]);
  return d;
}

function wgpAddPoint(p) {
  if (wgpShapeType === 'circle') {
    if (drawnPoints.length === 0) {
      drawnPoints = [p];
    } else {
      const r = Math.max(5, Math.round(wgpDistM(drawnPoints[0], p)));
      document.getElementById('wgp-radius').value = r;
    }
  } else {
    drawnPoints.push(p);
  }
  wgpRebuildShape();
}

function wgpRebuildShape() {
  drawMarkers.forEach(m => map.removeLayer(m));
  drawMarkers = [];
  if (drawPolyline) { map.removeLayer(drawPolyline); drawPolyline = null; }

  drawnPoints.forEach((pt, idx) => {
    const mk = L.marker(pt, {
      draggable: true,
      icon: L.divIcon({
        className: '',
        html: '<div style="width:10px;height:10px;background:#fff;border:2px solid #0284c7;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.4)"></div>',
        iconSize: [10, 10], iconAnchor: [5, 5]
      })
    }).addTo(map);
    mk.on('drag', e => { const ll = e.target.getLatLng(); drawnPoints[idx] = [ll.lat, ll.lng]; wgpUpdateGeometryOnly(); });
    mk.on('dblclick', e => { L.DomEvent.stopPropagation(e); drawnPoints.splice(idx, 1); wgpRebuildShape(); });
    drawMarkers.push(mk);
  });
  wgpUpdateGeometryOnly();
}

function wgpUpdateGeometryOnly() {
  const col = document.getElementById('wgp-color').value || '#15803d';
  const fillOn = document.getElementById('wgp-fill-chk').checked;
  let areaM2 = 0, perimM = 0;
  wgpPolygonPoints = [];

  if (wgpShapeType === 'circle' && drawnPoints.length >= 1) {
    const r = Math.max(5, parseFloat(document.getElementById('wgp-radius').value) || 100);
    wgpPolygonPoints = wgpCircleRing(drawnPoints[0], r, 36);
    areaM2 = Math.PI * r * r;
    perimM = 2 * Math.PI * r;
  } else if (wgpShapeType === 'line' && drawnPoints.length >= 2) {
    const w = Math.max(2, parseFloat(document.getElementById('wgp-width').value) || 20);
    wgpPolygonPoints = wgpLineBufferRing(drawnPoints, w);
    perimM = wgpPerimeterM(drawnPoints, false);
    areaM2 = perimM * w;
  } else if (wgpShapeType === 'polygon' && drawnPoints.length >= 1) {
    wgpPolygonPoints = drawnPoints.map(p => [+p[0].toFixed(7), +p[1].toFixed(7)]);
    areaM2 = wgpPolygonAreaM2(wgpPolygonPoints);
    perimM = wgpPerimeterM(wgpPolygonPoints, true);
  }

  if (drawPolyline) { map.removeLayer(drawPolyline); drawPolyline = null; }
  if (wgpPolygonPoints.length >= 2) {
    drawPolyline = L.polygon(wgpPolygonPoints, {
      color: col, weight: 2, fillColor: col, fillOpacity: fillOn ? 0.45 : 0
    }).addTo(map);
  }

  document.getElementById('wgp-area').textContent = (areaM2 / 10000).toFixed(3) + ' ha';
  document.getElementById('wgp-perim').textContent = (perimM / 1000).toFixed(3) + ' km, (' + Math.round(perimM) + ' m)';
  document.getElementById('wgp-coords').value = JSON.stringify(wgpPolygonPoints);
  document.getElementById('wgp-shape-points').value = JSON.stringify(drawnPoints);
}
function wgpUpdatePreviewStyle() { wgpUpdateGeometryOnly(); }
function wgpClearPoints() { drawnPoints = []; wgpRebuildShape(); }

function startDrawingMode(prefill) {
  switchMainTab('geofences');
  drawingMode = true;
  drawnPoints = [];
  document.getElementById('wialon-gf-props').style.display = 'block';
  document.getElementById('wgp-id').value = prefill?.id || 0;
  document.getElementById('wgp-header').textContent = prefill?.id ? ('Edit geofence: ' + prefill.name) : 'Geofence properties';
  document.getElementById('wgp-name').value = prefill?.name || 'New geofence';
  document.getElementById('wgp-label-color').value = prefill?.label_color || '#f97316';
  document.getElementById('wgp-label-size').value = String(prefill?.label_size || 12);
  document.getElementById('wgp-cat').value = prefill?.category || 'Plantation';
  wgpSyncGroups(prefill?.group_name || '');
  document.getElementById('wgp-desc').value = prefill?.description || '';
  const fillOn = String(prefill?.fill_enabled ?? '1') !== '0';
  document.getElementById('wgp-fill-chk').checked = fillOn;
  document.getElementById('wgp-fill-hidden').value = fillOn ? '1' : '0';
  document.getElementById('wgp-color').value = prefill?.color || '#15803d';
  document.getElementById('wgp-min-zoom').value = prefill?.min_zoom || 1;
  document.getElementById('wgp-max-zoom').value = prefill?.max_zoom || 19;
  document.getElementById('wgp-radius').value = prefill?.radius || 100;
  document.getElementById('wgp-width').value = prefill?.line_width || 20;
  wgpPickIcon(prefill?.icon || '');
  wgpSetType(prefill?.type || 'polygon');
  if (prefill?.points && Array.isArray(prefill.points)) {
    drawnPoints = prefill.points.map(p => [parseFloat(p[0]), parseFloat(p[1])]);
  }
  wgpRebuildShape();
  map.getContainer().style.cursor = 'crosshair';
  document.getElementById('wgp-name').focus();
}

function editGeofence(id, asCopy = false) {
  fetch(`geofences.php?ajax=single_gf&id=${id}`)
    .then(r => r.json())
    .then(s => {
      if (!s) return;
      let pts = s.coords || [];
      if (s.shape_points) { try { const sp = JSON.parse(s.shape_points); if (Array.isArray(sp) && sp.length) pts = sp; } catch (_) {} }
      s.points = pts;
      if (asCopy) { s.id = 0; s.name = (s.name || 'Geofence') + ' (Copy)'; }
      startDrawingMode(s);
      if (drawPolyline) map.fitBounds(drawPolyline.getBounds(), { padding: [40, 40], maxZoom: 16 });
    });
}

function cancelDrawing() {
  drawingMode = false;
  drawnPoints = [];
  wgpPolygonPoints = [];
  drawMarkers.forEach(m => map.removeLayer(m));
  drawMarkers = [];
  if (drawPolyline) { map.removeLayer(drawPolyline); drawPolyline = null; }
  document.getElementById('wialon-gf-props').style.display = 'none';
  map.getContainer().style.cursor = '';
}

function wgpBeforeSubmit() {
  wgpUpdateGeometryOnly();
  if (wgpPolygonPoints.length < 3) {
    alert(wgpShapeType === 'circle' ? 'Please click on the map to place the circle center.' :
          wgpShapeType === 'line' ? 'Please click at least 2 points on the map for the line.' :
          'Please place at least 3 points on the map to create a polygon.');
    return false;
  }
  return true;
}

function wgpApplyZoomVisibility() {
  const z = map.getZoom();
  Object.values(singleGfLayers).forEach(layer => {
    if (!layer._gfMeta) return;
    const vis = z >= layer._gfMeta.minZ && z <= layer._gfMeta.maxZ;
    const el = layer.getElement?.();
    if (el) el.style.display = vis ? '' : 'none';
    const tp = layer.getTooltip?.()?.getElement?.();
    if (tp) tp.style.display = vis ? '' : 'none';
  });
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
