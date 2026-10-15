<?php
require __DIR__ . '/../bootstrap.php';
$pdo = db();

// 1. Create drivers table
$pdo->exec("CREATE TABLE IF NOT EXISTS drivers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  code VARCHAR(50) NULL,
  phone VARCHAR(30) NULL,
  license_no VARCHAR(50) NULL,
  rfid_code VARCHAR(50) NULL,
  assigned_unit_id INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (assigned_unit_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 2. Create notifications table
$pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_id INT NOT NULL,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(150) NOT NULL,
  message TEXT NOT NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (unit_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Seed realistic drivers if empty
$driverCount = (int)$pdo->query("SELECT COUNT(*) FROM drivers")->fetchColumn();
if ($driverCount === 0) {
    $drivers = [
        ['A.B. Chandrasena', 'DRV-101', '0771234501', 'B-7891234', 'RFID-8812', 2], // 49-1665
        ['K.M. Sunil Shantha', 'DRV-102', '0771234502', 'B-8912345', 'RFID-8813', 3], // 49-3835
        ['W.P. Ranjith Bandara', 'DRV-103', '0771234503', 'H-9012345', 'RFID-8814', 4], // 2806 Harvester
        ['M.G. Dharmasiri', 'DRV-104', '0771234504', 'H-9012346', 'RFID-8815', 5], // 2808 Harvester
        ['P.K. Nishantha', 'DRV-105', '0771234505', 'B-9012347', 'RFID-8816', 8], // 3407 Infielder
        ['N.H. Sarath Kumara', 'DRV-106', '0771234506', 'B-9012348', 'RFID-8817', 12], // DAH-8851 Bolero
        ['T.M. Gamini', 'DRV-107', '0771234507', 'E-9012349', 'RFID-8818', 13]  // Excavator
    ];
    $stmt = $pdo->prepare("INSERT INTO drivers (name, code, phone, license_no, rfid_code, assigned_unit_id) VALUES (?,?,?,?,?,?)");
    foreach ($drivers as $d) {
        $stmt->execute($d);
    }
    echo "Seeded " . count($drivers) . " drivers.\n";
}

// Seed sample notifications if empty
$notifCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
if ($notifCount === 0) {
    $notifs = [
        [2, 'geofence', 'Entered Geofence D/207/01', 'Unit 49-1665 entered cultivation sector D/207/01 (Hingurana)', 7.2095, 81.6575],
        [4, 'speed', 'Overspeeding Alert', 'Harvester 2806 exceeded speed threshold (24.5 km/h recorded in sector road)', 7.2140, 81.6620],
        [12, 'battery', 'Low Battery Warning', 'DAH-8851 (Bolero) battery dropped below 15%', 7.2010, 81.6510],
        [2, 'fuel', 'Fuel Filling Detected', 'Unit 49-1665 refueled +65 Liters at Hingurana Central Bowser', 7.2050, 81.6580],
        [3, 'idle', 'Excessive Idling Warning', 'Unit 49-3835 has been idling with ignition ON for > 20 mins', 7.2180, 81.6680],
        [2, 'sos', 'SOS Emergency Alarm Dispatched', 'Panic button pressed by operator Chandrasena', 7.2095, 81.6575]
    ];
    $stmt = $pdo->prepare("INSERT INTO notifications (unit_id, type, title, message, lat, lng) VALUES (?,?,?,?,?,?)");
    foreach ($notifs as $n) {
        $stmt->execute($n);
    }
    echo "Seeded " . count($notifs) . " notifications.\n";
}

echo "Database extended successfully for Wialon features.\n";
