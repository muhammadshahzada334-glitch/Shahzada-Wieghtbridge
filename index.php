<?php
session_start();
// ============================================================
// SHAHZADA WEIGHBRIDGE - PHP + MySQL
// Database: shahzada_wieghtbridge
// ============================================================

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli("localhost", "root", "", "shahzada_wieghtbridge");

if ($conn->connect_error) {
    die("Database connection failed: " . htmlspecialchars($conn->connect_error));
}


$conn->set_charset("utf8mb4");

/* ============================================================
   V5.2 BUSINESS AUTOMATION
   Universal Search + Daily Backup + Thermal Printing
   + Weighing Indicator integration point
   ============================================================ */
$backupDir = __DIR__ . DIRECTORY_SEPARATOR . "backups";
if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }

function wb_daily_backup($conn, $dir) {
    $file = $dir . DIRECTORY_SEPARATOR . "weighbridge_backup_" . date("Y-m-d") . ".sql";
    if (file_exists($file)) return;
    $out = "-- Shahzada Weighbridge automatic backup: " . date("Y-m-d H:i:s") . "\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    $tables = $conn->query("SHOW TABLES");
    if ($tables) while ($t = $tables->fetch_row()) {
        $table = $t[0];
        $safe = str_replace("`", "``", $table);
        $create = $conn->query("SHOW CREATE TABLE `$safe`");
        if ($create) {
            $c = $create->fetch_assoc();
            $out .= "DROP TABLE IF EXISTS `$safe`;\n" . ($c["Create Table"] ?? "") . ";\n\n";
        }
        $data = $conn->query("SELECT * FROM `$safe`");
        if ($data) while ($row = $data->fetch_assoc()) {
            $cols = []; $vals = [];
            foreach ($row as $k => $v) {
                $cols[] = "`" . str_replace("`", "``", $k) . "`";
                $vals[] = $v === null ? "NULL" : "'" . $conn->real_escape_string($v) . "'";
            }
            $out .= "INSERT INTO `$safe` (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ");\n";
        }
        $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    @file_put_contents($file, $out);
}
wb_daily_backup($conn, $backupDir);


/* ============================================================
   V5 PRODUCTION LAYER
   - users / roles
   - business settings
   - audit log
   - receipt/slip numbers
   ============================================================ */

$schemaStatements = [
"CREATE TABLE IF NOT EXISTS wb_users_v5 (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','operator') NOT NULL DEFAULT 'operator',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

"CREATE TABLE IF NOT EXISTS wb_settings_v5 (
    setting_key VARCHAR(80) NOT NULL,
    setting_value TEXT NOT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

"CREATE TABLE IF NOT EXISTS wb_audit_v5 (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    username VARCHAR(80) NOT NULL,
    action_name VARCHAR(100) NOT NULL,
    details TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
];

foreach ($schemaStatements as $schemaSql) {
    if (!$conn->query($schemaSql)) {
        die("V5 database setup failed: " . htmlspecialchars($conn->error));
    }
}

$recordColumns = [
    "slip_no" => "ALTER TABLE weighbridge_records_v2 ADD COLUMN slip_no VARCHAR(40) DEFAULT NULL",
    "created_by" => "ALTER TABLE weighbridge_records_v2 ADD COLUMN created_by VARCHAR(80) DEFAULT ''"
];
foreach ($recordColumns as $column => $alterSql) {
    $check = $conn->query("SHOW COLUMNS FROM weighbridge_records_v2 LIKE '" . $conn->real_escape_string($column) . "'");
    if ($check && $check->num_rows === 0) {
        $conn->query($alterSql);
    }
}

$defaultSettings = [
    "company_name" => "SHAHZADA WEIGHBRIDGE",
    "company_address" => "Sparco Road, Moach Goth, Karachi",
    "company_phone" => "",
    "company_whatsapp" => "",
    "company_footer" => "Thank you for using Shahzada Weighbridge",
    "maps_url" => "https://maps.app.goo.gl/iAgLsEYmTDkxgFuG9",
    "currency" => "KG"
];
foreach ($defaultSettings as $key => $value) {
    $stmt = $conn->prepare("INSERT IGNORE INTO wb_settings_v5 (setting_key, setting_value) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

function wb_setting($key, $default = "") {
    global $conn;
    $stmt = $conn->prepare("SELECT setting_value FROM wb_settings_v5 WHERE setting_key=? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? $row["setting_value"] : $default;
}

function wb_log($action, $details = "") {
    global $conn;
    $uid = isset($_SESSION["wb_user_id"]) ? (int)$_SESSION["wb_user_id"] : null;
    $username = $_SESSION["wb_username"] ?? "system";
    $stmt = $conn->prepare("INSERT INTO wb_audit_v5 (user_id, username, action_name, details) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isss", $uid, $username, $action, $details);
        $stmt->execute();
        $stmt->close();
    }
}

function wb_require_login() {
    if (empty($_SESSION["wb_authenticated"])) {
        http_response_code(401);
        echo json_encode(["success"=>false, "message"=>"Login required."]);
        exit;
    }
}

function wb_require_role($roles) {
    wb_require_login();
    $role = $_SESSION["wb_role"] ?? "";
    if (!in_array($role, (array)$roles, true)) {
        http_response_code(403);
        echo json_encode(["success"=>false, "message"=>"You do not have permission for this action."]);
        exit;
    }
}

function wb_generate_slip_no($id) {
    return "SW-" . date("Ymd") . "-" . str_pad((string)$id, 5, "0", STR_PAD_LEFT);
}

// V5 default accounts. Created only if the username does not already exist.
// Change all default passwords from Settings before real business use.
$defaultAccounts = [
    ["admin", "System Administrator", "Admin@12345", "admin"],
    ["manager", "Business Manager", "Manager@12345", "manager"],
    ["operator", "Weighbridge Operator", "Operator@12345", "operator"]
];

foreach ($defaultAccounts as $account) {
    [$u, $n, $p, $r] = $account;

    $check = $conn->prepare("SELECT id FROM wb_users_v5 WHERE username=? LIMIT 1");
    if ($check) {
        $check->bind_param("s", $u);
        $check->execute();
        $exists = $check->get_result();
        $alreadyExists = $exists && $exists->num_rows > 0;
        $check->close();

        if (!$alreadyExists) {
            $hash = password_hash($p, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO wb_users_v5 (username, full_name, password_hash, role, active) VALUES (?, ?, ?, ?, 1)");
            if ($stmt) {
                $stmt->bind_param("ssss", $u, $n, $hash, $r);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}



// Create our own table automatically.
// This does NOT delete or change your existing customers/vehicles/weighments tables.
$createTable = "CREATE TABLE IF NOT EXISTS weighbridge_records_v2 (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    truck_number VARCHAR(100) NOT NULL,
    driver_name VARCHAR(150) NOT NULL,
    gross_weight DECIMAL(12,2) NOT NULL,
    tare_weight DECIMAL(12,2) NOT NULL,
    deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_weight DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if (!$conn->query($createTable)) {
    die("Could not create records table: " . htmlspecialchars($conn->error));
}
if (!$conn->query("ALTER TABLE weighbridge_records_v2 ADD COLUMN customer VARCHAR(180) DEFAULT ''")) {
    // Ignore duplicate-column errors; this keeps existing V2/V3 databases compatible.
    if (stripos($conn->error, "duplicate column") === false) {
        // Do not stop the whole application for an older schema; reports will still work on compatible schemas.
    }
}


$createCustomers = "CREATE TABLE IF NOT EXISTS wb_customers_v2 (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_name VARCHAR(180) NOT NULL,
    company VARCHAR(180) DEFAULT '',
    phone VARCHAR(50) DEFAULT '',
    address VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
if (!$conn->query($createCustomers)) {
    die("Could not create customers table: " . htmlspecialchars($conn->error));
}

$createVehicles = "CREATE TABLE IF NOT EXISTS wb_vehicles_v2 (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    truck_number VARCHAR(100) NOT NULL UNIQUE,
    owner_name VARCHAR(180) DEFAULT '',
    default_driver VARCHAR(150) DEFAULT '',
    phone VARCHAR(50) DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
if (!$conn->query($createVehicles)) {
    die("Could not create vehicles table: " . htmlspecialchars($conn->error));
}


// Save record through normal HTML POST.


// Reports API / CSV export.
if ($_SERVER["REQUEST_METHOD"] === "GET" && ($_GET["action"] ?? "") === "report_data") {
    wb_require_role(["admin","manager"]);
    header("Content-Type: application/json; charset=utf-8");

    $from = trim($_GET["from"] ?? "");
    $to = trim($_GET["to"] ?? "");
    $truck = trim($_GET["truck"] ?? "");
    $customer = trim($_GET["customer"] ?? "");

    $where = [];
    $params = [];
    $types = "";

    if ($from !== "") { $where[] = "DATE(created_at) >= ?"; $params[] = $from; $types .= "s"; }
    if ($to !== "") { $where[] = "DATE(created_at) <= ?"; $params[] = $to; $types .= "s"; }
    if ($truck !== "") { $where[] = "truck_number LIKE ?"; $params[] = "%".$truck."%"; $types .= "s"; }
    if ($customer !== "") { $where[] = "customer LIKE ?"; $params[] = "%".$customer."%"; $types .= "s"; }

    $sql = "SELECT id, slip_no, truck_number, driver_name, customer, gross_weight, tare_weight, deduction, net_weight, created_at
            FROM weighbridge_records_v2";
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["success"=>false, "message"=>$conn->error]);
        exit;
    }
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $gross = $tare = $deduction = $net = 0;
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
        $gross += (float)$r["gross_weight"];
        $tare += (float)$r["tare_weight"];
        $deduction += (float)$r["deduction"];
        $net += (float)$r["net_weight"];
    }
    $stmt->close();

    echo json_encode([
        "success"=>true,
        "rows"=>$rows,
        "summary"=>[
            "trips"=>count($rows),
            "gross"=>$gross,
            "tare"=>$tare,
            "deduction"=>$deduction,
            "net"=>$net,
            "vehicles"=>count(array_unique(array_map(fn($r)=>$r["truck_number"], $rows))),
            "customers"=>count(array_filter(array_unique(array_map(fn($r)=>trim($r["customer"] ?? ""), $rows))))
        ]
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "GET" && ($_GET["action"] ?? "") === "export_csv") {
    wb_require_role(["admin","manager"]);
    $from = trim($_GET["from"] ?? "");
    $to = trim($_GET["to"] ?? "");
    $truck = trim($_GET["truck"] ?? "");
    $customer = trim($_GET["customer"] ?? "");

    $where = [];
    $params = [];
    $types = "";
    if ($from !== "") { $where[] = "DATE(created_at) >= ?"; $params[] = $from; $types .= "s"; }
    if ($to !== "") { $where[] = "DATE(created_at) <= ?"; $params[] = $to; $types .= "s"; }
    if ($truck !== "") { $where[] = "truck_number LIKE ?"; $params[] = "%".$truck."%"; $types .= "s"; }
    if ($customer !== "") { $where[] = "customer LIKE ?"; $params[] = "%".$customer."%"; $types .= "s"; }

    $sql = "SELECT id, slip_no, truck_number, driver_name, customer, gross_weight, tare_weight, deduction, net_weight, created_at
            FROM weighbridge_records_v2";
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) die("Export failed: " . $conn->error);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=shahzada-weighbridge-report-" . date("Y-m-d-H-i") . ".csv");
    $out = fopen("php://output", "w");
    fputcsv($out, ["ID","Truck Number","Driver","Customer","Gross KG","Tare KG","Deduction KG","Net KG","Date"]);
    while ($r = $result->fetch_assoc()) {
        fputcsv($out, [$r["id"],$r["truck_number"],$r["driver_name"],$r["customer"],$r["gross_weight"],$r["tare_weight"],$r["deduction"],$r["net_weight"],$r["created_at"]]);
    }
    fclose($out);
    $stmt->close();
    exit;
}




/* ---------------- V5 AUTHENTICATION ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "login") {
    header("Content-Type: application/json; charset=utf-8");
    $username = trim($_POST["username"] ?? "");
    $password = (string)($_POST["password"] ?? "");

    $stmt = $conn->prepare("SELECT id, username, full_name, password_hash, role, active FROM wb_users_v5 WHERE username=? LIMIT 1");
    if (!$stmt) {
        echo json_encode(["success"=>false, "message"=>"Login service unavailable."]);
        exit;
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$user || !(int)$user["active"] || !password_verify($password, $user["password_hash"])) {
        echo json_encode(["success"=>false, "message"=>"Invalid username or password."]);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION["wb_authenticated"] = true;
    $_SESSION["wb_user_id"] = (int)$user["id"];
    $_SESSION["wb_username"] = $user["username"];
    $_SESSION["wb_full_name"] = $user["full_name"];
    $_SESSION["wb_role"] = $user["role"];

    $stmt = $conn->prepare("UPDATE wb_users_v5 SET last_login=NOW() WHERE id=?");
    if ($stmt) {
        $uid = (int)$user["id"];
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
    }
    wb_log("LOGIN", "Successful login");
    echo json_encode(["success"=>true, "message"=>"Login successful."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "GET" && ($_GET["action"] ?? "") === "logout") {
    if (!empty($_SESSION["wb_authenticated"])) wb_log("LOGOUT", "User logged out");
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), "", time()-42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: " . strtok($_SERVER["REQUEST_URI"], "?"));
    exit;
}

/* Login gate for all business data / API actions. */
if (empty($_SESSION["wb_authenticated"])) {
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "login") {
        // handled above
    } elseif (($_GET["action"] ?? "") === "logout") {
        // handled above
    } else {
        $company = htmlspecialchars(wb_setting("company_name", "SHAHZADA WEIGHBRIDGE"));
        ?>
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width,initial-scale=1">
          <title><?= $company ?> — Secure Login</title>
          <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
          <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
          <style>
            body{min-height:100vh;background:radial-gradient(circle at top,#243047,#080b12 55%);display:grid;place-items:center;font-family:Arial,sans-serif}
            .login-card{width:min(440px,92vw);background:#fff;border-radius:28px;padding:34px;box-shadow:0 30px 80px rgba(0,0,0,.35)}
            .brand{width:70px;height:70px;border-radius:20px;background:#111827;color:#f4c430;display:grid;place-items:center;font-size:30px;margin:auto}
            .btn-gold{background:#f4c430;border:0;color:#111827;font-weight:800;padding:13px;border-radius:12px}
            .form-control{padding:13px;border-radius:12px}
          </style>
        </head>
        <body>

<div class="container-fluid px-3">
  <div class="alert alert-dark border-0 rounded-0 mb-0 text-center small">
    <i class="fas fa-shield-halved text-warning me-2"></i>
    <strong>V6 PROFESSIONAL WORKFLOW MODE</strong> — <?= htmlspecialchars($_SESSION["wb_full_name"] ?? "") ?> · <?= htmlspecialchars(strtoupper($_SESSION["wb_role"] ?? "")) ?>
    <span class="mx-2">•</span> Database Protected
    <span class="mx-2">•</span> Audit Logging Enabled
  </div>
</div>

          <div class="login-card">
            <div class="brand"><i class="fas fa-scale-balanced"></i></div>
            <h3 class="text-center fw-bold mt-3 mb-1"><?= $company ?></h3>
            <p class="text-center text-muted mb-4">Secure Business Login</p>
            <form id="v5Login">
              <label class="form-label fw-semibold">Username</label>
              <input name="username" class="form-control mb-3" value="admin" autocomplete="username" required>
              <label class="form-label fw-semibold">Password</label>
              <input name="password" type="password" class="form-control mb-3" placeholder="Enter password" autocomplete="current-password" required>
              <button class="btn btn-gold w-100" type="submit"><i class="fas fa-right-to-bracket me-2"></i>Sign In</button>
              <div id="loginMsg" class="small text-center mt-3"></div>
            </form>
            <div class="small text-muted text-center mt-4">V6 Professional Edition</div>
          </div>
          <script>
          document.getElementById("v5Login").addEventListener("submit",async e=>{
            e.preventDefault();
            const msg=document.getElementById("loginMsg");
            msg.textContent="Signing in...";
            const fd=new FormData(e.target); fd.append("action","login");
            try{
              const r=await fetch(window.location.href,{method:"POST",body:fd});
              const d=await r.json();
              if(!d.success) throw new Error(d.message);
              location.reload();
            }catch(err){msg.textContent="✗ "+err.message;msg.className="small text-center mt-3 text-danger";}
          });
          </script>
        
<!-- V5.2 SMART TOOLS -->
<section class="card" id="smartTools" style="margin-top:18px;">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <div><h2 style="margin:0">🔍 Universal Search</h2><div class="small-muted">Search slip, truck, driver or customer from one place.</div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button type="button" class="btn btn-secondary" onclick="location.href='?wb_action=download_backup'">💾 Today's Backup</button>
      <button type="button" class="btn btn-primary" onclick="wbOpenSearch()">Search Records</button>
    </div>
  </div>
  <div id="wbSearchBox" style="display:none;margin-top:12px">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <input id="wbQ" class="form-control" style="flex:1;min-width:240px" placeholder="Slip no / truck / driver / customer">
      <button type="button" class="btn btn-primary" onclick="wbSearch()">Search</button>
    </div>
    <div id="wbResults" style="margin-top:12px"></div>
  </div>
</section>

<section class="card" style="margin-top:18px;">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <div><h2 style="margin:0">🖨️ Thermal Printer Mode</h2><div class="small-muted">Professional receipt layout for 58mm / 80mm thermal printers.</div></div>
    <div>
      <button type="button" class="btn btn-secondary" onclick="wbThermal('80mm')">80mm</button>
      <button type="button" class="btn btn-secondary" onclick="wbThermal('58mm')">58mm</button>
    </div>
  </div>
</section>

<section class="card" style="margin-top:18px;">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <div><h2 style="margin:0">⚖️ Weighing Indicator</h2><div class="small-muted">Use a live reading supplied by a USB/RS-232 bridge application.</div></div>
    <div style="display:flex;gap:8px;align-items:center">
      <input id="wbIndicator" type="number" step="0.01" class="form-control" style="width:170px" placeholder="Weight KG">
      <button type="button" class="btn btn-primary" onclick="wbUseWeight()">Use Reading</button>
    </div>
  </div>
  <div class="small-muted" style="margin-top:7px;">Direct scale communication depends on the actual indicator model/driver; this version provides the safe browser-side integration point.</div>
</section>

<style>
@media print{body.wb58{width:58mm!important}body.wb80{width:80mm!important}}
.wbResult{display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr .8fr .8fr .8fr auto;gap:6px;padding:8px 0;border-bottom:1px solid #ddd;align-items:center}
@media(max-width:900px){.wbResult{grid-template-columns:1fr 1fr}}
</style>

<script>
function wbOpenSearch(){document.getElementById('wbSearchBox').style.display='block';document.getElementById('wbQ').focus();}
function wbEsc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
async function wbSearch(){
 const q=document.getElementById('wbQ').value.trim(), box=document.getElementById('wbResults');
 if(!q){box.innerHTML='<div class="small-muted">Enter something to search.</div>';return;}
 box.innerHTML='<div class="small-muted">Searching...</div>';
 try{
  const r=await fetch('?wb_action=universal_search&q='+encodeURIComponent(q),{cache:'no-store'}), d=await r.json();
  if(!d.rows?.length){box.innerHTML='<div class="small-muted">No matching records found.</div>';return;}
  box.innerHTML=d.rows.map(x=>`<div class="wbResult"><b>${wbEsc(x.slip)}</b><span>${wbEsc(x.truck)}</span><span>${wbEsc(x.driver)}</span><span>${wbEsc(x.customer)}</span><span>${wbEsc(x.gross)}</span><span>${wbEsc(x.tare)}</span><b>${wbEsc(x.net)}</b><button class="btn btn-primary" type="button" onclick='wbPrint(${JSON.stringify(x)})'>🖨️ Reprint</button></div>`).join('');
 }catch(e){box.innerHTML='<div class="small-muted">Search failed. Check Apache/PHP and refresh.</div>';}
}
function wbThermal(w){document.body.classList.remove('wb58','wb80');document.body.classList.add(w==='58mm'?'wb58':'wb80');localStorage.setItem('wbThermalWidth',w);alert('Thermal print width set to '+w+'.');}
(function(){const w=localStorage.getItem('wbThermalWidth');if(w)document.body.classList.add(w==='58mm'?'wb58':'wb80');})();
function wbPrint(x){
 const width=localStorage.getItem('wbThermalWidth')||'80mm';
 const h=`<!doctype html><html><head><meta charset="utf-8"><style>@page{size:${width} auto;margin:3mm}body{font:12px Arial;width:${width};margin:0;padding:3mm;box-sizing:border-box}h2{text-align:center;font-size:17px;margin:0 0 8px}.r{display:flex;justify-content:space-between;margin:5px 0}.net{font-size:15px;font-weight:bold}.line{border-top:1px dashed #000;margin:7px 0}.c{text-align:center}</style></head><body><h2>SHAHZADA WEIGHBRIDGE</h2><div class="c">${wbEsc(x.slip)}</div><div class="line"></div><div class="r"><span>Truck</span><b>${wbEsc(x.truck)}</b></div><div class="r"><span>Driver</span><b>${wbEsc(x.driver)}</b></div><div class="r"><span>Customer</span><b>${wbEsc(x.customer)}</b></div><div class="line"></div><div class="r"><span>Gross</span><b>${wbEsc(x.gross)} KG</b></div><div class="r"><span>Tare</span><b>${wbEsc(x.tare)} KG</b></div><div class="r net"><span>Net</span><b>${wbEsc(x.net)} KG</b></div><div class="line"></div><div class="c">${wbEsc(x.date)}</div></body></html>`;
 const w=window.open('','_blank','width=420,height=650');if(!w){alert('Allow pop-ups for localhost.');return;}w.document.write(h);w.document.close();w.focus();setTimeout(()=>w.print(),250);
}
function wbUseWeight(){
 const v=document.getElementById('wbIndicator').value;if(v===''){alert('Enter the indicator reading.');return;}
 const ids=['gross_weight','grossWeight','gross','grossWeightInput'];
 for(const id of ids){const e=document.getElementById(id);if(e){e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return;}}
 const n=[...document.querySelectorAll('input[type="number"]')];if(n.length)n[0].value=v;else alert('Gross weight field not found.');
}
</script>

</body>
        </html>
        <?php
        exit;
    }
}

/* ---------------- V5 SETTINGS ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_settings") {
    header("Content-Type: application/json; charset=utf-8");
    wb_require_role(["admin"]);
    $allowed = ["company_name","company_address","company_phone","company_whatsapp","company_footer","maps_url"];
    foreach ($allowed as $key) {
        if (isset($_POST[$key])) {
            $value = trim((string)$_POST[$key]);
            $stmt = $conn->prepare("INSERT INTO wb_settings_v5 (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            if ($stmt) {
                $stmt->bind_param("ss", $key, $value);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    wb_log("SETTINGS_UPDATE", "Business settings updated");
    echo json_encode(["success"=>true,"message"=>"Settings saved successfully."]);
    exit;
}

/* ---------------- V5 PASSWORD ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "change_password") {
    header("Content-Type: application/json; charset=utf-8");
    wb_require_login();
    $current = (string)($_POST["current_password"] ?? "");
    $new = (string)($_POST["new_password"] ?? "");
    if (strlen($new) < 8) {
        echo json_encode(["success"=>false,"message"=>"New password must be at least 8 characters."]);
        exit;
    }
    $uid = (int)$_SESSION["wb_user_id"];
    $stmt = $conn->prepare("SELECT password_hash FROM wb_users_v5 WHERE id=?");
    $stmt->bind_param("i",$uid); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$user || !password_verify($current,$user["password_hash"])) {
        echo json_encode(["success"=>false,"message"=>"Current password is incorrect."]);
        exit;
    }
    $hash = password_hash($new,PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE wb_users_v5 SET password_hash=? WHERE id=?");
    $stmt->bind_param("si",$hash,$uid); $ok=$stmt->execute(); $stmt->close();
    if($ok) wb_log("PASSWORD_CHANGE","User changed own password");
    echo json_encode(["success"=>$ok,"message"=>$ok?"Password changed successfully.":"Could not change password."]);
    exit;
}

/* ---------------- V5 USER MANAGEMENT ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "add_user") {
    header("Content-Type: application/json; charset=utf-8");
    wb_require_role(["admin"]);
    $username=trim($_POST["username"]??"");
    $full=trim($_POST["full_name"]??"");
    $role=$_POST["role"]??"operator";
    $password=(string)($_POST["password"]??"");
    if(!preg_match('/^[A-Za-z0-9_.-]{3,80}$/',$username) || $full==="" || strlen($password)<8 || !in_array($role,["admin","manager","operator"],true)){
        echo json_encode(["success"=>false,"message"=>"Enter valid user details. Password must be 8+ characters."]); exit;
    }
    $hash=password_hash($password,PASSWORD_DEFAULT);
    $stmt=$conn->prepare("INSERT INTO wb_users_v5 (username,full_name,password_hash,role) VALUES (?,?,?,?)");
    if(!$stmt){echo json_encode(["success"=>false,"message"=>$conn->error]);exit;}
    $stmt->bind_param("ssss",$username,$full,$hash,$role);
    $ok=$stmt->execute(); $err=$stmt->error; $stmt->close();
    if($ok) wb_log("USER_CREATE","Created user ".$username." (".$role.")");
    echo json_encode(["success"=>$ok,"message"=>$ok?"User created successfully.":("Could not create user: ".$err)]); exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "toggle_user") {
    header("Content-Type: application/json; charset=utf-8");
    wb_require_role(["admin"]);
    $id=(int)($_POST["id"]??0);
    if($id === (int)$_SESSION["wb_user_id"]){echo json_encode(["success"=>false,"message"=>"You cannot deactivate your own account."]);exit;}
    $stmt=$conn->prepare("UPDATE wb_users_v5 SET active=IF(active=1,0,1) WHERE id=?");
    $stmt->bind_param("i",$id); $ok=$stmt->execute(); $stmt->close();
    if($ok) wb_log("USER_STATUS","Toggled user ID ".$id);
    echo json_encode(["success"=>$ok,"message"=>$ok?"User status updated.":"Could not update user."]); exit;
}

/* ---------------- V5 BACKUP ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "GET" && ($_GET["action"] ?? "") === "backup_json") {
    wb_require_role(["admin"]);
    $payload = [
        "backup_version"=>"V5",
        "created_at"=>date("c"),
        "business_settings"=>[],
        "customers"=>[],
        "vehicles"=>[],
        "weighments"=>[],
        "users"=>[]
    ];
    $s=$conn->query("SELECT setting_key,setting_value FROM wb_settings_v5");
    while($s && ($r=$s->fetch_assoc())) $payload["business_settings"][$r["setting_key"]]=$r["setting_value"];
    foreach([
        "customers"=>"SELECT * FROM wb_customers_v2 ORDER BY id",
        "vehicles"=>"SELECT * FROM wb_vehicles_v2 ORDER BY id",
        "weighments"=>"SELECT * FROM weighbridge_records_v2 ORDER BY id",
        "users"=>"SELECT id,username,full_name,role,active,created_at,last_login FROM wb_users_v5 ORDER BY id"
    ] as $k=>$q){
        $res=$conn->query($q);
        while($res && ($r=$res->fetch_assoc())) $payload[$k][]=$r;
    }
    wb_log("BACKUP_EXPORT","JSON business backup exported");
    header("Content-Type: application/json; charset=utf-8");
    header("Content-Disposition: attachment; filename=shahzada-weighbridge-backup-".date("Y-m-d-H-i-s").".json");
    echo json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}


// Customer management.
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "add_customer") {
    wb_require_role(["admin","manager"]);
    header("Content-Type: application/json; charset=utf-8");
    $name = trim($_POST["customer_name"] ?? "");
    $company = trim($_POST["company"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $address = trim($_POST["address"] ?? "");

    if ($name === "") {
        echo json_encode(["success" => false, "message" => "Customer name is required."]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO wb_customers_v2 (customer_name, company, phone, address) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "Customer prepare failed: " . $conn->error]);
        exit;
    }
    $stmt->bind_param("ssss", $name, $company, $phone, $address);
    $ok = $stmt->execute();
    echo json_encode($ok
        ? ["success" => true, "message" => "Customer added successfully.", "id" => $stmt->insert_id]
        : ["success" => false, "message" => "Customer save failed: " . $stmt->error]);
    $stmt->close();
    exit;
}

// Customer edit / delete.
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "update_customer") {
    wb_require_role(["admin","manager"]); header("Content-Type: application/json; charset=utf-8");
    $id=(int)($_POST["id"]??0); $name=trim($_POST["customer_name"]??""); $company=trim($_POST["company"]??""); $phone=trim($_POST["phone"]??""); $address=trim($_POST["address"]??"");
    if($id<=0 || $name===""){ echo json_encode(["success"=>false,"message"=>"Customer name is required."]); exit; }
    $stmt=$conn->prepare("UPDATE wb_customers_v2 SET customer_name=?, company=?, phone=?, address=? WHERE id=?");
    if(!$stmt){ echo json_encode(["success"=>false,"message"=>$conn->error]); exit; }
    $stmt->bind_param("ssssi",$name,$company,$phone,$address,$id); $ok=$stmt->execute(); $err=$stmt->error; $stmt->close();
    if($ok) wb_log("CUSTOMER_UPDATE","Updated customer ID ".$id);
    echo json_encode(["success"=>$ok,"message"=>$ok?"Customer updated successfully.":"Could not update customer: ".$err]); exit;
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete_customer") {
    wb_require_role(["admin","manager"]); header("Content-Type: application/json; charset=utf-8");
    $id=(int)($_POST["id"]??0); if($id<=0){echo json_encode(["success"=>false,"message"=>"Invalid customer."]);exit;}
    $stmt=$conn->prepare("DELETE FROM wb_customers_v2 WHERE id=?"); if(!$stmt){echo json_encode(["success"=>false,"message"=>$conn->error]);exit;}
    $stmt->bind_param("i",$id); $ok=$stmt->execute(); $err=$stmt->error; $stmt->close();
    if($ok) wb_log("CUSTOMER_DELETE","Deleted customer ID ".$id);
    echo json_encode(["success"=>$ok,"message"=>$ok?"Customer deleted successfully.":"Could not delete customer: ".$err]); exit;
}

// Vehicle management — create / edit / delete.
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_vehicle") {
    wb_require_role(["admin","manager"]); header("Content-Type: application/json; charset=utf-8");
    $id=(int)($_POST["id"]??0); $truck=trim($_POST["truck_number"]??""); $owner=trim($_POST["owner_name"]??""); $driver=trim($_POST["default_driver"]??""); $phone=trim($_POST["phone"]??"");
    if($truck===""){echo json_encode(["success"=>false,"message"=>"Truck number is required."]);exit;}
    if($id>0){
        $stmt=$conn->prepare("UPDATE wb_vehicles_v2 SET truck_number=?, owner_name=?, default_driver=?, phone=? WHERE id=?");
        if(!$stmt){echo json_encode(["success"=>false,"message"=>$conn->error]);exit;}
        $stmt->bind_param("ssssi",$truck,$owner,$driver,$phone,$id); $ok=$stmt->execute(); $err=$stmt->error; $stmt->close();
        if($ok) wb_log("VEHICLE_UPDATE","Updated vehicle ".$truck);
        echo json_encode(["success"=>$ok,"message"=>$ok?"Vehicle updated successfully.":"Could not update vehicle: ".$err]);exit;
    }
    $stmt=$conn->prepare("INSERT INTO wb_vehicles_v2 (truck_number, owner_name, default_driver, phone) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE owner_name=VALUES(owner_name), default_driver=VALUES(default_driver), phone=VALUES(phone)");
    if(!$stmt){echo json_encode(["success"=>false,"message"=>$conn->error]);exit;}
    $stmt->bind_param("ssss",$truck,$owner,$driver,$phone); $ok=$stmt->execute(); $err=$stmt->error; $stmt->close();
    if($ok) wb_log("VEHICLE_SAVE","Saved vehicle ".$truck);
    echo json_encode(["success"=>$ok,"message"=>$ok?"Vehicle saved successfully.":"Vehicle save failed: ".$err]);exit;
}
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "delete_vehicle") {
    wb_require_role(["admin","manager"]); header("Content-Type: application/json; charset=utf-8");
    $id=(int)($_POST["id"]??0); if($id<=0){echo json_encode(["success"=>false,"message"=>"Invalid vehicle."]);exit;}
    $truck=""; $q=$conn->prepare("SELECT truck_number FROM wb_vehicles_v2 WHERE id=? LIMIT 1");
    if($q){$q->bind_param("i",$id);$q->execute();$r=$q->get_result()->fetch_assoc();$truck=$r["truck_number"]??"";$q->close();}
    $stmt=$conn->prepare("DELETE FROM wb_vehicles_v2 WHERE id=?"); if(!$stmt){echo json_encode(["success"=>false,"message"=>$conn->error]);exit;}
    $stmt->bind_param("i",$id); $ok=$stmt->execute(); $err=$stmt->error; $stmt->close();
    if($ok) wb_log("VEHICLE_DELETE","Deleted vehicle ".$truck);
    echo json_encode(["success"=>$ok,"message"=>$ok?"Vehicle deleted successfully. History records remain safe.":"Could not delete vehicle: ".$err]);exit;
}

// Keeping this in the same file makes setup simple: only one PHP file is needed.
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_record") {
    wb_require_role(["admin","manager","operator"]);
    header("Content-Type: application/json; charset=utf-8");

    $truck = trim($_POST["truck"] ?? "");
    $driver = trim($_POST["driver"] ?? "");
    $customer = trim($_POST["customer"] ?? "");
    $gross = (float)($_POST["gross"] ?? 0);
    $tare = (float)($_POST["tare"] ?? 0);
    $deduction = (float)($_POST["deduction"] ?? 0);

    if ($truck === "" || $driver === "") {
        echo json_encode(["success" => false, "message" => "Truck number and driver name are required."]);
        exit;
    }

    if ($gross <= 0 || $tare < 0 || $deduction < 0) {
        echo json_encode(["success" => false, "message" => "Please enter valid weight values."]);
        exit;
    }

    if ($tare > $gross) {
        echo json_encode(["success" => false, "message" => "Tare weight cannot be greater than gross weight."]);
        exit;
    }

    $net = $gross - $tare - $deduction;

    if ($net < 0) {
        echo json_encode(["success" => false, "message" => "Deduction is too high; net weight cannot be negative."]);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO weighbridge_records_v2
        (truck_number, driver_name, customer, gross_weight, tare_weight, deduction, net_weight, slip_no, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "Prepare failed: " . $conn->error]);
        exit;
    }

    $slipNo = "PENDING-" . bin2hex(random_bytes(4));
    $createdBy = $_SESSION["wb_username"] ?? "system";
    $stmt->bind_param("sssddddss", $truck, $driver, $customer, $gross, $tare, $deduction, $net, $slipNo, $createdBy);

    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        $slipNo = wb_generate_slip_no($newId);
        $up = $conn->prepare("UPDATE weighbridge_records_v2 SET slip_no=? WHERE id=?");
        if ($up) { $up->bind_param("si", $slipNo, $newId); $up->execute(); $up->close(); }
        wb_log("WEIGHMENT_SAVE", "Saved ".$slipNo." / Truck ".$truck." / Net ".$net." KG");
        echo json_encode([
            "success" => true,
            "message" => "Record saved successfully.",
            "id" => $newId,
            "slip_no" => $slipNo,
            "created_by" => $createdBy,
            "date" => date("d/m/Y h:i:s A"),
            "truck" => $truck,
            "driver" => $driver,
            "customer" => $customer,
            "gross" => $gross,
            "tare" => $tare,
            "deduction" => $deduction,
            "net" => $net
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Database save failed: " . $stmt->error]);
    }

    $stmt->close();
    exit;
}

// Delete all records — admin only.
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "clear_records") {
    header("Content-Type: application/json; charset=utf-8");
    wb_require_role(["admin"]);
    if ($conn->query("TRUNCATE TABLE weighbridge_records_v2")) {
        wb_log("RECORDS_CLEAR","All weighment records cleared by admin");
        echo json_encode(["success" => true, "message" => "All records deleted."]);
    } else {
        echo json_encode(["success" => false, "message" => "Could not delete records: " . $conn->error]);
    }
    exit;
}

// Load saved records for the table.

$customers = [];
$customerResult = $conn->query("SELECT id, customer_name, company, phone, address FROM wb_customers_v2 ORDER BY id DESC");
if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) $customers[] = $row;
}

$vehicles = [];
$vehicleResult = $conn->query("SELECT id, truck_number, owner_name, default_driver, phone FROM wb_vehicles_v2 ORDER BY id DESC");
if ($vehicleResult) {
    while ($row = $vehicleResult->fetch_assoc()) $vehicles[] = $row;
}

$records = [];
$result = $conn->query(
    "SELECT id, truck_number, driver_name, gross_weight, tare_weight, deduction, net_weight, created_at
     FROM weighbridge_records_v2 ORDER BY id DESC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
}

$latestRecord = null;
if (!empty($records)) {
    $latestRecord = $records[0];
}

// V6 operator-friendly period statistics.
$todayStart = date("Y-m-d 00:00:00");
$weekStart = date("Y-m-d 00:00:00", strtotime("monday this week"));
$monthStart = date("Y-m-01 00:00:00");
$v6Stats = ["today"=>["trips"=>0,"net"=>0], "week"=>["trips"=>0,"net"=>0], "month"=>["trips"=>0,"net"=>0]];
if ($st = $conn->prepare("SELECT COUNT(*) trips, COALESCE(SUM(net_weight),0) net FROM weighbridge_records_v2 WHERE created_at >= ?")) {
    foreach (["today"=>$todayStart,"week"=>$weekStart,"month"=>$monthStart] as $key=>$start) {
        $st->bind_param("s", $start);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $v6Stats[$key] = ["trips"=>(int)($r["trips"]??0),"net"=>(float)($r["net"]??0)];
    }
    $st->close();
}
?>

<?php
if (isset($_GET["wb_action"]) && $_GET["wb_action"] === "download_backup") {
    $file = $backupDir . DIRECTORY_SEPARATOR . "weighbridge_backup_" . date("Y-m-d") . ".sql";
    if (!file_exists($file)) { http_response_code(404); exit("Backup not found."); }
    header("Content-Type: application/sql");
    header("Content-Disposition: attachment; filename=\"" . basename($file) . "\"");
    readfile($file);
    exit;
}

if (isset($_GET["wb_action"]) && $_GET["wb_action"] === "universal_search") {
    header("Content-Type: application/json; charset=utf-8");
    $q = trim($_GET["q"] ?? "");
    if ($q === "") { echo json_encode(["ok"=>true,"rows"=>[]]); exit; }
    $like = "%" . $q . "%"; $rows = [];

    foreach (["weighbridge_records_v2","weighments"] as $table) {
        $ex = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        if (!$ex || !$ex->num_rows) continue;
        $cr = $conn->query("SHOW COLUMNS FROM `$table`");
        if (!$cr) continue;
        $cols = [];
        while ($c = $cr->fetch_assoc()) $cols[] = $c["Field"];
        $pick = function($a) use ($cols) {
            foreach ($a as $x) if (in_array($x, $cols, true)) return $x;
            return null;
        };
        $slip=$pick(["slip_no","slip_number","receipt_no","slip_no"]);
        $truck=$pick(["truck_number","vehicle_number","truck_no","vehicle_no"]);
        $driver=$pick(["driver_name","driver"]);
        $customer=$pick(["customer_name","customer"]);
        $gross=$pick(["gross_weight","gross"]);
        $tare=$pick(["tare_weight","tare"]);
        $net=$pick(["net_weight","net"]);
        $date=$pick(["created_at","date","weighment_date","created_on"]);
        $searchCols=array_values(array_filter([$slip,$truck,$driver,$customer]));
        if (!$searchCols) continue;

        $where=implode(" OR ",array_map(fn($c)=>"`$c` LIKE ?", $searchCols));
        $sql="SELECT * FROM `$table` WHERE $where";
        if($date) $sql.=" ORDER BY `$date` DESC";
        $sql.=" LIMIT 50";
        $st=$conn->prepare($sql);
        if(!$st) continue;
        $types=str_repeat("s",count($searchCols));
        $params=array_fill(0,count($searchCols),$like);
        $st->bind_param($types,...$params);
        if($st->execute()){
            $rs=$st->get_result();
            while($r=$rs->fetch_assoc()){
                $rows[]=[
                    "slip"=>$slip?($r[$slip]??""):("WB-".($r["id"]??"")),
                    "truck"=>$truck?($r[$truck]??""):"",
                    "driver"=>$driver?($r[$driver]??""):"",
                    "customer"=>$customer?($r[$customer]??""):"",
                    "gross"=>$gross?($r[$gross]??""):"",
                    "tare"=>$tare?($r[$tare]??""):"",
                    "net"=>$net?($r[$net]??""):"",
                    "date"=>$date?($r[$date]??""):""
                ];
            }
        }
        $st->close();
    }
    echo json_encode(["ok"=>true,"rows"=>$rows]); exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shahzada Weighbridge | 200 Ton Digital | Sparco Road Karachi</title>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;900&family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">

<style>
:root {
    --primary-gold:#ffc107;
    --dark-metal:#0d0d0d;
    --wa-green:#25d366;
}
html { scroll-behavior:smooth; }
body {
    font-family:'Poppins',sans-serif;
    background:#ffc107;
    color:#111;
}
.navbar {
    background:var(--dark-metal)!important;
    border-bottom:3px solid var(--primary-gold);
    padding:5px 0;
}
.nav-logo { height:60px; width:auto; }
.nav-link { font-weight:600; color:#fff!important; }
.nav-link:hover { color:var(--primary-gold)!important; }
.hero-section {
    min-height:90vh;
    background:linear-gradient(rgba(0,0,0,.8),rgba(0,0,0,.8)),
    url('https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=1600&q=80');
    background-size:cover;
    background-position:center;
    display:flex;
    align-items:center;
    color:#fff;
    text-align:center;
}
.hero-title { font-family:'Montserrat',sans-serif; font-size:calc(2.2rem + 2vw); font-weight:900; }
.gold-text { color:var(--primary-gold); }
.section-padding { padding:80px 0; }
.calc-wrapper {
    background:#1a1a1a;
    color:#fff;
    padding:40px;
    border-radius:20px;
    border-left:8px solid var(--primary-gold);
}
.form-control-custom {
    min-height:52px;
    border-radius:12px;
}
.map-wrapper {
    border-radius:20px;
    overflow:hidden;
    border:3px solid #eee;
    height:450px;
}
.btn-wa-direct {
    background:var(--wa-green);
    color:#fff;
    padding:15px 30px;
    border-radius:50px;
    font-weight:700;
    text-decoration:none;
    display:inline-flex;
    align-items:center;
}
.floating-wa {
    position:fixed;
    bottom:30px;
    right:30px;
    background:var(--wa-green);
    color:#fff;
    width:60px;
    height:60px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:30px;
    z-index:1000;
}
.records-card {
    background:#1c1c1c;
    border-radius:15px;
    padding:20px;
    color:#fff;
}
.table { color:#fff; }
.small-muted { opacity:.7; font-size:.9rem; }
@media print {
    body * { visibility:hidden!important; }
    #printSlip, #printSlip * { visibility:visible!important; }
    #printSlip {
        position:absolute;
        left:0;
        top:0;
        width:100%;
    }
}

/* ===== SHAHZADA MODERN DIGITAL UI V2 ===== */
:root{--gold:#f4c430;--gold-soft:#fff4c7;--ink:#111827;--navy:#0b1220;--panel:#fff;--muted:#64748b;--line:#e5e7eb;--bg:#f5f7fb;}
body{background:var(--bg);color:var(--ink)}
.navbar{background:linear-gradient(90deg,#080b12,#121a29)!important;border-bottom:1px solid rgba(244,196,48,.35);padding:10px 0}
.hero-section{min-height:72vh}
.dashboard-wrap{margin-top:-55px;position:relative;z-index:5}
.stat-card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 12px 35px rgba(15,23,42,.07);height:100%}
.stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:var(--gold-soft);color:#8a6700;font-size:20px}
.stat-label{color:var(--muted);font-size:.82rem;font-weight:600;text-transform:uppercase;letter-spacing:.6px}
.stat-value{font-size:1.65rem;font-weight:800;margin-top:4px}
.quick-card{background:#111827;color:#fff;border-radius:20px;padding:28px;box-shadow:0 15px 40px rgba(15,23,42,.15)}
.quick-btn{border:1px solid rgba(255,255,255,.13);background:#182235;color:#fff;border-radius:14px;padding:16px 18px;text-decoration:none;display:flex;align-items:center;gap:12px;transition:.2s}
.quick-btn:hover{transform:translateY(-2px);background:#223049;color:#fff}
.calc-wrapper{background:#fff;color:var(--ink);padding:34px;border-radius:22px;border:1px solid var(--line);border-left:6px solid var(--gold);box-shadow:0 16px 45px rgba(15,23,42,.08)}
.calc-wrapper label{color:#334155}.form-control-custom{min-height:52px;border:1px solid #d7dce5;background:#fbfcfe}.form-control-custom:focus{border-color:var(--gold);box-shadow:0 0 0 .2rem rgba(244,196,48,.18)}
.net-panel{margin-top:22px;border-radius:18px;background:#0f172a;color:#fff;padding:20px;text-align:center}.net-number{font-size:2.5rem;font-weight:900;color:var(--gold)}
.records-card{background:#fff;border:1px solid var(--line);border-radius:22px;padding:24px;color:var(--ink);box-shadow:0 12px 35px rgba(15,23,42,.06)}
.records-card .table{color:var(--ink);vertical-align:middle}.records-card thead th{background:#f8fafc;color:#475569;font-size:.78rem;text-transform:uppercase;letter-spacing:.5px}
.search-box{border:1px solid var(--line);border-radius:12px;padding:11px 14px;width:100%;max-width:360px}.footer-modern{background:#0b1220;color:#cbd5e1}
@media(max-width:767px){.dashboard-wrap{margin-top:-25px}.calc-wrapper{padding:22px}.net-number{font-size:2rem}}

.report-kpi{background:#fff;border:1px solid var(--line);border-radius:18px;padding:20px;box-shadow:0 10px 30px rgba(15,23,42,.06);height:100%}
.report-kpi .value{font-size:1.55rem;font-weight:900}
.report-kpi .label{font-size:.75rem;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);font-weight:700}
.report-toolbar{background:#111827;border-radius:20px;padding:22px;color:#fff}
.report-toolbar .form-control,.report-toolbar .form-select{min-height:48px}
.chart-card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:22px;box-shadow:0 10px 30px rgba(15,23,42,.05)}
.report-table-wrap{max-height:500px;overflow:auto}


.v6-weighing-section{background:linear-gradient(180deg,#0b1220,#111827)!important}
.v6-desk-card{background:#fff;border:0;border-radius:26px;padding:28px;box-shadow:0 22px 60px rgba(0,0,0,.22)}
.v6-side-card{background:#f8fafc;border:1px solid #e5e7eb;border-radius:26px;padding:28px;box-shadow:0 18px 45px rgba(0,0,0,.14)}
.v6-net-preview{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:20px 22px;border-radius:20px;background:#111827;color:#fff}
.v6-net-number{font-size:clamp(2.4rem,6vw,4.4rem);line-height:1;font-weight:900;letter-spacing:-2px;color:#f4c430;margin:6px 0}
.v6-live-weight{font-size:clamp(2.5rem,7vw,4.8rem);font-weight:900;letter-spacing:-3px;color:#111827;line-height:1;margin:24px 0 12px}
.v6-live-weight small{font-size:1rem;letter-spacing:0;color:#64748b}
.v6-scale-status{display:flex;align-items:center;gap:8px;font-weight:700;color:#475569}
.v6-dot{width:10px;height:10px;border-radius:50%;background:#94a3b8;display:inline-block}
.v6-dot.live{background:#16a34a;box-shadow:0 0 0 5px rgba(22,163,74,.12)}
.checkline{padding:8px 0;color:#475569;font-size:.92rem}
kbd{background:#fff;color:#111827;border:1px solid #cbd5e1;border-bottom-width:2px;border-radius:6px;padding:2px 6px;margin:0 2px;font-size:.78rem}
.v6-input:focus{border-color:#f4c430;box-shadow:0 0 0 .2rem rgba(244,196,48,.16)}
.v6-saving{opacity:.65;pointer-events:none}
@media(max-width:768px){.v6-net-preview{align-items:flex-start;flex-direction:column}.v6-shortcuts{display:none}}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
window.SHAHZADA_V5_SETTINGS = <?= json_encode([
  "company_name"=>wb_setting("company_name"),
  "company_address"=>wb_setting("company_address"),
  "company_phone"=>wb_setting("company_phone"),
  "company_whatsapp"=>wb_setting("company_whatsapp"),
  "company_footer"=>wb_setting("company_footer"),
  "maps_url"=>wb_setting("maps_url")
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
window.SHAHZADA_V5_USER = <?= json_encode([
  "username"=>$_SESSION["wb_username"] ?? "",
  "role"=>$_SESSION["wb_role"] ?? ""
]) ?>;
</script>
</head>

<body>

<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
<div class="container">
    <a class="navbar-brand d-flex align-items-center" href="#home">
        <span class="fw-bold">SHAHZADA WB</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMenu">
        <ul class="navbar-nav ms-auto text-center">
            <li class="nav-item"><a class="nav-link" href="#home">Dashboard</a></li>
            <li class="nav-item"><a class="nav-link fw-bold text-warning" href="#calculator">Weighing Desk</a></li>
            <li class="nav-item"><a class="nav-link" href="#records-section">Records</a></li>
            <?php if (in_array(($_SESSION["wb_role"] ?? ""), ["admin","manager"], true)): ?>
            <li class="nav-item"><a class="nav-link" href="#customers">Customers</a></li>
            <li class="nav-item"><a class="nav-link" href="#vehicles">Vehicles</a></li>
            <li class="nav-item"><a class="nav-link" href="#reports">Reports</a></li>
            <?php endif; ?>
            <?php if (($_SESSION["wb_role"] ?? "") === "admin"): ?><li class="nav-item"><a class="nav-link" href="#settings">Settings</a></li><?php endif; ?>
            <li class="nav-item"><a class="nav-link" href="#location">Location</a></li>
            <li class="nav-item ms-lg-3"><a class="btn btn-warning fw-bold px-4" href="tel:+923356598276">CALL</a></li>
        </ul>
    </div>

<div class="d-flex align-items-center gap-2 ms-lg-3">
  <span class="badge bg-warning text-dark"><i class="fas fa-user me-1"></i><?= htmlspecialchars($_SESSION["wb_username"] ?? "") ?> · <?= htmlspecialchars(strtoupper($_SESSION["wb_role"] ?? "")) ?></span>
  <a class="btn btn-sm btn-outline-light" href="?action=logout"><i class="fas fa-right-from-bracket me-1"></i>Logout</a>
</div>

</div>
</nav>

<header id="home" class="hero-section">
<div class="container">
    <h1 class="hero-title mb-4">SHAHZADA <span class="gold-text">WEIGHBRIDGE</span></h1>
    <h2 class="fw-bold mb-4">200 TONS DIGITAL CAPACITY</h2>
    <p class="lead mb-5">Sparco Road, Moach Goth, Karachi - 24/7 Professional Weighing Services.</p>
    <div class="d-flex justify-content-center gap-3 flex-wrap">
        <a href="#location" class="btn btn-outline-warning btn-lg px-5">Visit Location</a>
        <a href="https://wa.me/923356598276" target="_blank" class="btn btn-warning btn-lg px-5 fw-bold text-dark">Message Now</a>
    </div>
</div>
</header>

<section class="dashboard-wrap pb-4"><div class="container"><div class="row g-3">
<div class="col-6 col-lg-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-label">Today</div><div class="stat-value"><?= $v6Stats['today']['trips'] ?></div><div class="small-muted"><?= number_format($v6Stats['today']['net'],2) ?> KG net</div></div><div class="stat-icon"><i class="fas fa-calendar-day"></i></div></div></div></div>
<div class="col-6 col-lg-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-label">This Week</div><div class="stat-value"><?= $v6Stats['week']['trips'] ?></div><div class="small-muted"><?= number_format($v6Stats['week']['net'],2) ?> KG net</div></div><div class="stat-icon"><i class="fas fa-chart-line"></i></div></div></div></div>
<div class="col-6 col-lg-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-label">This Month</div><div class="stat-value"><?= $v6Stats['month']['trips'] ?></div><div class="small-muted"><?= number_format($v6Stats['month']['net'],2) ?> KG net</div></div><div class="stat-icon"><i class="fas fa-calendar"></i></div></div></div></div>
<div class="col-6 col-lg-3"><div class="stat-card"><div class="d-flex justify-content-between align-items-start"><div><div class="stat-label">System</div><div class="stat-value text-success">ONLINE</div><div class="small-muted">Database connected</div></div><div class="stat-icon"><i class="fas fa-circle-check"></i></div></div></div></div>
</div><div class="quick-card mt-3"><div class="row align-items-center g-3"><div class="col-lg-5"><div class="small text-uppercase opacity-75">Digital Weighbridge</div><h3 class="fw-bold mb-1">Quick Actions</h3><div class="opacity-75">Operate the weighbridge without leaving the dashboard.</div></div><div class="col-lg-7"><div class="row g-2"><div class="col-6 col-md-3"><a class="quick-btn" href="#calculator"><i class="fas fa-plus"></i><span>New</span></a></div><div class="col-6 col-md-3"><a class="quick-btn" href="#records-section"><i class="fas fa-receipt"></i><span>Slips</span></a></div><div class="col-6 col-md-3"><a class="quick-btn" href="#location"><i class="fas fa-location-dot"></i><span>Location</span></a></div><div class="col-6 col-md-3"><a class="quick-btn" href="https://maps.app.goo.gl/iAgLsEYmTDkxgFuG9" target="_blank"><i class="fas fa-diamond-turn-right"></i><span>Directions</span></a></div></div></div></div></div></div></section>

<section id="about" class="section-padding bg-light">
<div class="container">
<div class="row align-items-center">
<div class="col-lg-6">
    <h2 class="fw-bold">Shahzada Weighbridge <span class="gold-text">Karachi</span></h2>
    <p class="mt-4">Humara weighbridge Sparco Road, Moach Goth area mein waqay hai. Hum Karachi ke transporters ko accurate aur computerized weighing results faraham karte hain. System bade trailers aur 200-ton tak ke load ke liye optimized hai.</p>
    <ul class="list-unstyled mt-4">
        <li class="mb-2"><i class="fas fa-check-circle text-warning me-2"></i>24 Hours Service</li>
        <li class="mb-2"><i class="fas fa-check-circle text-warning me-2"></i>Fast & Computerized Weight Slips</li>
        <li class="mb-2"><i class="fas fa-check-circle text-warning me-2"></i>Professional Records Management</li>
    </ul>
</div>
</div>
</div>
</section>


<section id="customers" class="section-padding bg-light">
<div class="container">
  <div class="text-center mb-5">
    <div class="text-uppercase small fw-bold text-warning">Business Management</div>
    <h2 class="fw-bold">Customers & Vehicles</h2>
    <p class="text-muted">Save regular parties and trucks once, then reuse them during weighing.</p>
  </div>

  <div class="row g-4">
    <div class="col-lg-6">
      <div class="records-card h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div><h4 class="fw-bold mb-1">Customer / Party</h4><div class="text-muted small">Add a customer for future reports.</div></div>
          <i class="fas fa-users fs-3 text-warning"></i>
        </div>
        <form id="customerForm">
          <div class="row g-3">
            <div class="col-md-6"><input class="form-control form-control-custom" name="customer_name" placeholder="Customer Name *" required></div>
            <div class="col-md-6"><input class="form-control form-control-custom" name="company" placeholder="Company"></div>
            <div class="col-md-6"><input class="form-control form-control-custom" name="phone" placeholder="Phone"></div>
            <div class="col-md-6"><input class="form-control form-control-custom" name="address" placeholder="Address"></div>
            <div class="col-12"><button class="btn btn-dark px-4" type="submit"><i class="fas fa-plus me-2"></i>Add Customer</button></div>
          </div>
        </form>
        <div id="customerStatus" class="small mt-3"></div>
        <div class="mt-3 small text-muted"><strong><?= count($customers) ?></strong> customers saved</div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="records-card h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div><h4 class="fw-bold mb-1">Vehicle / Truck</h4><div class="text-muted small">Save truck details and default driver.</div></div>
          <i class="fas fa-truck-moving fs-3 text-warning"></i>
        </div>
        <form id="vehicleForm">
          <input type="hidden" name="id" id="vehicleId" value="0">
          <div class="row g-3">
            <div class="col-md-6"><input class="form-control form-control-custom" name="truck_number" placeholder="Truck Number *" required></div>
            <div class="col-md-6"><input class="form-control form-control-custom" name="owner_name" placeholder="Owner / Transporter"></div>
            <div class="col-md-6"><input class="form-control form-control-custom" name="default_driver" placeholder="Default Driver"></div>
            <div class="col-md-6"><input class="form-control form-control-custom" name="phone" placeholder="Phone"></div>
            <div class="col-12 d-flex gap-2 flex-wrap"><button id="vehicleSubmitBtn" class="btn btn-dark px-4" type="submit"><i class="fas fa-save me-2"></i>Save Vehicle</button><button id="vehicleCancelBtn" class="btn btn-outline-secondary px-4 d-none" type="button">Cancel Edit</button></div>
          </div>
        </form>
        <div id="vehicleStatus" class="small mt-3"></div>
        <div class="mt-3 small text-muted"><strong><?= count($vehicles) ?></strong> vehicles saved</div>
      </div>
    </div>
  </div>

  <div class="records-card mt-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div><h4 class="fw-bold mb-1">Saved Customers / Parties</h4><div class="text-muted small">Edit or remove master customer details without affecting weighment history.</div></div>
      <input id="customerSearch" class="search-box" placeholder="Search customer or company...">
    </div>
    <div class="table-responsive mt-3"><table class="table"><thead><tr><th>Customer</th><th>Company</th><th>Phone</th><th>Address</th><th>Action</th></tr></thead>
      <tbody id="customerRows">
      <?php foreach ($customers as $c): ?>
        <tr data-customer-search="<?= htmlspecialchars(strtolower($c["customer_name"] . " " . $c["company"] . " " . $c["phone"] . " " . $c["address"])) ?>">
          <td><strong><?= htmlspecialchars($c["customer_name"]) ?></strong></td><td><?= htmlspecialchars($c["company"]) ?></td><td><?= htmlspecialchars($c["phone"]) ?></td><td><?= htmlspecialchars($c["address"]) ?></td>
          <td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary me-1 edit-customer" data-id="<?= (int)$c["id"] ?>" data-name="<?= htmlspecialchars($c["customer_name"],ENT_QUOTES) ?>" data-company="<?= htmlspecialchars($c["company"],ENT_QUOTES) ?>" data-phone="<?= htmlspecialchars($c["phone"],ENT_QUOTES) ?>" data-address="<?= htmlspecialchars($c["address"],ENT_QUOTES) ?>"><i class="fas fa-pen"></i></button><button type="button" class="btn btn-sm btn-outline-danger delete-customer" data-id="<?= (int)$c["id"] ?>" data-name="<?= htmlspecialchars($c["customer_name"],ENT_QUOTES) ?>"><i class="fas fa-trash"></i></button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$customers): ?><tr><td colspan="5" class="text-center text-muted">No customers saved yet.</td></tr><?php endif; ?>
      </tbody></table></div>
  </div>

  <div class="records-card mt-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div><h4 class="fw-bold mb-1">Saved Vehicles</h4><div class="text-muted small">Latest registered trucks.</div></div>
      <input id="vehicleSearch" class="search-box" placeholder="Search truck or driver...">
    </div>
    <div class="table-responsive mt-3">
      <table class="table">
        <thead><tr><th>Truck</th><th>Owner</th><th>Driver</th><th>Phone</th><th>Action</th></tr></thead>
        <tbody id="vehicleRows">
        <?php foreach ($vehicles as $v): ?>
          <tr data-vehicle-search="<?= htmlspecialchars(strtolower($v["truck_number"] . " " . $v["owner_name"] . " " . $v["default_driver"] . " " . $v["phone"])) ?>">
            <td><strong><?= htmlspecialchars($v["truck_number"]) ?></strong></td><td><?= htmlspecialchars($v["owner_name"]) ?></td><td><?= htmlspecialchars($v["default_driver"]) ?></td><td><?= htmlspecialchars($v["phone"]) ?></td>
            <td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary me-1 edit-vehicle" data-id="<?= (int)$v["id"] ?>" data-truck="<?= htmlspecialchars($v["truck_number"],ENT_QUOTES) ?>" data-owner="<?= htmlspecialchars($v["owner_name"],ENT_QUOTES) ?>" data-driver="<?= htmlspecialchars($v["default_driver"],ENT_QUOTES) ?>" data-phone="<?= htmlspecialchars($v["phone"],ENT_QUOTES) ?>"><i class="fas fa-pen"></i></button><button type="button" class="btn btn-sm btn-outline-danger delete-vehicle" data-id="<?= (int)$v["id"] ?>" data-truck="<?= htmlspecialchars($v["truck_number"],ENT_QUOTES) ?>"><i class="fas fa-trash"></i></button></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$vehicles): ?><tr><td colspan="5" class="text-center text-muted">No vehicles saved yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</section>



<section id="settings" class="section-padding bg-light">
<div class="container">
  <div class="text-center mb-5">
    <div class="text-uppercase small fw-bold text-warning">Administration</div>
    <h2 class="fw-bold">Production Settings & Security</h2>
    <p class="text-muted">Business identity, access control and backup tools.</p>
  </div>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="records-card h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div><h4 class="fw-bold mb-1">Business Settings</h4><div class="small-muted">These details appear on your professional receipt.</div></div>
          <i class="fas fa-building fs-3 text-warning"></i>
        </div>
        <form id="settingsForm">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Business Name</label><input class="form-control form-control-custom" name="company_name" value="<?= htmlspecialchars(wb_setting("company_name")) ?>"></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control form-control-custom" name="company_phone" value="<?= htmlspecialchars(wb_setting("company_phone")) ?>"></div>
            <div class="col-12"><label class="form-label">Address</label><input class="form-control form-control-custom" name="company_address" value="<?= htmlspecialchars(wb_setting("company_address")) ?>"></div>
            <div class="col-md-6"><label class="form-label">WhatsApp</label><input class="form-control form-control-custom" name="company_whatsapp" value="<?= htmlspecialchars(wb_setting("company_whatsapp")) ?>"></div>
            <div class="col-md-6"><label class="form-label">Google Maps URL</label><input class="form-control form-control-custom" name="maps_url" value="<?= htmlspecialchars(wb_setting("maps_url")) ?>"></div>
            <div class="col-12"><label class="form-label">Receipt Footer</label><input class="form-control form-control-custom" name="company_footer" value="<?= htmlspecialchars(wb_setting("company_footer")) ?>"></div>
            <div class="col-12"><button class="btn btn-warning fw-bold px-4" type="submit"><i class="fas fa-floppy-disk me-2"></i>Save Settings</button></div>
          </div>
          <div id="settingsStatus" class="small mt-3"></div>
        </form>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="records-card mb-4">
        <h4 class="fw-bold">Security</h4>
        <div class="small-muted mb-3">Logged in as <strong><?= htmlspecialchars($_SESSION["wb_full_name"] ?? "") ?></strong> — <?= htmlspecialchars($_SESSION["wb_role"] ?? "") ?></div>
        <form id="passwordForm">
          <input type="password" class="form-control form-control-custom mb-2" name="current_password" placeholder="Current password" required>
          <input type="password" class="form-control form-control-custom mb-2" name="new_password" placeholder="New password (8+ characters)" required>
          <button class="btn btn-dark w-100" type="submit">Change Password</button>
        </form>
        <div id="passwordStatus" class="small mt-3"></div>
      </div>

      <div class="records-card">
        <h4 class="fw-bold">Business Backup</h4>
        <p class="small-muted">Download a portable JSON backup of settings, customers, vehicles, weighments and user metadata.</p>
        <?php if (($_SESSION["wb_role"] ?? "") === "admin"): ?>
        <a class="btn btn-outline-light w-100 mb-2" href="?action=backup_json"><i class="fas fa-database me-2"></i>Download Full Backup</a>
        <?php else: ?>
        <div class="alert alert-secondary mb-0">Admin access required for backups.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (($_SESSION["wb_role"] ?? "") === "admin"): ?>
  <div class="records-card mt-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div><h4 class="fw-bold mb-1">User Management</h4><div class="small-muted">Create separate accounts for operators and managers. Default accounts are created automatically on first load if missing.</div></div>
      <span class="badge bg-warning text-dark">ADMIN ONLY</span>
    </div>
    <form id="userForm" class="row g-3 mt-1">
      <div class="col-md-3"><input class="form-control form-control-custom" name="username" placeholder="Username" required></div>
      <div class="col-md-3"><input class="form-control form-control-custom" name="full_name" placeholder="Full Name" required></div>
      <div class="col-md-2"><select class="form-select form-control-custom" name="role"><option value="operator">Operator</option><option value="manager">Manager</option><option value="admin">Admin</option></select></div>
      <div class="col-md-2"><input type="password" class="form-control form-control-custom" name="password" placeholder="Password" required></div>
      <div class="col-md-2"><button class="btn btn-warning w-100" type="submit">Create User</button></div>
    </form>
    <div id="userStatus" class="small mt-3"></div>
    <div class="table-responsive mt-3">
      <table class="table"><thead><tr><th>User</th><th>Name</th><th>Role</th><th>Status</th><th>Last Login</th><th>Action</th></tr></thead>
      <tbody>
      <?php
      $usersRes=$conn->query("SELECT id,username,full_name,role,active,last_login FROM wb_users_v5 ORDER BY id");
      while($u=$usersRes?$usersRes->fetch_assoc():null):
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($u["username"]) ?></strong></td>
        <td><?= htmlspecialchars($u["full_name"]) ?></td>
        <td><?= htmlspecialchars(strtoupper($u["role"])) ?></td>
        <td><?= $u["active"] ? "Active" : "Disabled" ?></td>
        <td><?= htmlspecialchars($u["last_login"] ?? "Never") ?></td>
        <td><?php if((int)$u["id"] !== (int)$_SESSION["wb_user_id"]): ?><button class="btn btn-sm btn-outline-light toggleUser" data-id="<?= (int)$u["id"] ?>">Toggle</button><?php endif; ?></td>
      </tr>
      <?php endwhile; ?>
      </tbody></table>
    </div>
  </div>
  <?php endif; ?>
</div>
</section>

<section id="reports" class="section-padding">
<div class="container">
  <div class="text-center mb-5">
    <div class="text-uppercase small fw-bold text-warning">Business Intelligence</div>
    <h2 class="fw-bold">Reports & Analytics</h2>
    <p class="text-muted">Filter weighments, inspect totals and export your report.</p>
  </div>

  <div class="report-toolbar mb-4">
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label small opacity-75">From Date</label>
        <input id="reportFrom" type="date" class="form-control">
      </div>
      <div class="col-md-3">
        <label class="form-label small opacity-75">To Date</label>
        <input id="reportTo" type="date" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label small opacity-75">Truck</label>
        <input id="reportTruck" class="form-control" placeholder="ABC-123">
      </div>
      <div class="col-md-2">
        <label class="form-label small opacity-75">Customer</label>
        <input id="reportCustomer" class="form-control" placeholder="Party">
      </div>
      <div class="col-md-2 d-grid">
        <button id="runReport" class="btn btn-warning fw-bold"><i class="fas fa-magnifying-glass me-2"></i>Run Report</button>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap mt-3">
      <button type="button" class="btn btn-outline-light btn-sm" data-report-range="today">Today</button>
      <button type="button" class="btn btn-outline-light btn-sm" data-report-range="week">This Week</button>
      <button type="button" class="btn btn-outline-light btn-sm" data-report-range="month">This Month</button>
      <button type="button" class="btn btn-outline-light btn-sm" id="clearReport">Clear</button>
      <button type="button" class="btn btn-light btn-sm ms-md-auto" id="exportReport"><i class="fas fa-file-csv me-2"></i>Export CSV</button>
      <button type="button" class="btn btn-warning btn-sm" id="printReport"><i class="fas fa-print me-2"></i>Print Report</button>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="report-kpi"><div class="label">Trips</div><div id="rTrips" class="value">0</div></div></div>
    <div class="col-6 col-lg-3"><div class="report-kpi"><div class="label">Gross Weight</div><div id="rGross" class="value">0 KG</div></div></div>
    <div class="col-6 col-lg-3"><div class="report-kpi"><div class="label">Tare Weight</div><div id="rTare" class="value">0 KG</div></div></div>
    <div class="col-6 col-lg-3"><div class="report-kpi"><div class="label">Net Weight</div><div id="rNet" class="value text-success">0 KG</div></div></div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-8">
      <div class="chart-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div><h5 class="fw-bold mb-1">Net Weight by Day</h5><div class="text-muted small">Based on the selected report.</div></div>
          <span id="reportStatus" class="small text-muted">Ready</span>
        </div>
        <canvas id="netChart" height="115"></canvas>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="chart-card h-100">
        <h5 class="fw-bold">Quick Summary</h5>
        <hr>
        <div class="d-flex justify-content-between py-2"><span>Vehicles</span><strong id="rVehicles">0</strong></div>
        <div class="d-flex justify-content-between py-2"><span>Customers</span><strong id="rCustomers">0</strong></div>
        <div class="d-flex justify-content-between py-2"><span>Deduction</span><strong id="rDeduction">0 KG</strong></div>
        <div class="d-flex justify-content-between py-2"><span>Average Net / Trip</span><strong id="rAverage">0 KG</strong></div>
      </div>
    </div>
  </div>

  <div class="records-card">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div><h4 class="fw-bold mb-1">Report Results</h4><div class="text-muted small">Latest matching weighments.</div></div>
      <span id="reportCount" class="badge bg-dark">0 records</span>
    </div>
    <div class="table-responsive report-table-wrap mt-3">
      <table class="table" id="reportTable">
        <thead><tr><th>Slip</th><th>ID</th><th>Date</th><th>Truck</th><th>Driver</th><th>Customer</th><th>Gross</th><th>Tare</th><th>Deduction</th><th>Net</th><th>Action</th></tr></thead>
        <tbody id="reportRows"><tr><td colspan="11" class="text-center text-muted">Run a report to load data.</td></tr></tbody>
      </table>
    </div>
  </div>
</div>
</section>

<section id="calculator" class="py-5 bg-dark v6-weighing-section">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
    <div><div class="text-uppercase small fw-bold text-warning">Operator Workstation</div><h2 class="fw-bold text-white mb-1">⚖️ Weighing Desk</h2><p class="text-white-50 mb-0">Fast workflow: vehicle → weight → net → save → print.</p></div>
    <div class="v6-shortcuts text-white-50 small"><kbd>F2</kbd> Truck <kbd>F4</kbd> Calculate <kbd>Ctrl+S</kbd> Save <kbd>Ctrl+P</kbd> Print <kbd>Esc</kbd> New</div>
  </div>
  <div class="row g-4 align-items-stretch">
    <div class="col-xl-8">
      <div class="calc-wrapper v6-desk-card h-100">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div><h4 class="fw-bold mb-1">Current Weighment</h4><div class="small text-muted">Enter vehicle details and weights. Net is calculated safely on every save.</div></div>
          <button id="newWeighingBtn" type="button" class="btn btn-outline-dark btn-sm"><i class="fas fa-rotate-left me-1"></i>New</button>
        </div>
        <div class="row g-3">
          <div class="col-md-6"><label class="small mb-2 fw-bold">TRUCK NUMBER <span class="text-danger">*</span></label><input type="text" id="truck" class="form-control form-control-custom v6-input" placeholder="e.g. ABC-123" autocomplete="off"></div>
          <div class="col-md-6"><label class="small mb-2 fw-bold">DRIVER NAME <span class="text-danger">*</span></label><input type="text" id="driver" class="form-control form-control-custom v6-input" placeholder="Driver name" autocomplete="off"></div>
          <div class="col-12"><label class="small mb-2 fw-bold">CUSTOMER / PARTY</label><select id="customerSelect" class="form-select form-control-custom v6-input"><option value="">Walk-in / Select customer...</option><?php foreach ($customers as $c): ?><option value="<?= htmlspecialchars($c['customer_name']) ?>"><?= htmlspecialchars($c['customer_name'] . ($c['company'] ? ' — '.$c['company'] : '')) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="small mb-2 fw-bold">GROSS WEIGHT (KG) <span class="text-danger">*</span></label><input type="number" id="gross" min="0" step="0.01" class="form-control form-control-custom v6-input v6-weight" placeholder="0"></div>
          <div class="col-md-4"><label class="small mb-2 fw-bold">TARE WEIGHT (KG) <span class="text-danger">*</span></label><input type="number" id="tare" min="0" step="0.01" class="form-control form-control-custom v6-input v6-weight" placeholder="0"></div>
          <div class="col-md-4"><label class="small mb-2 fw-bold">DEDUCTION / FEE (KG)</label><input type="number" id="fee" min="0" step="0.01" value="0" class="form-control form-control-custom v6-input v6-weight" placeholder="0"></div>
        </div>
        <div class="v6-net-preview mt-4" id="res">
          <div><div class="small text-uppercase fw-bold opacity-75">Net Weight</div><div class="v6-net-number"><span id="net">0</span> <small>KG</small></div><div class="small opacity-75">Gross − Tare − Deduction</div></div>
          <div class="text-end"><div id="weightState" class="badge bg-secondary">WAITING</div><div id="weightHint" class="small mt-2 opacity-75">Enter weights to calculate</div></div>
        </div>
        <div class="row g-2 mt-3">
          <div class="col-md-4"><button id="calculateBtn" type="button" class="btn btn-dark w-100 py-3 fw-bold"><i class="fas fa-calculator me-2"></i>Calculate</button></div>
          <div class="col-md-4"><button id="saveBtn" type="button" class="btn btn-warning w-100 py-3 fw-bold"><i class="fas fa-floppy-disk me-2"></i>Save Record</button></div>
          <div class="col-md-4"><button id="savePrintBtn" type="button" class="btn btn-success w-100 py-3 fw-bold"><i class="fas fa-print me-2"></i>Save & Print</button></div>
        </div>
        <div class="row g-2 mt-2"><div class="col-md-6"><button id="printBtn" type="button" class="btn btn-outline-dark w-100 py-2 fw-bold"><i class="fas fa-receipt me-2"></i>Print Current Slip</button></div><div class="col-md-6"><button id="whatsappBtn" type="button" class="btn btn-outline-success w-100 py-2 fw-bold"><i class="fab fa-whatsapp me-2"></i>Send WhatsApp</button></div></div>
        <div id="status" class="mt-3 text-center fw-bold" role="status" aria-live="polite"></div>
      </div>
    </div>
    <div class="col-xl-4">
      <div class="v6-side-card h-100">
        <div class="d-flex align-items-center justify-content-between mb-3"><div><div class="small text-uppercase fw-bold text-muted">Scale integration</div><h5 class="fw-bold mb-0">Live Weight</h5></div><span id="scaleBadge" class="badge bg-secondary">Manual</span></div>
        <div class="v6-live-weight" id="liveWeight">0 <small>KG</small></div>
        <div class="v6-scale-status"><span id="scaleDot" class="v6-dot"></span><span id="scaleStatus">Waiting for indicator</span></div>
        <div class="small text-muted mt-3">Current V6 keeps the indicator as an integration point. When your real indicator is connected through USB/RS-232/LAN, this live value can feed Gross/Tare without manual typing.</div>
        <div class="mt-4"><label class="small fw-bold">Test / Indicator Reading</label><div class="input-group mt-2"><input id="wbIndicator" type="number" step="0.01" class="form-control" placeholder="e.g. 45280"><button type="button" class="btn btn-dark" onclick="wbUseWeight()">Use</button></div></div>
        <hr class="my-4"><div class="small fw-bold mb-2">Operator checklist</div><div class="checkline">✓ Truck number verified</div><div class="checkline">✓ Weight stable before capture</div><div class="checkline">✓ Gross / Tare validated</div><div class="checkline">✓ Save before leaving scale</div>
      </div>
    </div>
  </div>
</div>
</section>

<section id="records-section" class="py-5">
<div class="container">
<div class="records-card">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h3 class="mb-0">Daily Records</h3>
    <button id="clearBtn" type="button" class="btn btn-outline-danger btn-sm">Admin: Clear Records</button>
</div>
<p class="small-muted mt-2">Records are stored in MySQL database: <b>shahzada_wieghtbridge</b>.</p>


<div class="row g-2 align-items-center mt-3"><div class="col-lg-7"><input id="recordSearch" class="search-box" type="search" placeholder="Search truck, driver or date..."></div><div class="col-lg-5 text-lg-end"><button id="todayBtn" type="button" class="btn btn-outline-dark me-2">Today</button><button id="allBtn" type="button" class="btn btn-dark">All Records</button></div></div>
<div class="table-responsive">
<table class="table table-bordered table-striped mt-3">
<thead>
<tr>
    <th>Date</th>
    <th>Truck</th>
    <th>Driver</th>
    <th>Gross</th>
    <th>Tare</th>
    <th>Deduction</th>
    <th>Net</th>
</tr>
</thead>
<tbody id="records">
<?php foreach ($records as $r): ?>
<tr>
    <td><?= htmlspecialchars($r["created_at"]) ?></td>
    <td><?= htmlspecialchars($r["truck_number"]) ?></td>
    <td><?= htmlspecialchars($r["driver_name"]) ?></td>
    <td><?= htmlspecialchars($r["gross_weight"]) ?> KG</td>
    <td><?= htmlspecialchars($r["tare_weight"]) ?> KG</td>
    <td><?= htmlspecialchars($r["deduction"]) ?> KG</td>
    <td><b><?= htmlspecialchars($r["net_weight"]) ?> KG</b></td>
</tr>
<?php endforeach; ?>
<?php if (count($records) === 0): ?>
<tr><td colspan="7" class="text-center">No records saved yet.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>
</section>

<section id="location" class="section-padding bg-light">
<div class="container">
<div class="text-center mb-5">
    <h2 class="fw-bold">SPARCO ROAD STATION</h2>
    <p class="lead">Moach Goth, Near Sparco Road, Karachi.</p>
</div>
<div class="map-wrapper shadow-lg mb-4">
<iframe
src="https://www.google.com/maps?q=https://maps.app.goo.gl/iAgLsEYmTDkxgFuG9&output=embed"
width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy"></iframe>
</div>
<div class="text-center">
<a href="https://maps.app.goo.gl/iAgLsEYmTDkxgFuG9" target="_blank" class="btn btn-dark btn-lg px-5 rounded-pill">
<i class="fas fa-directions text-warning me-2"></i>Open in Google Maps
</a>
</div>
</div>
</section>

<section class="py-5 footer-modern text-center">
<div class="container">
<h2 class="fw-bold gold-text mb-4">DIRECT CONTACT</h2>
<p class="lead mb-4">Sirf ek click karein aur WhatsApp par humse rabta karein.</p>
<div class="d-flex justify-content-center gap-3 flex-wrap">
<a href="https://wa.me/923356598276" target="_blank" class="btn-wa-direct">
<i class="fab fa-whatsapp me-3 fa-lg"></i>WHATSAPP MESSENGER
</a>
<a href="tel:+923356598276" class="btn btn-outline-light btn-lg px-5 py-3 rounded-pill fw-bold">
<i class="fas fa-phone-alt me-2"></i>CALL 0335-6598276
</a>
</div>
</div>
</section>

<a href="https://wa.me/923356598276" target="_blank" class="floating-wa shadow">
<i class="fab fa-whatsapp"></i>
</a>

<!-- Hidden print slip -->
<div id="printSlip" style="display:none;"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentRecord = <?php
    if ($latestRecord) {
        echo json_encode([
            "id" => (int)$latestRecord["id"], "truck" => $latestRecord["truck_number"], "driver" => $latestRecord["driver_name"],
            "customer" => $latestRecord["customer"] ?? "", "gross" => (float)$latestRecord["gross_weight"], "tare" => (float)$latestRecord["tare_weight"],
            "deduction" => (float)$latestRecord["deduction"], "net" => (float)$latestRecord["net_weight"], "slip_no" => $latestRecord["slip_no"] ?? "", "date" => $latestRecord["created_at"]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else { echo "null"; }
?>;

const truckEl=document.getElementById("truck"), driverEl=document.getElementById("driver"), grossEl=document.getElementById("gross"), tareEl=document.getElementById("tare"), feeEl=document.getElementById("fee");
const netEl=document.getElementById("net"), resultEl=document.getElementById("res"), statusEl=document.getElementById("status"), customerSelect=document.getElementById("customerSelect");
const weightState=document.getElementById("weightState"), weightHint=document.getElementById("weightHint"), liveWeight=document.getElementById("liveWeight"), scaleStatus=document.getElementById("scaleStatus"), scaleDot=document.getElementById("scaleDot");
let saving=false;
function number(v){const n=parseFloat(v);return Number.isFinite(n)?n:0;}
function setStatus(msg,type="muted"){statusEl.textContent=msg;statusEl.className="mt-3 text-center fw-bold "+({ok:"text-success",error:"text-danger",warn:"text-warning",muted:"text-white"}[type]||"text-muted");}
function markDirty(){ if(resultEl){resultEl.dataset.valid="0"; weightState.className="badge bg-secondary";weightState.textContent="READY";weightHint.textContent="Calculate before saving.";} }
function calculateNetWeight(showAlert=true){
  const gross=number(grossEl.value), tare=number(tareEl.value), fee=number(feeEl.value);
  if(gross<=0 || tare<0){if(showAlert) alert("Enter a valid Gross Weight and Tare Weight.");return null;}
  if(tare>gross){if(showAlert) alert("Tare Weight cannot be greater than Gross Weight.");return null;}
  if(fee<0){if(showAlert) alert("Deduction cannot be negative.");return null;}
  const net=gross-tare-fee;if(net<0){if(showAlert) alert("Deduction is too high; Net cannot be negative.");return null;}
  netEl.textContent=net.toLocaleString("en-US",{maximumFractionDigits:2});resultEl.dataset.valid="1";weightState.className="badge bg-success";weightState.textContent="CALCULATED";weightHint.textContent="Ready to save.";
  currentRecord={id:currentRecord?.id||null,truck:truckEl.value.trim(),driver:driverEl.value.trim(),customer:customerSelect?.value||"",gross,tare,deduction:fee,net,date:currentRecord?.date||new Date().toLocaleString(),slip_no:currentRecord?.slip_no||""};
  return currentRecord;
}
function fillVehicleDriver(){
  const val=truckEl.value.trim().toLowerCase(); const option=Array.from(document.querySelectorAll("#truckList option")).find(o=>o.value.toLowerCase()===val); if(option&&option.dataset.driver) driverEl.value=option.dataset.driver;
}
truckEl?.addEventListener("change",fillVehicleDriver); truckEl?.addEventListener("blur",fillVehicleDriver);
[grossEl,tareEl,feeEl,truckEl,driverEl,customerSelect].forEach(el=>el?.addEventListener("input",markDirty));
[grossEl,tareEl,feeEl].forEach(el=>el?.addEventListener("input",()=>{const g=number(grossEl.value),t=number(tareEl.value),f=number(feeEl.value);if(g>0&&t>=0&&t<=g&&f>=0&&g-t-f>=0){liveWeight.innerHTML=(g-t-f).toLocaleString("en-US",{maximumFractionDigits:2})+' <small>KG NET</small>';}}));
document.getElementById("calculateBtn")?.addEventListener("click",()=>calculateNetWeight(true));

async function saveRecord(printAfter=false){
  if(saving)return; const record=calculateNetWeight(true); if(!record)return;
  if(!record.truck||!record.driver){alert("Truck Number and Driver Name are required.");truckEl.focus();return;}
  saving=true;document.querySelectorAll("#saveBtn,#savePrintBtn").forEach(b=>b.classList.add("v6-saving"));setStatus("Saving to MySQL...","warn");
  const form=new FormData();form.append("action","save_record");form.append("truck",record.truck);form.append("driver",record.driver);form.append("customer",record.customer||"");form.append("gross",record.gross);form.append("tare",record.tare);form.append("deduction",record.deduction);
  try{const response=await fetch(window.location.href,{method:"POST",body:form,cache:"no-store"});const data=await response.json();if(!data.success)throw new Error(data.message||"Database save failed.");currentRecord={...record,...data};setStatus("✓ Saved "+(data.slip_no||"record")+" successfully.","ok");weightState.className="badge bg-success";weightState.textContent="SAVED";weightHint.textContent="Database record confirmed.";if(printAfter){setTimeout(()=>printSlip(currentRecord),150);}else{setTimeout(()=>location.reload(),700);}}
  catch(error){setStatus("✗ "+error.message,"error");console.error(error);}finally{saving=false;document.querySelectorAll("#saveBtn,#savePrintBtn").forEach(b=>b.classList.remove("v6-saving"));}
}
document.getElementById("saveBtn")?.addEventListener("click",()=>saveRecord(false));document.getElementById("savePrintBtn")?.addEventListener("click",()=>saveRecord(true));
function getPrintRecord(){return currentRecord||calculateNetWeight(false);}
function printSlip(recordOverride=null){const record=recordOverride||getPrintRecord();if(!record){alert("Calculate a valid weighment first.");return;}if(!record.truck||!record.driver){alert("Truck Number and Driver Name are required.");return;}const date=record.date||new Date().toLocaleString();const s=window.SHAHZADA_V5_SETTINGS||{};const width=localStorage.getItem("wbThermalWidth")||"80mm";const win=window.open("","_blank","width=450,height=700");if(!win){alert("Please allow pop-ups for localhost.");return;}win.document.write(`<!doctype html><html><head><title>${escapeHtml(record.slip_no||"Weight Slip")}</title><style>@page{size:${width} auto;margin:3mm}body{font-family:Arial,sans-serif;width:${width};max-width:100%;margin:0 auto;padding:4mm;box-sizing:border-box;color:#111}.brand{text-align:center;font-size:18px;font-weight:900}.sub{text-align:center;font-size:11px;margin:2px 0 8px}.line{border-top:1px dashed #000;margin:8px 0}.row{display:flex;justify-content:space-between;gap:8px;margin:6px 0}.row span:last-child{font-weight:700;text-align:right}.net{font-size:18px;font-weight:900}.meta{text-align:center;font-size:10px;margin-top:8px}.footer{text-align:center;font-size:10px;margin-top:12px}</style></head><body><div class="brand">${escapeHtml(s.company_name||"SHAHZADA WEIGHBRIDGE")}</div><div class="sub">${escapeHtml(s.company_address||"")}</div><div class="line"></div><div class="row"><span>Slip No</span><span>${escapeHtml(record.slip_no||"-")}</span></div><div class="row"><span>Truck</span><span>${escapeHtml(record.truck)}</span></div><div class="row"><span>Driver</span><span>${escapeHtml(record.driver)}</span></div>${record.customer?`<div class="row"><span>Customer</span><span>${escapeHtml(record.customer)}</span></div>`:""}<div class="line"></div><div class="row"><span>Gross</span><span>${number(record.gross).toLocaleString()} KG</span></div><div class="row"><span>Tare</span><span>${number(record.tare).toLocaleString()} KG</span></div><div class="row"><span>Deduction</span><span>${number(record.deduction||0).toLocaleString()} KG</span></div><div class="row net"><span>NET</span><span>${number(record.net).toLocaleString()} KG</span></div><div class="line"></div><div class="meta">${escapeHtml(date)}</div><div class="footer">${escapeHtml(s.company_phone||"")}<br>${escapeHtml(s.company_footer||"Thank you")}</div><script>window.onload=function(){window.print();}<' + '/script></body></html>`);win.document.close();}
document.getElementById("printBtn")?.addEventListener("click",()=>printSlip());
function resetWeighing(){truckEl.value="";driverEl.value="";if(customerSelect)customerSelect.value="";grossEl.value="";tareEl.value="";feeEl.value="0";netEl.textContent="0";resultEl.dataset.valid="0";currentRecord=null;liveWeight.innerHTML='0 <small>KG</small>';weightState.className="badge bg-secondary";weightState.textContent="READY";weightHint.textContent="Enter weights to calculate";setStatus("New weighing ready.","muted");truckEl.focus();}
document.getElementById("newWeighingBtn")?.addEventListener("click",resetWeighing);
function wbUseWeight(){const v=number(document.getElementById("wbIndicator")?.value);if(v<=0){alert("Enter a valid indicator reading.");return;}liveWeight.innerHTML=v.toLocaleString("en-US",{maximumFractionDigits:2})+' <small>KG</small>';scaleStatus.textContent="Reading received";scaleDot.classList.add("live");document.getElementById("scaleBadge").textContent="Test Reading";document.getElementById("scaleBadge").className="badge bg-success";grossEl.value=v;grossEl.dispatchEvent(new Event("input",{bubbles:true}));setStatus("Indicator reading applied to Gross Weight.","ok");}
document.addEventListener("keydown",e=>{if(e.key==="F2"){e.preventDefault();truckEl.focus();truckEl.select();}if(e.key==="F4"){e.preventDefault();calculateNetWeight(true);}if(e.ctrlKey&&e.key.toLowerCase()==="s"){e.preventDefault();saveRecord(false);}if(e.ctrlKey&&e.key.toLowerCase()==="p"){e.preventDefault();printSlip();}if(e.key==="Escape"){e.preventDefault();resetWeighing();}});

function sendWhatsApp() {
    const record = getPrintRecord();
    if (!record) return;

    const msg =
`*SHAHZADA WEIGHBRIDGE*
Truck: ${record.truck}
Driver: ${record.driver}
${record.customer ? `Customer: ${record.customer}
` : ""}Gross: ${record.gross} KG
Tare: ${record.tare} KG
Deduction: ${record.deduction || 0} KG
Net: ${record.net} KG
Date: ${record.date || new Date().toLocaleString()}`;

    window.open("https://wa.me/923356598276?text=" + encodeURIComponent(msg), "_blank");
}

document.getElementById("whatsappBtn").addEventListener("click", sendWhatsApp);

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}


const searchInput = document.getElementById("recordSearch"); const tableBody = document.getElementById("records");
function filterRows(mode="all"){const q=(searchInput?.value||"").toLowerCase().trim(); const today=new Date().toLocaleDateString(); Array.from(tableBody.querySelectorAll("tr")).forEach(row=>{const t=row.textContent.toLowerCase(); const d=row.cells[0]?.textContent||""; row.style.display=(!q||t.includes(q))&&(mode!=="today"||d.includes(today))?"":"none";});}
searchInput?.addEventListener("input",()=>filterRows("all")); document.getElementById("todayBtn")?.addEventListener("click",()=>filterRows("today")); document.getElementById("allBtn")?.addEventListener("click",()=>filterRows("all"));


async function submitManagementForm(formId, action, statusId) {
    const formEl = document.getElementById(formId);
    const status = document.getElementById(statusId);
    if (!formEl) return;
    formEl.addEventListener("submit", async (e) => {
        e.preventDefault();
        status.textContent = "Saving...";
        status.className = "small mt-3 text-warning fw-bold";
        const data = new FormData(formEl);
        data.append("action", action);
        try {
            const response = await fetch(window.location.href, {method:"POST", body:data});
            const result = await response.json();
            if (!result.success) throw new Error(result.message);
            status.textContent = "✓ " + result.message;
            status.className = "small mt-3 text-success fw-bold";
            formEl.reset();
            setTimeout(() => window.location.reload(), 500);
        } catch (err) {
            status.textContent = "✗ " + err.message;
            status.className = "small mt-3 text-danger fw-bold";
        }
    });
}
submitManagementForm("customerForm", "add_customer", "customerStatus");

async function postManagement(fd, statusId){
    const status=document.getElementById(statusId);
    if(status){status.textContent="Saving...";status.className="small mt-3 text-warning fw-bold";}
    try{
        const r=await fetch(window.location.href,{method:"POST",body:fd}); const d=await r.json();
        if(!d.success) throw new Error(d.message||"Operation failed.");
        if(status){status.textContent="✓ "+d.message;status.className="small mt-3 text-success fw-bold";}
        setTimeout(()=>location.reload(),450);
    }catch(err){if(status){status.textContent="✗ "+err.message;status.className="small mt-3 text-danger fw-bold";}}
}
const vehicleForm=document.getElementById("vehicleForm");
vehicleForm?.addEventListener("submit",e=>{e.preventDefault();const fd=new FormData(vehicleForm);fd.append("action","save_vehicle");postManagement(fd,"vehicleStatus");});
document.querySelectorAll(".edit-vehicle").forEach(btn=>btn.addEventListener("click",()=>{
    document.getElementById("vehicleId").value=btn.dataset.id; vehicleForm.querySelector('[name="truck_number"]').value=btn.dataset.truck||"";
    vehicleForm.querySelector('[name="owner_name"]').value=btn.dataset.owner||""; vehicleForm.querySelector('[name="default_driver"]').value=btn.dataset.driver||""; vehicleForm.querySelector('[name="phone"]').value=btn.dataset.phone||"";
    document.getElementById("vehicleSubmitBtn").innerHTML='<i class="fas fa-pen me-2"></i>Update Vehicle'; document.getElementById("vehicleCancelBtn").classList.remove("d-none");
    vehicleForm.scrollIntoView({behavior:"smooth",block:"center"});
}));
document.getElementById("vehicleCancelBtn")?.addEventListener("click",()=>{vehicleForm.reset();document.getElementById("vehicleId").value="0";document.getElementById("vehicleSubmitBtn").innerHTML='<i class="fas fa-save me-2"></i>Save Vehicle';document.getElementById("vehicleCancelBtn").classList.add("d-none");});
document.querySelectorAll(".delete-vehicle").forEach(btn=>btn.addEventListener("click",()=>{if(!confirm('Delete vehicle '+btn.dataset.truck+'? Existing weighment history will remain safe.'))return;const fd=new FormData();fd.append("action","delete_vehicle");fd.append("id",btn.dataset.id);postManagement(fd,"vehicleStatus");}));
document.getElementById("vehicleSearch")?.addEventListener("input",e=>{const q=e.target.value.toLowerCase().trim();document.querySelectorAll("#vehicleRows tr").forEach(row=>row.style.display=(row.dataset.vehicleSearch||row.textContent.toLowerCase()).includes(q)?"":"none");});
document.getElementById("customerSearch")?.addEventListener("input",e=>{const q=e.target.value.toLowerCase().trim();document.querySelectorAll("#customerRows tr").forEach(row=>row.style.display=(row.dataset.customerSearch||row.textContent.toLowerCase()).includes(q)?"":"none");});
document.querySelectorAll(".edit-customer").forEach(btn=>btn.addEventListener("click",()=>{
    const name=prompt("Customer name:",btn.dataset.name||"");if(name===null)return;const company=prompt("Company:",btn.dataset.company||"");if(company===null)return;const phone=prompt("Phone:",btn.dataset.phone||"");if(phone===null)return;const address=prompt("Address:",btn.dataset.address||"");if(address===null)return;
    const fd=new FormData();fd.append("action","update_customer");fd.append("id",btn.dataset.id);fd.append("customer_name",name);fd.append("company",company);fd.append("phone",phone);fd.append("address",address);postManagement(fd,"customerStatus");
}));
document.querySelectorAll(".delete-customer").forEach(btn=>btn.addEventListener("click",()=>{if(!confirm('Delete customer '+btn.dataset.name+'? Existing weighment history will remain safe.'))return;const fd=new FormData();fd.append("action","delete_customer");fd.append("id",btn.dataset.id);postManagement(fd,"customerStatus");}));

document.getElementById("clearBtn").addEventListener("click", async () => {
    if (!confirm("Delete ALL saved weighbridge records?")) return;

    const form = new FormData();
    form.append("action", "clear_records");

    try {
        const response = await fetch(window.location.href, {
            method: "POST",
            body: form
        });
        const data = await response.json();

        if (!data.success) throw new Error(data.message);

        alert(data.message);
        window.location.reload();
    } catch (error) {
        alert(error.message);
    }
});
</script>
<script>

// ===== V5 Production Settings & Security =====
async function v5Form(formId, action, statusId, reloadAfter=false){
  const form=document.getElementById(formId); if(!form) return;
  form.addEventListener("submit",async e=>{
    e.preventDefault();
    const status=document.getElementById(statusId);
    status.textContent="Saving...";
    const fd=new FormData(form); fd.append("action",action);
    try{
      const r=await fetch(window.location.href,{method:"POST",body:fd});
      const d=await r.json();
      if(!d.success) throw new Error(d.message||"Request failed");
      status.textContent="✓ "+d.message;
      status.className="small mt-3 text-success fw-bold";
      if(reloadAfter) setTimeout(()=>location.reload(),500);
      else form.reset();
    }catch(err){
      status.textContent="✗ "+err.message;
      status.className="small mt-3 text-danger fw-bold";
    }
  });
}
v5Form("settingsForm","save_settings","settingsStatus",true);
v5Form("passwordForm","change_password","passwordStatus",false);
v5Form("userForm","add_user","userStatus",true);
document.querySelectorAll(".toggleUser").forEach(btn=>{
  btn.addEventListener("click",async()=>{
    const fd=new FormData(); fd.append("action","toggle_user"); fd.append("id",btn.dataset.id);
    const r=await fetch(window.location.href,{method:"POST",body:fd});
    const d=await r.json(); if(!d.success) alert(d.message); else location.reload();
  });
});

// ===== V4 Reports & Analytics =====
let reportRowsData = [];
let netChartInstance = null;

function fmtKg(n){ return Number(n || 0).toLocaleString() + " KG"; }
function isoDate(d){
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
}

function setReportRange(range){
    const now = new Date();
    const to = isoDate(now);
    let from = to;
    if(range === "week"){
        const day = now.getDay();
        const diff = day === 0 ? 6 : day - 1;
        const monday = new Date(now);
        monday.setDate(now.getDate() - diff);
        from = isoDate(monday);
    } else if(range === "month"){
        from = isoDate(new Date(now.getFullYear(), now.getMonth(), 1));
    }
    document.getElementById("reportFrom").value = from;
    document.getElementById("reportTo").value = to;
    loadReport();
}

async function loadReport(){
    const status = document.getElementById("reportStatus");
    status.textContent = "Loading...";
    const p = new URLSearchParams({
        action:"report_data",
        from:document.getElementById("reportFrom").value,
        to:document.getElementById("reportTo").value,
        truck:document.getElementById("reportTruck").value.trim(),
        customer:document.getElementById("reportCustomer").value.trim()
    });
    try{
        const res = await fetch(window.location.pathname + "?" + p.toString());
        const data = await res.json();
        if(!data.success) throw new Error(data.message || "Report failed");
        reportRowsData = data.rows || [];
        const s = data.summary || {};
        document.getElementById("rTrips").textContent = Number(s.trips||0).toLocaleString();
        document.getElementById("rGross").textContent = fmtKg(s.gross);
        document.getElementById("rTare").textContent = fmtKg(s.tare);
        document.getElementById("rNet").textContent = fmtKg(s.net);
        document.getElementById("rVehicles").textContent = Number(s.vehicles||0).toLocaleString();
        document.getElementById("rCustomers").textContent = Number(s.customers||0).toLocaleString();
        document.getElementById("rDeduction").textContent = fmtKg(s.deduction);
        document.getElementById("rAverage").textContent = fmtKg(s.trips ? s.net/s.trips : 0);
        document.getElementById("reportCount").textContent = reportRowsData.length + " records";
        renderReportRows(reportRowsData);
        renderNetChart(reportRowsData);
        status.textContent = "Updated " + new Date().toLocaleTimeString();
    }catch(err){
        status.textContent = "Error";
        alert(err.message);
    }
}

function renderReportRows(rows){
    const body = document.getElementById("reportRows");
    if(!rows.length){
        body.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">No matching records.</td></tr>';
        return;
    }
    body.innerHTML = rows.map((r,i) => `<tr>
        <td><strong>${escapeHtml(r.slip_no || ("#" + r.id))}</strong></td><td>#${escapeHtml(r.id)}</td><td>${escapeHtml(r.created_at)}</td>
        <td><strong>${escapeHtml(r.truck_number)}</strong></td><td>${escapeHtml(r.driver_name)}</td><td>${escapeHtml(r.customer || "")}</td>
        <td>${Number(r.gross_weight).toLocaleString()} KG</td><td>${Number(r.tare_weight).toLocaleString()} KG</td><td>${Number(r.deduction).toLocaleString()} KG</td><td><strong>${Number(r.net_weight).toLocaleString()} KG</strong></td>
        <td><button type="button" class="btn btn-sm btn-outline-dark" onclick="printReportRecord(${i})" title="Reprint slip"><i class="fas fa-print"></i></button></td>
    </tr>`).join("");
}

function printReportRecord(index){const r=reportRowsData[index];if(!r)return;printSlipForRecord({slip_no:r.slip_no||("#"+r.id),truck:r.truck_number,driver:r.driver_name,customer:r.customer||"",gross:Number(r.gross_weight),tare:Number(r.tare_weight),deduction:Number(r.deduction),net:Number(r.net_weight),date:r.created_at});}
function printSlipForRecord(record){const win=window.open("","_blank","width=450,height=650");if(!win){alert("Please allow pop-ups for printing.");return;}win.document.write(`<!doctype html><html><head><title>Weight Slip</title><style>body{font-family:Arial,sans-serif;width:320px;margin:20px auto;color:#111}h2{text-align:center;margin-bottom:20px}.line{border-top:1px dashed #000;margin:12px 0}.row{display:flex;justify-content:space-between;margin:8px 0}.net{font-size:20px;font-weight:bold}.footer{text-align:center;margin-top:25px;font-size:12px}</style></head><body><h2>${escapeHtml((window.SHAHZADA_V5_SETTINGS||{}).company_name||"SHAHZADA WEIGHBRIDGE")}</h2><div class="line"></div><div class="row"><b>Slip No:</b><span>${escapeHtml(record.slip_no||"")}</span></div><div class="row"><b>Truck:</b><span>${escapeHtml(record.truck)}</span></div><div class="row"><b>Driver:</b><span>${escapeHtml(record.driver)}</span></div>${record.customer?`<div class="row"><b>Customer:</b><span>${escapeHtml(record.customer)}</span></div>`:""}<div class="row"><b>Gross:</b><span>${record.gross} KG</span></div><div class="row"><b>Tare:</b><span>${record.tare} KG</span></div><div class="row"><b>Deduction:</b><span>${record.deduction||0} KG</span></div><div class="row net"><b>Net:</b><span>${record.net} KG</span></div><div class="line"></div><div><b>Date:</b> ${escapeHtml(record.date||new Date().toLocaleString())}</div><div class="footer">${escapeHtml((window.SHAHZADA_V5_SETTINGS||{}).company_address||"")}<br>${escapeHtml((window.SHAHZADA_V5_SETTINGS||{}).company_phone||"")}<br>${escapeHtml((window.SHAHZADA_V5_SETTINGS||{}).company_footer||"Thank you")}</div><script>window.onload=function(){window.print();}<\/script></body></html>`);win.document.close();}

function renderNetChart(rows){
    const grouped = {};
    rows.forEach(r => {
        const day = String(r.created_at || "").slice(0,10);
        grouped[day] = (grouped[day] || 0) + Number(r.net_weight || 0);
    });
    const labels = Object.keys(grouped).sort();
    const values = labels.map(k => grouped[k]);
    const ctx = document.getElementById("netChart");
    if(netChartInstance) netChartInstance.destroy();
    if(typeof Chart === "undefined") return;
    netChartInstance = new Chart(ctx, {
        type:"bar",
        data:{labels, datasets:[{label:"Net Weight (KG)", data:values}]},
        options:{responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}}
    });
}

document.getElementById("reportTo")?.addEventListener("change", () => {
    const from = document.getElementById("reportFrom").value;
    const to = document.getElementById("reportTo").value;
    if (from && to && from > to) document.getElementById("reportFrom").value = to;
});
document.getElementById("runReport")?.addEventListener("click", loadReport);
document.querySelectorAll("[data-report-range]").forEach(btn => {
    btn.addEventListener("click", () => setReportRange(btn.dataset.reportRange));
});
document.getElementById("clearReport")?.addEventListener("click", () => {
    ["reportFrom","reportTo","reportTruck","reportCustomer"].forEach(id => document.getElementById(id).value = "");
    loadReport();
});
document.getElementById("exportReport")?.addEventListener("click", () => {
    const p = new URLSearchParams({
        action:"export_csv",
        from:document.getElementById("reportFrom").value,
        to:document.getElementById("reportTo").value,
        truck:document.getElementById("reportTruck").value.trim(),
        customer:document.getElementById("reportCustomer").value.trim()
    });
    window.location.href = window.location.pathname + "?" + p.toString();
});
document.getElementById("printReport")?.addEventListener("click", () => {
    const table = document.getElementById("reportTable").outerHTML;
    const title = "Shahzada Weighbridge — Report";
    const w = window.open("", "_blank", "width=1100,height=800");
    w.document.write(`<html><head><title>${title}</title><style>
      body{font-family:Arial,sans-serif;padding:30px;color:#111}h1{margin-bottom:4px}
      table{border-collapse:collapse;width:100%;margin-top:20px}th,td{border:1px solid #ddd;padding:8px;text-align:left}
      th{background:#f3f4f6}.meta{color:#666}
    </style></head><body><h1>${title}</h1><div class="meta">Generated: ${new Date().toLocaleString()}</div>${table}</body></html>`);
    w.document.close(); w.focus(); setTimeout(()=>w.print(),300);
});

setReportRange("today");
</script>
</body>
</html>