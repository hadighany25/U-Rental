<?php
ob_start(); 
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: auth.php");
    exit();
}
// បញ្ឈប់ការចាក់សោរ Session ពេលមាន AJAX ទាញទិន្នន័យ (ការពារការគាំង និង Logout ឯងៗ)
session_write_close(); 

include 'config.php';

// Auto Add Columns សម្រាប់ Payment និង Chart បើមិនទាន់មាន
$check_col = $conn->query("SHOW COLUMNS FROM bookings LIKE 'payment_status'");
if($check_col->num_rows == 0) {
    $conn->query("ALTER TABLE bookings ADD COLUMN payment_status ENUM('unpaid', 'paid') DEFAULT 'unpaid'");
    $conn->query("ALTER TABLE bookings ADD COLUMN payment_method VARCHAR(50) DEFAULT 'cash'");
}

// 👉 បន្ថែម Auto-Column សម្រាប់ថ្ងៃកក់ (created_at) ដើម្បីឱ្យក្រាហ្វទាញទិន្នន័យបាន
$check_date = $conn->query("SHOW COLUMNS FROM bookings LIKE 'created_at'");
if($check_date->num_rows == 0) {
    $conn->query("ALTER TABLE bookings ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

$toast_msg = "";
$toast_type = "success";

if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'added') $toast_msg = "✅ បង្កើតការកក់ជោគជ័យ!";
    if ($_GET['msg'] == 'error') { $toast_msg = "❌ បរាជ័យ! បន្ទប់នេះមានគេកក់ជាន់ម៉ោងគ្នា។"; $toast_type = "error"; }
    if ($_GET['msg'] == 'deleted') $toast_msg = "🗑️ លុបទិន្នន័យចេញពីប្រព័ន្ធជោគជ័យ!";
    if ($_GET['msg'] == 'updated') $toast_msg = "✏️ ទិន្នន័យត្រូវបានធ្វើបច្ចុប្បន្នភាព!";
    if ($_GET['msg'] == 'paid') $toast_msg = "✅ ទូទាត់ប្រាក់ជោគជ័យ!";
}

// ១. មុខងារកក់បន្ទប់ថ្មី (ទប់ស្កាត់ការជាន់គ្នា & Redirect ទៅ POS)
if (isset($_POST['admin_book'])) {
    $room_id = $_POST['room_id'];
    $tenant_name = trim($_POST['tenant_name']);
    $tenant_phone = trim($_POST['tenant_phone']);
    $check_in = $_POST['check_in_time'];
    $check_out = $_POST['check_out_time'];
    $status = $_POST['status']; 
    $payment_method = $_POST['payment_method'];
    $payment_status = $_POST['payment_status'];

    $stmt_check = $conn->prepare("SELECT id FROM bookings WHERE room_id = ? AND status != 'checked_out' AND (? < check_out_time AND ? > check_in_time)");
    $stmt_check->bind_param("iss", $room_id, $check_in, $check_out);
    $stmt_check->execute();
    
    if ($stmt_check->get_result()->num_rows > 0) {
        header("Location: admin.php?msg=error"); exit();
    } else {
        $stmt_book = $conn->prepare("INSERT INTO bookings (room_id, tenant_name, tenant_phone, check_in_time, check_out_time, status, payment_method, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_book->bind_param("isssssss", $room_id, $tenant_name, $tenant_phone, $check_in, $check_out, $status, $payment_method, $payment_status);
        if ($stmt_book->execute()) {
            
            $new_booking_id = $conn->insert_id; 

            if ($status === 'checked_in') {
                $conn->query("UPDATE rooms SET status='occupied' WHERE id=$room_id");
            }

            // ជូនដំណឹងទៅ Telegram
            $msg_tele = "🔔 ការកក់ថ្មីពី Admin (New Booking):\n" .
                        "🏢 បន្ទប់លេខ: " . $room_id . "\n" .
                        "👤 ឈ្មោះភ្ញៀវ: " . $tenant_name . "\n" .
                        "📱 លេខទូរស័ព្ទ: " . $tenant_phone . "\n" .
                        "📅 ថ្ងៃចូល: " . $check_in . "\n" .
                        "🌐 ស្ថានភាព: " . $status;
    
            if(function_exists('sendTelegramMessage')) {
                sendTelegramMessage($msg_tele);
            }
            
            if ($payment_status === 'unpaid') {
                header("Location: paymentadmin.php?id=" . $new_booking_id);
                exit();
            } else {
                header("Location: admin.php?msg=added"); 
                exit();
            }
        }
    }
}

// ២. មុខងារប្តូរស្ថានភាព និង Edit
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    if (isset($_GET['room_id'])) {
        $room_id = intval($_GET['room_id']);
        if ($_GET['action'] == 'checkin') {
            $conn->query("UPDATE bookings SET status='checked_in' WHERE id=$id");
            $conn->query("UPDATE rooms SET status='occupied' WHERE id=$room_id");
        } elseif ($_GET['action'] == 'checkout') {
            $conn->query("UPDATE bookings SET status='checked_out' WHERE id=$id");
            $conn->query("UPDATE rooms SET status='available' WHERE id=$room_id");
        }
        header("Location: admin.php?msg=updated"); exit();
    }
}

// ==========================================
// មុខងារបន្ថែមប្រភេទបន្ទប់ (Room Type)
// ==========================================
if (isset($_POST['add_room_type'])) {
    $type_name = trim($_POST['type_name']);
    $stmt = $conn->prepare("INSERT INTO room_types (type_name) VALUES (?)");
    $stmt->bind_param("s", $type_name);
    if ($stmt->execute()) {
        header("Location: admin.php?msg=type_added"); exit();
    }
}

// ==========================================
// មុខងារបន្ថែមបន្ទប់ថ្មី (New Room)
// ==========================================
if (isset($_POST['add_room'])) {
    $room_number = trim($_POST['room_number']);
    $type_id = intval($_POST['type_id']);
    $price = floatval($_POST['price']);
    
    $check = $conn->query("SELECT id FROM rooms WHERE room_number = '$room_number'");
    if ($check->num_rows > 0) {
        header("Location: admin.php?msg=room_exists"); exit();
    } else {
        $stmt = $conn->prepare("INSERT INTO rooms (room_number, type_id, price, status) VALUES (?, ?, ?, 'available')");
        $stmt->bind_param("sid", $room_number, $type_id, $price);
        if ($stmt->execute()) {
            header("Location: admin.php?msg=room_added"); exit();
        }
    }
}

// ==========================================
// មុខងារកែប្រែ និងលុប (Edit & Delete)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'del_room' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $conn->query("DELETE FROM rooms WHERE id = $id");
    header("Location: admin.php?msg=deleted"); exit();
}

if (isset($_GET['action']) && $_GET['action'] == 'del_type' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $conn->query("DELETE FROM room_types WHERE id = $id");
    header("Location: admin.php?msg=deleted"); exit();
}

if (isset($_POST['edit_room_btn'])) {
    $id = intval($_POST['edit_room_id']);
    $room_number = trim($_POST['edit_room_number']);
    $type_id = intval($_POST['edit_type_id']);
    $price = floatval($_POST['edit_price']);
    
    $stmt = $conn->prepare("UPDATE rooms SET room_number = ?, type_id = ?, price = ? WHERE id = ?");
    $stmt->bind_param("sidi", $room_number, $type_id, $price, $id);
    if ($stmt->execute()) {
        header("Location: admin.php?msg=updated"); exit();
    }
}

if (isset($_POST['edit_type_btn'])) {
    $id = intval($_POST['edit_type_id']);
    $name = trim($_POST['edit_type_name']);
    
    $stmt = $conn->prepare("UPDATE room_types SET type_name = ? WHERE id = ?");
    $stmt->bind_param("si", $name, $id);
    if ($stmt->execute()) {
        header("Location: admin.php?msg=updated"); exit();
    }
}

if (isset($_POST['edit_booking'])) {
    $edit_id = intval($_POST['edit_id']);
    $edit_name = trim($_POST['edit_name']);
    $edit_phone = trim($_POST['edit_phone']);
    $edit_room = intval($_POST['edit_room_id']);
    $stmt = $conn->prepare("UPDATE bookings SET tenant_name=?, tenant_phone=?, room_id=? WHERE id=?");
    $stmt->bind_param("ssii", $edit_name, $edit_phone, $edit_room, $edit_id);
    $stmt->execute();
    header("Location: admin.php?msg=updated"); exit();
}

if (isset($_GET['delete_id'])) {
    $id = intval($_GET['delete_id']);
    $conn->query("DELETE FROM bookings WHERE id = $id");
    header("Location: admin.php?msg=deleted"); exit();
}

// ទាញយកទិន្នន័យ
$rooms_list = $conn->query("SELECT r.*, t.type_name FROM rooms r LEFT JOIN room_types t ON r.type_id = t.id ORDER BY r.room_number ASC");
$bookings_list = $conn->query("SELECT b.*, r.room_number, t.type_name, r.price FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN room_types t ON r.type_id = t.id WHERE b.status != 'checked_out' ORDER BY b.id DESC");

$floor_plan_query = $conn->query("
    SELECT r.*, t.type_name, 
           (SELECT b.tenant_name FROM bookings b WHERE b.room_id = r.id AND b.status != 'checked_out' ORDER BY b.id DESC LIMIT 1) as guest_name,
           (SELECT b.tenant_phone FROM bookings b WHERE b.room_id = r.id AND b.status != 'checked_out' ORDER BY b.id DESC LIMIT 1) as guest_phone,
           (SELECT b.check_in_time FROM bookings b WHERE b.room_id = r.id AND b.status != 'checked_out' ORDER BY b.id DESC LIMIT 1) as in_time,
           (SELECT b.check_out_time FROM bookings b WHERE b.room_id = r.id AND b.status != 'checked_out' ORDER BY b.id DESC LIMIT 1) as out_time
    FROM rooms r LEFT JOIN room_types t ON r.type_id = t.id ORDER BY r.room_number ASC
");

// ស្ថិតិ
$total_rooms = $rooms_list->num_rows;
$occupied_rooms = $conn->query("SELECT COUNT(*) as c FROM rooms WHERE status='occupied'")->fetch_assoc()['c'];
$pending_bookings = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE status='booked'")->fetch_assoc()['c'];
$revenue = $conn->query("SELECT SUM(r.price) as total FROM bookings b JOIN rooms r ON b.room_id = r.id WHERE b.payment_status='paid'")->fetch_assoc()['total'];

// 👉 កន្លែងកែថ្មី៖ ការទាញទិន្នន័យក្រាហ្វ (Chart Data) មានសុវត្ថិភាពជាងមុន
$chart_labels = []; $chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('D', strtotime($date)); 
    // ការពារកុំឱ្យ Error បើ Table អត់មាន Data
    $query = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE DATE(created_at) = '$date'");
    $chart_data[] = $query ? $query->fetch_assoc()['c'] : 0;
}

// 👉 កន្លែងកែថ្មី៖ យក id DESC ដើម្បីឱ្យអ្នកកក់ក្រោយគេ លោតមកលើគេជានិច្ច
$recent_activities = $conn->query("SELECT b.tenant_name, b.status, COALESCE(b.created_at, b.check_in_time) as created_at FROM bookings b ORDER BY b.id DESC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Admin - U-Rental</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Kantumruy+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    
    <style>
        body { font-family: 'Inter', 'Kantumruy Pro', sans-serif; background-color: #F8FAFC; color: #0F172A; }
        .tab-content { display: none; opacity: 0; transform: translateY(10px); transition: all 0.3s ease; }
        .tab-content.active { display: block; opacity: 1; transform: translateY(0); }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94A3B8; }
        
        /* Live Validation Banner */
        .live-error-banner { display: none; background: #FEF2F2; color: #DC2626; border: 1px solid #FCA5A5; padding: 12px; border-radius: 8px; margin-top: 15px; font-weight: 600; font-size: 13px; }
        .slot-tag { background: #fff; border: 1px solid #FCD34D; padding: 4px 8px; border-radius: 4px; display: inline-block; font-size: 11px; margin: 3px; color: #D97706; font-weight: 600; }

        /* Clean Table */
        .clean-table th { background: transparent; color: #64748B; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #E2E8F0; padding-bottom: 12px; }
        .clean-table td { border-bottom: 1px dashed #F1F5F9; padding: 16px 12px; }
        .clean-table tr:hover td { background: #F8FAFC; }

        /* Print Settings */
        @media print {
            body * { visibility: hidden !important; }
            #printableBoardingPass, #printableBoardingPass * { visibility: visible !important; }
            #printableBoardingPass { position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%); width: 100%; max-width:850px; z-index: 9999; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <div id="toastAlert" class="fixed top-6 right-6 z-50 px-6 py-4 rounded-xl shadow-2xl flex items-center gap-3 transition-all duration-500 transform translate-x-full opacity-0 <?php echo $toast_type=='error' ? 'bg-red-600' : 'bg-slate-800'; ?> text-white">
        <span class="font-medium text-sm"><?php echo $toast_msg; ?></span>
    </div>
    <?php if ($toast_msg): ?>
        <script>
            setTimeout(() => { 
                let t = document.getElementById('toastAlert');
                t.classList.remove('translate-x-full', 'opacity-0');
                setTimeout(() => { t.classList.add('translate-x-full', 'opacity-0'); }, 3000);
            }, 100);
        </script>
    <?php endif; ?>

    <div id="customConfirmModal" class="fixed inset-0 z-[100] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300 no-print">
        <div class="bg-slate-900/60 backdrop-blur-sm absolute inset-0" onclick="closeConfirm()"></div>
        <div class="bg-white rounded-2xl p-8 relative z-10 w-[90%] max-w-sm shadow-2xl scale-95 transition-transform duration-300" id="confirmBox">
            <div id="confirmIcon" class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">❓</div>
            <h3 class="text-xl font-bold text-center text-slate-800 mb-2" id="confirmTitle">Confirm Action</h3>
            <p class="text-center text-slate-500 text-sm font-medium mb-6" id="confirmText">Are you sure?</p>
            <div class="flex justify-center gap-3">
                <button onclick="closeConfirm()" class="px-5 py-2.5 rounded-xl text-sm font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 w-full transition">Cancel</button>
                <a href="#" id="confirmBtn" class="px-5 py-2.5 rounded-xl text-sm font-bold text-white w-full text-center shadow-lg transition">Yes, Do it</a>
            </div>
        </div>
    </div>

    <aside class="w-[260px] bg-[#0B1120] text-slate-400 flex flex-col no-print z-20">
        <div class="h-20 flex items-center px-8 border-b border-slate-800/50">
            <h1 class="text-2xl font-bold text-white"><span class="text-[#3B82F6]">U-</span>Rental</h1>
        </div>
        <div class="flex-1 overflow-y-auto py-6 px-4 space-y-8">
            <div>
                <p class="px-4 text-[11px] font-bold tracking-[0.2em] text-slate-500 uppercase mb-3">Main Menu</p>
                <div class="space-y-1">
                    <button onclick="switchTab('overview')" id="nav-overview" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition text-sm font-medium hover:text-white hover:bg-white/5"><svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg> Overview</button>
                    <button onclick="switchTab('booking')" id="nav-booking" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition text-sm font-medium hover:text-white hover:bg-white/5"><svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg> Booking</button>
                    <button onclick="switchTab('floorplan')" id="nav-floorplan" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition text-sm font-medium hover:text-white hover:bg-white/5"><svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5z"></path></svg> Floorplan</button>
                    <button onclick="switchTab('guestlist')" id="nav-guestlist" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition text-sm font-medium hover:text-white hover:bg-white/5"><svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg> Guest Database</button>
                </div>
            </div>
            <div>
                <p class="px-4 text-[11px] font-bold tracking-[0.2em] text-slate-500 uppercase mb-3">Security & Support</p>
                <div class="space-y-1">
                    <button onclick="showSupportInfo()" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition text-sm font-medium hover:text-white hover:bg-white/5"><svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"></path></svg> contacts</button>
                </div>
            </div>
        </div>
        <div class="p-4 border-t border-slate-800/50">
            <a href="#" onclick="event.preventDefault(); triggerConfirm('logout', 'auth.php?action=logout');" class="flex items-center justify-center gap-2 w-full px-4 py-3 text-slate-400 hover:text-white rounded-xl transition duration-300 font-bold text-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg> logout
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto">
        <header class="h-20 bg-white px-8 flex justify-between items-center sticky top-0 z-10 border-b border-slate-100 no-print">
            <div class="flex items-center gap-4">
                <span class="flex items-center gap-2 bg-emerald-50 text-emerald-600 px-3 py-1.5 rounded-full text-[11px] border border-emerald-100 font-bold"><span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span> Live Sync</span>
                <span class="text-slate-400 text-sm pl-4 border-l border-slate-200">ម៉ោងបច្ចុប្បន្នភាពចុងក្រោយ</span>
            </div>
            <div class="flex items-center gap-4 font-bold text-sm text-slate-700">
                <span class="text-slate-400 font-normal mr-2">មាតិកាក្រោយការ</span>
                <div class="w-8 h-8 rounded-full bg-slate-200 flex items-center justify-center text-slate-600 overflow-hidden"><img src="https://ui-avatars.com/api/?name=<?php echo urlencode(isset($_SESSION['fullname']) ? $_SESSION['fullname'] : 'Admin'); ?>&background=E2E8F0&color=475569" alt="User"></div>
                <?php echo isset($_SESSION['fullname']) ? htmlspecialchars($_SESSION['fullname']) : 'Admin'; ?>
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
            </div>
        </header>

        <div class="p-8">
            <div id="overview" class="tab-content active">
                <h2 class="text-xl font-bold text-slate-800 mb-6" id="headerTitle">Platform Overview</h2>
                
                <div class="bg-[#EEF2FF] rounded-[16px] p-6 mb-6 flex flex-col md:flex-row items-center justify-between border border-[#E0E7FF] relative overflow-hidden">
                    <div class="relative z-10 text-center md:text-left mb-4 md:mb-0">
                        <h2 class="text-slate-800 text-lg font-bold mb-1">ពិនិត្យសំបុត្រភ្ញៀវយ៉ាងរហ័ស</h2>
                        <p class="text-slate-600 text-[13px] mb-4">មានអ្នកកក់ជាច្រើនយប់ក្នុងកាលវិភាគ Verify Guest Ticket Quickly</p>
                        <button onclick="openScanner()" class="bg-[#2563EB] hover:bg-blue-700 text-white font-bold px-5 py-2 rounded-xl shadow-md transition text-xs">
                            Open Scanner
                        </button>
                    </div>
                    <div class="relative z-10 hidden md:block">
                        <div class="flex items-center gap-3">
                            <div class="bg-white border border-slate-200 rounded-xl shadow-sm rotate-[-15deg] transform translate-x-6 overflow-hidden w-28 opacity-80">
                                <div class="bg-[#E11D48] h-3 w-full"></div>
                                <div class="p-2 text-center">
                                    <div class="text-[8px] font-bold text-slate-400 mb-1">U-RENTAL</div>
                                    <div class="text-xs font-black tracking-[0.2em] text-slate-800">||| | |||</div>
                                </div>
                            </div>
                            <div class="bg-white border border-slate-200 rounded-xl shadow-lg relative z-10 overflow-hidden w-36">
                                <div class="bg-[#E11D48] text-white text-[8px] font-bold px-2 py-1 flex justify-between">
                                    <span>BOOKING</span><span>U-RENTAL</span>
                                </div>
                                <div class="p-3 text-center">
                                    <div class="text-[10px] font-bold text-slate-800 mb-1">Verify Ticket</div>
                                    <div class="text-sm font-black tracking-[0.2em] text-slate-800">|| |||| ||</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-[16px] border border-slate-200 p-6 shadow-sm hover:shadow-md transition">
                        <h3 class="text-[13px] font-medium text-slate-500 mb-2">Total Rooms</h3>
                        <div class="text-3xl font-bold text-slate-800"><?php echo $total_rooms; ?></div>
                    </div>
                    <div class="bg-white rounded-[16px] border border-slate-200 p-6 shadow-sm hover:shadow-md transition">
                        <h3 class="text-[13px] font-medium text-slate-500 mb-2">Active Stays</h3>
                        <div class="text-3xl font-bold text-slate-800"><?php echo $occupied_rooms; ?></div>
                    </div>
                    <div class="bg-white rounded-[16px] border border-slate-200 p-6 shadow-sm hover:shadow-md transition">
                        <h3 class="text-[13px] font-medium text-slate-500 mb-2">Pending</h3>
                        <div class="text-3xl font-bold text-slate-800"><?php echo $pending_bookings; ?></div>
                    </div>
                    <div class="bg-white rounded-[16px] border border-slate-200 p-6 shadow-sm hover:shadow-md transition">
                        <h3 class="text-[13px] font-medium text-slate-500 mb-2">Revenue</h3>
                        <div class="text-3xl font-bold text-slate-800">$<?php echo $revenue ? number_format($revenue,2) : "0.00"; ?></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white rounded-[16px] border border-slate-200 shadow-sm p-6">
                        <h3 class="text-sm font-bold text-slate-800 mb-6">Volume (Last 7 Days)</h3>
                        <div class="h-64 w-full"><canvas id="realBookingChart"></canvas></div>
                    </div>
                    <div class="bg-white rounded-[16px] border border-slate-200 shadow-sm p-6">
                        <h3 class="text-sm font-bold text-slate-800 mb-6">Recent Activity</h3>
                        <div class="relative pl-4 border-l border-slate-200 ml-2 space-y-6">
                            <?php if($recent_activities && $recent_activities->num_rows > 0): while($act = $recent_activities->fetch_assoc()): 
                                $color = $act['status'] == 'checked_in' ? '#10B981' : ($act['status'] == 'booked' ? '#3B82F6' : '#E2E8F0');
                            ?>
                            <div class="relative">
                                <div class="absolute -left-[21px] top-1 w-2.5 h-2.5 rounded-full" style="background: <?php echo $color; ?>; box-shadow: 0 0 0 4px white;"></div>
                                <div class="text-sm font-medium text-slate-700">មានការកក់ពី <?php echo htmlspecialchars($act['tenant_name']); ?></div>
                                <div class="text-[11px] text-slate-400 mt-1"><?php echo $act['created_at'] ? date('d M, h:i A', strtotime($act['created_at'])) : 'ថ្មីៗនេះ'; ?></div>
                            </div>
                            <?php endwhile; else: ?>
                            <div class="text-sm text-slate-400 italic">No recent activities.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div id="booking" class="tab-content">
                <div class="bg-white rounded-[24px] shadow-sm border border-slate-100 p-8 lg:p-10">
                    <div class="flex items-center gap-4 mb-8 border-b border-slate-100 pb-6">
                        <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center text-2xl shadow-inner">📝</div>
                        <div>
                            <h2 class="text-2xl font-black text-slate-800">បង្កើតការកក់ថ្មី</h2>
                            <p class="text-sm text-slate-500 font-medium mt-1">បំពេញព័ត៌មានភ្ញៀវ និងជ្រើសរើសជម្រើសទូទាត់</p>
                        </div>
                    </div>
                    
                    <form method="POST" id="bookingForm" class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">ជ្រើសរើសបន្ទប់ទំនេរ (Room Unit)</label>
                            <div class="relative">
                                <select name="room_id" id="roomSelect" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl pl-4 pr-10 py-3.5 outline-none focus:bg-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all font-bold text-slate-700 appearance-none cursor-pointer" required onchange="fetchBookedSlots(); validateLiveDates();">
                                    <option value="" disabled selected>-- ចុចជ្រើសរើសបន្ទប់ --</option>
                                    <?php $rooms_list->data_seek(0); while($r = $rooms_list->fetch_assoc()): ?>
                                        <option value='<?php echo $r['id']; ?>' data-price='<?php echo $r['price']; ?>'>🚪 បន្ទប់ <?php echo $r['room_number']; ?> &nbsp;•&nbsp; <?php echo $r['type_name']; ?> &nbsp;•&nbsp; ($<?php echo $r['price']; ?>/ថ្ងៃ)</option>
                                    <?php endwhile; ?>
                                </select>
                                <div class="absolute inset-y-0 right-4 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </div>
                            </div>
                            <div id="liveStatusBox" class="mt-3 hidden bg-amber-50 border border-amber-200 p-4 rounded-xl shadow-sm">
                                <h4 class="text-xs font-bold text-amber-800 mb-2 flex items-center gap-2"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg> ម៉ោងដែលមានគេកក់រួចហើយ:</h4>
                                <div id="slotsContainer" class="flex flex-wrap gap-2"></div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">ឈ្មោះភ្ញៀវ (Guest Name)</label>
                            <input type="text" name="tenant_name" placeholder="ឧ. Sok Dara" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3.5 outline-none focus:bg-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all font-medium text-slate-700" required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">លេខទូរស័ព្ទ (Phone)</label>
                            <input type="text" name="tenant_phone" placeholder="012 345 678" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3.5 outline-none focus:bg-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition-all font-medium text-slate-700" required>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">ថ្ងៃចូល (Check-In)</label>
                            <input type="datetime-local" name="check_in_time" id="checkInInput" onchange="validateLiveDates()" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3.5 outline-none focus:bg-white focus:border-blue-500 transition-all font-bold text-slate-700" required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">ថ្ងៃចេញ (Check-Out)</label>
                            <input type="datetime-local" name="check_out_time" id="checkOutInput" onchange="validateLiveDates()" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3.5 outline-none focus:bg-white focus:border-blue-500 transition-all font-bold text-slate-700" required>
                        </div>
                        
                        <div class="md:col-span-2 bg-[#F8FAFC] p-6 rounded-2xl border border-slate-200 mt-2">
                            <h3 class="text-sm font-black text-slate-800 mb-5 flex items-center gap-2">
                                <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                                ផ្នែកទូទាត់ប្រាក់ (Payment Details)
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">វិធីសាស្ត្រ (Method)</label>
                                    <select name="payment_method" id="paymentMethod" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 font-bold text-slate-700 shadow-sm" required>
                                        <option value="cash">💵 ទូទាត់សាច់ប្រាក់ (Cash)</option>
                                        <option value="online">💳 U-Pay / Online</option>
                                        <option value="card">💳 ឆូតកាត (Card Swipe)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">ស្ថានភាព (Status)</label>
                                    <select name="payment_status" id="paymentStatusSelect" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 font-bold text-slate-700 shadow-sm" required>
                                        <option value="unpaid" class="text-red-500 font-bold">❌ មិនទាន់បង់ (បន្តទៅម៉ាស៊ីន POS)</option>
                                        <option value="paid" class="text-emerald-600 font-bold">✅ បង់រួចរាល់ (Paid)</option>
                                    </select>
                                </div>
                                
                                <div class="md:col-span-2">
                                    <div id="paymentNote" class="bg-white p-5 rounded-xl border-l-4 border-blue-500 text-sm font-medium text-slate-600 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
                                        <div class="flex items-center gap-3">
                                            <span class="text-2xl">💡</span>
                                            <span>សូមជ្រើសរើសបន្ទប់ និងម៉ោងចេញចូលដើម្បីប្រព័ន្ធគណនាតម្លៃ។</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">ស្ថានភាពកក់ (Booking Status)</label>
                            <select name="status" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-3.5 outline-none focus:bg-white focus:border-blue-500 font-bold text-slate-700" required>
                                <option value="booked">📅 កក់ទុកមុន (Advance Booking)</option>
                                <option value="checked_in">📥 ចូលស្នាក់នៅភ្លាម (Instant Check-In)</option>
                            </select>
                        </div>
                        
                        <div id="liveErrorBanner" class="live-error-banner md:col-span-2 bg-red-50 border border-red-200 text-red-600 p-4 rounded-xl text-center shadow-sm font-bold flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                            ម៉ោងនេះមានគេកក់រួចហើយ! សូមប្តូរម៉ោងថ្មីដើម្បីអាចបន្តបាន។
                        </div>

                        <div class="md:col-span-2 mt-4">
                            <button type="submit" name="admin_book" id="submitBookBtn" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-xl shadow-[0_10px_20px_-10px_rgba(37,99,235,0.5)] transition-all transform hover:-translate-y-1 text-base flex justify-center items-center gap-2">
                                បន្តទៅកាន់ម៉ាស៊ីន POS (Pay Now) <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="floorplan" class="tab-content">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-white rounded-[16px] shadow-sm border border-slate-100 p-6 flex flex-col max-h-[300px]">
                        <h3 class="text-sm font-bold text-slate-800 mb-4 border-l-4 border-blue-500 pl-3">គ្រប់គ្រងប្រភេទបន្ទប់</h3>
                        <form method="POST" class="flex gap-2 mb-4">
                            <input type="text" name="type_name" placeholder="បញ្ចូលប្រភេទថ្មី..." class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 outline-none focus:border-blue-500 text-sm" required>
                            <button type="submit" name="add_room_type" class="bg-[#0B1120] hover:bg-slate-800 text-white font-bold px-4 py-2 rounded-xl shadow transition text-xs">បញ្ចូល</button>
                        </form>
                        <div class="flex-1 overflow-y-auto pr-2">
                            <table class="w-full text-left text-sm">
                                <tbody class="divide-y divide-slate-100">
                                    <?php 
                                        $types_list = $conn->query("SELECT * FROM room_types ORDER BY id DESC");
                                        while($t = $types_list->fetch_assoc()): 
                                    ?>
                                    <tr class="hover:bg-slate-50 group">
                                        <td class="py-2 font-medium text-slate-700"><?php echo $t['type_name']; ?></td>
                                        <td class="py-2 text-right opacity-0 group-hover:opacity-100 transition-opacity">
                                            <button onclick="openEditTypeModal('<?php echo $t['id']; ?>', '<?php echo $t['type_name']; ?>')" class="text-blue-500 hover:text-blue-700 px-2">✏️</button>
                                            <a href="admin.php?action=del_type&id=<?php echo $t['id']; ?>" onclick="return confirm('តើអ្នកពិតជាចង់លុបប្រភេទនេះមែនទេ?');" class="text-red-500 hover:text-red-700">🗑️</a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-white rounded-[16px] shadow-sm border border-slate-100 p-6">
                        <h3 class="text-sm font-bold text-slate-800 mb-4 border-l-4 border-emerald-500 pl-3">បញ្ចូលបន្ទប់ថ្មី</h3>
                        <form method="POST" class="flex flex-col gap-4">
                            <div class="flex gap-3">
                                <input type="text" name="room_number" placeholder="លេខបន្ទប់ (ឧ. A01)" class="w-1/2 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 outline-none focus:border-emerald-500 text-sm" required>
                                <input type="number" step="0.01" name="price" placeholder="តម្លៃ ($)" class="w-1/2 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 outline-none focus:border-emerald-500 text-sm" required>
                            </div>
                            <div class="flex gap-3">
                                <select name="type_id" class="flex-1 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 outline-none focus:border-emerald-500 text-sm" required>
                                    <option value="" disabled selected>-- ជ្រើសរើសប្រភេទ --</option>
                                    <?php 
                                        $types_list->data_seek(0);
                                        while($t = $types_list->fetch_assoc()) echo "<option value='{$t['id']}'>{$t['type_name']}</option>"; 
                                    ?>
                                </select>
                                <button type="submit" name="add_room" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-6 py-2.5 rounded-xl shadow-md transition text-sm">រក្សាទុក</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="bg-white rounded-[16px] shadow-sm border border-slate-100 p-8">
                    <h2 class="text-xl font-bold text-slate-800 mb-6 border-l-4 border-slate-500 pl-3">Interactive Floor Plan</h2>
                    <div id="floorplanGrid" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-6">
                        <?php $floor_plan_query->data_seek(0); while($r = $floor_plan_query->fetch_assoc()): 
                            if (empty($r['guest_name'])) {
                                $bg = 'bg-emerald-50 border-emerald-200 text-emerald-700 hover:bg-emerald-100';
                                $onclick = "gotoBooking('{$r['id']}')";
                                $status_dot = ''; 
                            } else {
                                $bg = 'bg-red-50 border-red-200 text-red-700 hover:bg-red-100';
                                $onclick = "openRoomDetails('{$r['room_number']}', '".htmlspecialchars($r['guest_name'])."', '{$r['guest_phone']}', '".date('M d, H:i', strtotime($r['in_time']))."', '".date('M d, H:i', strtotime($r['out_time']))."')";
                                $status_dot = '<span class="absolute top-3 right-3 flex h-3 w-3"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span></span>';
                            }
                        ?>
                        <div onclick="<?php echo $onclick; ?>" class="border-2 rounded-2xl p-6 <?php echo $bg; ?> flex flex-col items-center justify-center relative transition transform cursor-pointer group shadow-sm hover:shadow-md">
                            
                            <div class="absolute top-2 left-2 flex gap-1 z-20 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onclick="event.stopPropagation(); openEditRoomModal('<?php echo $r['id']; ?>', '<?php echo $r['room_number']; ?>', '<?php echo $r['type_id']; ?>', '<?php echo $r['price']; ?>')" class="bg-white text-blue-500 hover:text-blue-700 w-7 h-7 rounded-lg shadow flex items-center justify-center text-xs">✏️</button>
                                <a href="admin.php?action=del_room&id=<?php echo $r['id']; ?>" onclick="event.stopPropagation(); return confirm('តើអ្នកពិតជាចង់លុបបន្ទប់នេះមែនទេ? (ទិន្នន័យប្រវត្តិការជួលអាចនឹងបាត់បង់)');" class="bg-white text-red-500 hover:text-red-700 w-7 h-7 rounded-lg shadow flex items-center justify-center text-xs">🗑️</a>
                            </div>

                            <?php echo $status_dot; ?>
                            <span class="text-3xl font-black tracking-tight"><?php echo $r['room_number']; ?></span>
                            <span class="text-[10px] font-bold uppercase mt-2 px-2 py-1 bg-white/50 rounded-lg"><?php echo $r['type_name'] ?? 'N/A'; ?> <span class="text-slate-400">($<?php echo $r['price']; ?>)</span></span>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        <div id="guestlist" class="tab-content">
                <div class="bg-white rounded-[16px] shadow-sm border border-slate-100 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex flex-col md:flex-row justify-between items-center bg-white gap-4">
                        <h2 class="text-lg font-bold text-slate-800">Guest Database</h2>
                        <div class="flex gap-2">
                            <button onclick="openScanner()" class="bg-[#0B1120] text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 hover:bg-slate-800 transition shadow-sm">
                                📷 Scan
                            </button>
                            <input type="text" id="searchInput" onkeyup="searchTable()" placeholder="Search Code, Name..." class="bg-slate-50 border border-slate-200 rounded-lg px-4 py-2 w-64 outline-none text-sm">
                        </div>
                    </div>
                   <div class="overflow-x-auto">
                        <table class="w-full clean-table text-left" id="guestTable">
                            <thead>
                                <tr class="bg-slate-50 text-slate-500 text-[11px] font-bold uppercase tracking-wider">
                                    <th class="px-6 pt-5">Ref & Room</th>
                                    <th class="px-6 pt-5">Guest</th>
                                    <th class="px-6 pt-5">Schedule</th>
                                    <th class="px-6 pt-5">Payment</th>
                                    <th class="px-6 pt-5">Status</th>
                                    <th class="px-6 pt-5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm">
                                <?php $bookings_list->data_seek(0); while($row = $bookings_list->fetch_assoc()): $search_code = "BK-".$row['id']."-".$row['room_number']; ?>
                                <tr>
                                    <td class="px-6">
                                        <div class="font-bold text-slate-800 text-base">Room <?php echo $row['room_number']; ?></div>
                                        <div class="text-[11px] text-blue-500 mt-1 font-mono font-bold"><?php echo $search_code; ?></div>
                                    </td>
                                    <td class="px-6"><div class="font-bold"><?php echo htmlspecialchars($row['tenant_name']); ?></div><div class="text-slate-500 text-xs mt-1"><?php echo htmlspecialchars($row['tenant_phone']); ?></div></td>
                                    <td class="px-6 text-xs text-slate-500"><div class="text-emerald-600">IN: <?php echo date('m/d/y h:i A', strtotime($row['check_in_time'])); ?></div><div class="text-amber-600">OUT: <?php echo date('m/d/y h:i A', strtotime($row['check_out_time'])); ?></div></td>
                                    <td class="px-6">
                                        <div class="mt-2">
                                            <?php if(isset($row['payment_status']) && $row['payment_status'] == 'paid'): ?>
                                                <span class="text-emerald-600 text-[12px] font-bold flex items-center gap-1">
                                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg> Paid
                                                </span>
                                            <?php else: ?>
                                                <span class="text-red-500 text-[12px] font-bold flex items-center gap-1">
                                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg> Unpaid
                                                </span>
                                                <a href="paymentadmin.php?id=<?php echo $row['id']; ?>" class="text-[10px] text-blue-600 font-bold hover:text-blue-800 hover:underline inline-block mt-1 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded transition">🔄 Pay Now</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6">
                                        <div>
                                            <?php 
                                                // --- Logic កំណត់ Status ថ្មី ---
                                                if ($row['status'] == 'checked_out') {
                                                    echo '<span class="bg-slate-50 text-slate-500 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase">Finished</span>';
                                                } elseif ($row['status'] == 'checked_in') {
                                                    echo '<span class="bg-emerald-50 text-emerald-600 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase">Active</span>';
                                                } elseif ($row['payment_status'] == 'unpaid') {
                                                    echo '<span class="bg-red-50 text-red-600 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase">Failed / Pending</span>';
                                                } elseif ($row['status'] == 'booked') {
                                                    echo '<span class="bg-amber-50 text-amber-600 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase">Booked</span>';
                                                } else {
                                                    echo '<span class="bg-slate-50 text-slate-500 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase">Finished</span>';
                                                } 
                                            ?>
                                        </div>
                                    </td>
                                    <td class="px-6 text-right space-x-1">
                                        <button onclick="openBoardingPass('<?php echo htmlspecialchars($row['tenant_name']); ?>','<?php echo htmlspecialchars($row['tenant_phone']); ?>','<?php echo $row['room_number']; ?>','<?php echo htmlspecialchars($row['type_name']); ?>','<?php echo date('M d, H:i', strtotime($row['check_in_time'])); ?>','<?php echo date('M d, H:i', strtotime($row['check_out_time'])); ?>','<?php echo $row['id']; ?>')" class="p-1.5 text-slate-400 hover:text-blue-500 bg-slate-50 hover:bg-blue-50 rounded" title="Ticket">🎫</button>
                                        
                                        <?php if ($row['status'] == 'booked'): ?>
                                            <a href="admin.php?action=checkin&id=<?php echo $row['id']; ?>&room_id=<?php echo $row['room_id']; ?>" class="p-1.5 inline-block text-emerald-500 bg-emerald-50 hover:bg-emerald-100 rounded" title="Check In">📥</a>
                                        <?php elseif ($row['status'] == 'checked_in'): ?>
                                            <a href="admin.php?action=checkout&id=<?php echo $row['id']; ?>&room_id=<?php echo $row['room_id']; ?>" class="p-1.5 inline-block text-amber-500 bg-amber-50 hover:bg-amber-100 rounded" title="Check Out">📤</a>
                                        <?php endif; ?>

                                        <button onclick="openEditModal('<?php echo $row['id']; ?>', '<?php echo htmlspecialchars($row['tenant_name']); ?>', '<?php echo htmlspecialchars($row['tenant_phone']); ?>', '<?php echo $row['room_id']; ?>')" class="p-1.5 text-blue-500 bg-blue-50 hover:bg-blue-100 rounded" title="Edit">✏️</button>
                                        
                                        <a href="admin.php?delete_id=<?php echo $row['id']; ?>" onclick="return confirm('តើអ្នកពិតជាចង់លុបទិន្នន័យនេះមែនទេ?');" class="p-1.5 inline-block text-red-500 bg-red-50 hover:bg-red-100 rounded" title="Delete">🗑️</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>        
        </div>
    </main>

    <div id="editTypeModal" class="fixed inset-0 z-[100] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
        <div class="bg-slate-900/60 backdrop-blur-sm absolute inset-0" onclick="closeEditModals()"></div>
        <div class="bg-white rounded-2xl p-8 relative z-10 w-[90%] max-w-sm shadow-2xl">
            <h3 class="text-lg font-bold text-slate-800 mb-4">កែប្រែប្រភេទបន្ទប់</h3>
            <form method="POST">
                <input type="hidden" name="edit_type_id" id="edit_type_id">
                <div class="mb-4">
                    <label class="block text-xs font-bold text-slate-500 mb-2">ឈ្មោះប្រភេទ</label>
                    <input type="text" name="edit_type_name" id="edit_type_name" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 text-sm" required>
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeEditModals()" class="flex-1 bg-slate-100 text-slate-600 font-bold py-3 rounded-xl transition">បោះបង់</button>
                    <button type="submit" name="edit_type_btn" class="flex-1 bg-[#0B1120] text-white font-bold py-3 rounded-xl shadow transition">រក្សាទុក</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editRoomModal" class="fixed inset-0 z-[100] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
        <div class="bg-slate-900/60 backdrop-blur-sm absolute inset-0" onclick="closeEditModals()"></div>
        <div class="bg-white rounded-2xl p-8 relative z-10 w-[90%] max-w-sm shadow-2xl">
            <h3 class="text-lg font-bold text-slate-800 mb-4">កែប្រែព័ត៌មានបន្ទប់</h3>
            <form method="POST">
                <input type="hidden" name="edit_room_id" id="edit_room_id">
                <div class="mb-4">
                    <label class="block text-xs font-bold text-slate-500 mb-2">លេខបន្ទប់</label>
                    <input type="text" name="edit_room_number" id="edit_room_number" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 text-sm" required>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-bold text-slate-500 mb-2">ប្រភេទបន្ទប់</label>
                    <select name="edit_type_id" id="edit_type_id_select" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 text-sm" required>
                        <?php 
                            $types_list->data_seek(0);
                            while($t = $types_list->fetch_assoc()) echo "<option value='{$t['id']}'>{$t['type_name']}</option>"; 
                        ?>
                    </select>
                </div>
                <div class="mb-6">
                    <label class="block text-xs font-bold text-slate-500 mb-2">តម្លៃប្រចាំថ្ងៃ ($)</label>
                    <input type="number" step="0.01" name="edit_price" id="edit_price" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 text-sm" required>
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="closeEditModals()" class="flex-1 bg-slate-100 text-slate-600 font-bold py-3 rounded-xl transition">បោះបង់</button>
                    <button type="submit" name="edit_room_btn" class="flex-1 bg-[#0B1120] text-white font-bold py-3 rounded-xl shadow transition">រក្សាទុក</button>
                </div>
            </form>
        </div>
    </div>

    <div id="supportModal" class="fixed inset-0 z-[80] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
        <div class="bg-slate-900/60 backdrop-blur-sm absolute inset-0" onclick="closeSupportInfo()"></div>
        <div class="bg-white rounded-2xl p-8 relative z-10 w-[90%] max-w-sm shadow-2xl scale-95 transition-transform" id="supportBox">
            <div class="w-16 h-16 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl font-black">📞</div>
            <h3 class="text-xl font-bold text-center text-slate-800 mb-6">Contact Support</h3>
            <div class="space-y-3 text-sm text-slate-600 font-medium bg-slate-50 p-4 rounded-xl border border-slate-100">
                <div class="flex flex-col border-b pb-2"><span class="text-xs text-slate-400">Email:</span><span class="text-slate-800 font-bold">support@urental.com</span></div>
                <div class="flex flex-col"><span class="text-xs text-slate-400">Hotline:</span><span class="text-blue-600 font-bold text-lg">+855 12 345 678</span></div>
            </div>
            <button onclick="closeSupportInfo()" class="mt-6 w-full bg-slate-800 hover:bg-slate-900 text-white font-bold py-3 rounded-xl transition">Got it!</button>
        </div>
    </div>

    <div id="roomDetailsModal" class="fixed inset-0 z-[80] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
        <div class="bg-slate-900/60 backdrop-blur-sm absolute inset-0" onclick="closeRoomDetails()"></div>
        <div class="bg-white rounded-2xl p-8 relative z-10 w-[90%] max-w-sm shadow-2xl scale-95 transition-transform" id="rdBox">
            <div class="w-16 h-16 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl font-black" id="rd_room"></div>
            <h3 class="text-xl font-bold text-center text-slate-800 mb-6" id="rd_name"></h3>
            <div class="space-y-3 text-sm text-slate-600 font-medium bg-slate-50 p-4 rounded-xl">
                <div class="flex justify-between border-b border-slate-200 pb-2"><span>Phone:</span><span id="rd_phone" class="text-slate-800 font-bold"></span></div>
                <div class="flex justify-between border-b border-slate-200 pb-2"><span>Check-In:</span><span id="rd_in" class="text-emerald-600 font-bold"></span></div>
                <div class="flex justify-between pb-2"><span>Check-Out:</span><span id="rd_out" class="text-amber-600 font-bold"></span></div>
            </div>
            <button onclick="closeRoomDetails()" class="mt-6 w-full bg-slate-800 hover:bg-slate-900 text-white font-bold py-3 rounded-xl transition">Close</button>
        </div>
    </div>

    <div id="editModal" class="fixed inset-0 z-[60] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
        <div class="bg-slate-900/60 backdrop-blur-sm absolute inset-0" onclick="closeEditModal()"></div>
        <div class="bg-white rounded-2xl p-8 relative z-10 w-[90%] max-w-md shadow-2xl scale-95 transition-transform" id="editBox">
            <h3 class="text-lg font-bold text-slate-800 mb-6 border-l-4 border-blue-500 pl-3">Edit Booking Info</h3>
            <form method="POST">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="space-y-4">
                    <div><label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Guest Name</label><input type="text" name="edit_name" id="edit_name" class="w-full border rounded-lg px-4 py-3 outline-none focus:border-blue-500" required></div>
                    <div><label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Phone Number</label><input type="text" name="edit_phone" id="edit_phone" class="w-full border rounded-lg px-4 py-3 outline-none focus:border-blue-500" required></div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Change Room</label>
                        <select name="edit_room_id" id="edit_room_id" class="w-full border rounded-lg px-4 py-3 outline-none focus:border-blue-500" required>
                            <?php $rooms_list->data_seek(0); while($r = $rooms_list->fetch_assoc()) echo "<option value='{$r['id']}'>Room {$r['room_number']}</option>"; ?>
                        </select>
                    </div>
                </div>
                <div class="mt-8 flex justify-end gap-3">
                    <button type="button" onclick="closeEditModal()" class="px-6 py-3 rounded-xl text-sm font-bold text-slate-500 hover:bg-slate-100 transition">Cancel</button>
                    <button type="submit" name="edit_booking" class="px-6 py-3 rounded-xl text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 shadow-md transition">Update Save</button>
                </div>
            </form>
        </div>
    </div>

    <div id="scannerModal" class="fixed inset-0 z-[60] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
        <div class="bg-slate-900/80 backdrop-blur-sm absolute inset-0" onclick="closeScanner()"></div>
        <div class="bg-white rounded-2xl p-6 relative z-10 w-[90%] max-w-lg shadow-2xl scale-95 transition-transform" id="scannerBox">
            <h3 class="text-sm font-bold text-slate-800 mb-4 flex justify-between items-center border-b pb-3">
                <span class="flex items-center gap-2">📷 Smart Scanner</span>
                <button onclick="closeScanner()" class="bg-slate-100 rounded-full w-8 h-8 font-bold text-slate-500 hover:text-red-500 hover:bg-red-50 transition">X</button>
            </h3>
            <p class="text-[11px] text-slate-500 mb-4 text-center font-medium">Use Camera or choose <strong class="text-blue-600">"Scan an Image File"</strong> below.<br>Format must match: BK-ID-ROOM</p>
            <div id="reader" class="rounded-xl overflow-hidden border-2 border-slate-200 shadow-inner bg-slate-50 pb-2"></div>
        </div>
    </div>

    <div id="boardingPassModal" class="fixed inset-0 z-[70] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300 no-print">
        <div class="bg-slate-900/60 backdrop-blur-sm absolute inset-0" onclick="closeBoardingPass()"></div>
        <div class="relative z-10 scale-95 transition-transform duration-300 transform" id="bpBox">
            <div id="printableBoardingPass" class="ticket-modal bg-white rounded-[16px] overflow-hidden flex w-[850px] shadow-2xl border border-slate-200 relative">
                
                <div class="flex-[2.8] relative bg-white border-r-2 border-dashed border-slate-300 z-10">
                    <div class="bg-[#E11D48] text-white px-8 py-4 flex justify-between items-center">
                        <h1 class="text-xl tracking-widest font-bold flex items-center gap-2">BOOKING TICKET</h1>
                        <span class="text-[11px] font-bold tracking-widest bg-white/20 px-3 py-1 rounded-full">U-RENTAL</span>
                    </div>
                    <div class="p-8 relative">
                        <div class="flex justify-between mb-6 relative z-10">
                            <div><div class="text-[10px] text-slate-400 font-bold tracking-widest">NAME OF GUEST</div><div id="bp_name" class="text-lg font-black text-slate-900 uppercase">---</div></div>
                            <div class="text-right"><div class="text-[10px] text-slate-400 font-bold tracking-widest">PHONE NUMBER</div><div id="bp_phone" class="text-base font-bold text-slate-900">---</div></div>
                        </div>
                        <div class="flex justify-between relative z-10">
                            <div><div class="text-[10px] text-slate-400 font-bold tracking-widest">ROOM NO.</div><div id="bp_room" class="text-[40px] font-black text-[#E11D48] leading-none mt-1">---</div><div id="bp_type" class="text-[11px] font-bold text-slate-500 mt-2 bg-slate-100 inline-block px-2 py-1 rounded">---</div></div>
                            <div class="text-right">
                                <div class="text-[10px] text-slate-400 font-bold tracking-widest">CHECK-IN</div><div id="bp_in" class="text-[15px] font-bold text-slate-900">---</div>
                                <div class="text-[10px] text-slate-400 font-bold tracking-widest mt-3">CHECK-OUT</div><div id="bp_out" class="text-[15px] font-bold text-slate-900">---</div>
                            </div>
                        </div>
                        <div class="mt-6 pt-4 border-t-2 border-slate-100 flex justify-between items-center relative z-10">
                            <svg id="bp_barcode"></svg>
                            <div class="text-[10px] text-slate-400 font-bold">Powered by U-Rental</div>
                        </div>
                    </div>
                </div>
                
                <div class="flex-[1.2] relative bg-slate-50 z-10">
                    <div class="bg-[#BE123C] text-white px-6 py-4 text-center"><h1 class="text-base font-bold tracking-widest">TICKET</h1></div>
                    <div class="p-6 text-center">
                        <div class="text-[10px] text-slate-400 font-bold tracking-widest">BOARDING ROOM</div>
                        <div id="bp_room_small" class="text-[32px] font-black text-slate-900 mt-1">---</div>
                        <div class="bg-white inline-block p-2 border-2 border-slate-200 rounded-xl mt-6 shadow-sm"><img id="bp_qr" src="" alt="QR" width="120"></div>
                        <div class="text-[9px] text-slate-400 font-bold mt-2 tracking-widest text-emerald-600">SHOW AT COUNTER</div>
                    </div>
                </div>
                <div class="absolute top-[60px] left-[70%] w-[34px] h-[34px] bg-[#F8FAFC] border border-slate-200 rounded-full transform -translate-x-1/2 z-20 shadow-inner"></div>
                <div class="absolute bottom-[60px] left-[70%] w-[34px] h-[34px] bg-[#F8FAFC] border border-slate-200 rounded-full transform -translate-x-1/2 z-20 shadow-inner"></div>
            </div>
            
            <div class="flex justify-center gap-3 mt-6 relative z-10 no-print">
                <button onclick="window.print()" class="bg-white text-slate-800 text-sm font-bold px-8 py-3 rounded-xl shadow-lg flex items-center gap-2 hover:bg-slate-50 transition transform hover:-translate-y-1">🖨️ Print Pass</button>
                <button onclick="closeBoardingPass()" class="bg-slate-800 text-white text-sm font-bold px-8 py-3 rounded-xl shadow-lg flex items-center gap-2 hover:bg-slate-900 transition transform hover:-translate-y-1">Close</button>
            </div>
        </div>
    </div>

   <script>

// ==========================================

// ទី១៖ គ្រប់គ្រងការគូរក្រាហ្វ (Chart Management)

// ==========================================

let bookingChartInstance = null;



function initChart() {

const chartCanvas = document.getElementById('realBookingChart');

if(!chartCanvas) return;


// ការពារមិនឱ្យគូរក្រាហ្វពេល Tab នេះត្រូវបានលាក់ (ទប់ស្កាត់ការ Error ទំហំ 0x0)

if(chartCanvas.offsetParent === null) return;



const ctx = chartCanvas.getContext('2d');

let gradient = ctx.createLinearGradient(0, 0, 0, 300);

gradient.addColorStop(0, 'rgba(59, 130, 246, 0.4)');

gradient.addColorStop(1, 'rgba(59, 130, 246, 0.0)');


// បោសសម្អាតក្រាហ្វចាស់សិន មុននឹងគូរថ្មី

if (bookingChartInstance) {

bookingChartInstance.destroy();

}



bookingChartInstance = new Chart(ctx, {

type: 'line',

data: { labels: <?php echo json_encode($chart_labels); ?>, datasets: [{ label: 'Bookings', data: <?php echo json_encode($chart_data); ?>, borderColor: '#3B82F6', backgroundColor: gradient, borderWidth: 2, pointBackgroundColor: '#fff', pointBorderColor: '#3B82F6', fill: true, tension: 0.4 }] },

options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, suggestedMax: 5, border: {display: false}, grid: { color: '#f1f5f9' } }, x: { border: {display: false}, grid: { display: false } } } }

});

}



// ==========================================

// ទី២៖ Tab Management

// ==========================================

document.addEventListener('DOMContentLoaded', () => {

let activeTab = localStorage.getItem('urental_admin_tab') || 'overview';

switchTab(activeTab);

// យើងលែងហៅ initChart() ត្រង់នេះទៀតហើយ!

});



function switchTab(tabId) {

localStorage.setItem('urental_admin_tab', tabId);

document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));

document.getElementById(tabId).classList.add('active');


const btns = ['overview', 'booking', 'floorplan', 'guestlist'];

const titles = {'overview': 'Platform Overview', 'booking': 'Create Booking', 'floorplan': 'Live Floor Plan', 'guestlist': 'Guest Database'};

btns.forEach(id => {

let btn = document.getElementById('nav-' + id);

if(id === tabId) {

btn.classList.add('bg-white/10', 'text-white');

document.getElementById('headerTitle').innerText = titles[id];

} else {

btn.classList.remove('bg-white/10', 'text-white');

}

});



// ហៅក្រាហ្វមកគូរ តែពេលដែលយើងចុចលើផ្ទាំង 'overview' ប៉ុណ្ណោះ

if (tabId === 'overview') {

setTimeout(() => { initChart(); }, 150);

}

}



// ==========================================

// ទី៣៖ មុខងារទូទៅផ្សេងៗ (រក្សាទុកនៅដដែល)

// ==========================================

function triggerConfirm(actionType, linkUrl) {

let title = document.getElementById('confirmTitle');

let text = document.getElementById('confirmText');

let icon = document.getElementById('confirmIcon');

let btn = document.getElementById('confirmBtn');



if(actionType === 'delete') {

icon.innerText = "🗑️"; icon.className = "w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl bg-red-100 text-red-500";

title.innerText = "Delete Record"; text.innerText = "This action cannot be undone. Proceed?";

btn.className = "px-5 py-2.5 rounded-xl text-sm font-bold text-white w-full text-center shadow-lg transition bg-red-600 hover:bg-red-700";

} else if(actionType === 'logout') {

icon.innerText = "🚪"; icon.className = "w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl bg-slate-100 text-slate-600";

title.innerText = "Secure Logout"; text.innerText = "Are you sure you want to end your session?";

btn.className = "px-5 py-2.5 rounded-xl text-sm font-bold text-white w-full text-center shadow-lg transition bg-slate-800 hover:bg-slate-900";

} else if(actionType === 'checkin') {

icon.innerText = "📥"; icon.className = "w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl bg-emerald-100 text-emerald-600";

title.innerText = "Confirm Check-In"; text.innerText = "Set this guest's status to Active Stay?";

btn.className = "px-5 py-2.5 rounded-xl text-sm font-bold text-white w-full text-center shadow-lg transition bg-emerald-600 hover:bg-emerald-700";

} else if(actionType === 'checkout') {

icon.innerText = "📤"; icon.className = "w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl bg-amber-100 text-amber-600";

title.innerText = "Confirm Check-Out"; text.innerText = "Guest is leaving. Room will become available.";

btn.className = "px-5 py-2.5 rounded-xl text-sm font-bold text-white w-full text-center shadow-lg transition bg-amber-600 hover:bg-amber-700";

}



btn.href = linkUrl;

document.getElementById('customConfirmModal').classList.remove('opacity-0', 'pointer-events-none');

setTimeout(() => { document.getElementById('confirmBox').classList.remove('scale-95'); }, 10);

}



function closeConfirm() {

document.getElementById('confirmBox').classList.add('scale-95');

setTimeout(() => { document.getElementById('customConfirmModal').classList.add('opacity-0', 'pointer-events-none'); }, 200);

}



// Modals Management

function openEditTypeModal(id, name) {

document.getElementById('edit_type_id').value = id;

document.getElementById('edit_type_name').value = name;

document.getElementById('editTypeModal').classList.remove('opacity-0', 'pointer-events-none');

}



function openEditRoomModal(id, number, typeId, price) {

document.getElementById('edit_room_id').value = id;

document.getElementById('edit_room_number').value = number;

document.getElementById('edit_type_id_select').value = typeId;

document.getElementById('edit_price').value = price;

document.getElementById('editRoomModal').classList.remove('opacity-0', 'pointer-events-none');

}



function closeEditModals() {

document.getElementById('editTypeModal').classList.add('opacity-0', 'pointer-events-none');

document.getElementById('editRoomModal').classList.add('opacity-0', 'pointer-events-none');

}



function openRoomDetails(room, name, phone, inDate, outDate) {

document.getElementById('rd_room').innerText = room;

document.getElementById('rd_name').innerText = name;

document.getElementById('rd_phone').innerText = phone;

document.getElementById('rd_in').innerText = inDate;

document.getElementById('rd_out').innerText = outDate;

document.getElementById('roomDetailsModal').classList.remove('opacity-0', 'pointer-events-none');

setTimeout(() => { document.getElementById('rdBox').classList.remove('scale-95'); }, 10);

}

function closeRoomDetails() {

document.getElementById('rdBox').classList.add('scale-95');

setTimeout(() => { document.getElementById('roomDetailsModal').classList.add('opacity-0', 'pointer-events-none'); }, 200);

}



function showSupportInfo() {

document.getElementById('supportModal').classList.remove('opacity-0', 'pointer-events-none');

setTimeout(() => { document.getElementById('supportBox').classList.remove('scale-95'); }, 10);

}

function closeSupportInfo() {

document.getElementById('supportBox').classList.add('scale-95');

setTimeout(() => { document.getElementById('supportModal').classList.add('opacity-0', 'pointer-events-none'); }, 200);

}



function gotoBooking(roomId) {

switchTab('booking');

document.getElementById('roomSelect').value = roomId;

fetchBookedSlots();

}



document.getElementById('paymentStatusSelect').addEventListener('change', function() {

let btn = document.getElementById('submitBookBtn');

if (this.value === 'unpaid') {

btn.innerHTML = 'បន្តទៅកាន់ម៉ាស៊ីន POS (Pay Now) <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>';

btn.className = 'w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-xl shadow-[0_10px_20px_-10px_rgba(37,99,235,0.5)] transition-all transform hover:-translate-y-1 text-base flex justify-center items-center gap-2';

} else {

btn.innerHTML = 'រក្សាទុកការកក់ (Save Booking) ✅';

btn.className = 'w-full bg-[#0B1120] hover:bg-slate-800 text-white font-bold py-4 rounded-xl shadow-lg transition-all transform hover:-translate-y-1 text-base flex justify-center items-center gap-2';

}

});



let activeBookings = [];

function fetchBookedSlots() {

let roomId = document.getElementById('roomSelect').value;

let box = document.getElementById('liveStatusBox');

let container = document.getElementById('slotsContainer');

if (!roomId) return;



fetch('check_availability.php?room_id=' + roomId).then(res => res.json()).then(data => {

activeBookings = data;

container.innerHTML = "";

if (data.length > 0) {

box.classList.remove('hidden');

data.forEach(s => { container.innerHTML += `<span class="slot-tag">${s.formatted_in} ➔ ${s.formatted_out}</span>`; });

} else {

box.classList.add('hidden');

}

validateLiveDates();

});

}



function validateLiveDates() {

let checkInInput = document.getElementById('checkInInput');

let checkOutInput = document.getElementById('checkOutInput');

let checkIn = checkInInput.value;

let checkOut = checkOutInput.value;

let err = document.getElementById('liveErrorBanner');

let btn = document.getElementById('submitBookBtn');



if (checkIn) {

checkOutInput.min = checkIn;

let inDate = new Date(checkIn);

let outDate = new Date(checkOut);


if (!checkOut || outDate <= inDate) {

let nextDay = new Date(inDate.getTime() + (24 * 60 * 60 * 1000));

let tzoffset = (new Date()).getTimezoneOffset() * 60000;

checkOutInput.value = (new Date(nextDay - tzoffset)).toISOString().slice(0, 16);

checkOut = checkOutInput.value;

}

}



if (!checkIn || !checkOut || activeBookings.length === 0) {

err.style.display = "none"; btn.disabled = false; btn.classList.remove('opacity-50', 'cursor-not-allowed', 'scale-100');

} else {

let isOverlapping = activeBookings.some(b => (checkIn < b.check_out && checkOut > b.check_in));

if (isOverlapping) {

err.style.display = "flex"; btn.disabled = true; btn.classList.add('opacity-50', 'cursor-not-allowed'); btn.classList.remove('hover:-translate-y-1');

} else {

err.style.display = "none"; btn.disabled = false; btn.classList.remove('opacity-50', 'cursor-not-allowed'); btn.classList.add('hover:-translate-y-1');

}

}



let roomSelect = document.getElementById('roomSelect');

let note = document.getElementById('paymentNote');

if (roomSelect.value && checkIn && checkOut && !btn.disabled) {

let selectedOption = roomSelect.options[roomSelect.selectedIndex];

let pricePerDay = parseFloat(selectedOption.getAttribute('data-price'));

let inDate = new Date(checkIn);

let outDate = new Date(checkOut);

let hours = Math.abs(outDate - inDate) / 36e5;


if (hours > 0 && note) {

let totalPrice = (hours / 24) * pricePerDay;

note.innerHTML = `

<div class="flex items-center gap-3">

<div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg shadow-sm">⏱️</div>

<div class="flex flex-col">

<span class="text-xs text-slate-400 font-bold uppercase tracking-widest">Duration</span>

<span class="text-slate-800 font-black">${hours.toFixed(0)} ម៉ោង</span>

</div>

</div>

<div class="flex items-center gap-4 mt-3 md:mt-0 md:border-l md:border-slate-200 md:pl-6">

<div class="flex flex-col text-right">

<span class="text-xs text-slate-400 font-bold uppercase tracking-widest">Total Amount</span>

<span class="text-2xl font-black text-blue-600">$${totalPrice.toFixed(2)}</span>

</div>

</div>

`;

}

} else if (note) {

note.innerHTML = `<div class="flex items-center gap-3"><span class="text-2xl">💡</span><span>សូមជ្រើសរើសបន្ទប់ និងម៉ោងចេញចូលដើម្បីប្រព័ន្ធគណនាតម្លៃ។</span></div>`;

}

}



setInterval(() => {

const activeTag = document.activeElement ? document.activeElement.tagName : '';

if (activeTag === 'INPUT' || activeTag === 'SELECT' || activeTag === 'TEXTAREA') {

return;

}

let cleanUrl = window.location.pathname + '?_t=' + new Date().getTime();



fetch(cleanUrl)

.then(res => res.text())

.then(html => {

let doc = new DOMParser().parseFromString(html, 'text/html');


let searchInput = document.getElementById('searchInput');

if(searchInput && searchInput.value === "") {

let oldGuest = document.querySelector('#guestTable tbody');

let newGuest = doc.querySelector('#guestTable tbody');

if(oldGuest && newGuest) oldGuest.innerHTML = newGuest.innerHTML;

}


let oldFloorPlan = document.getElementById('floorplanGrid');

let newFloorPlan = doc.querySelector('#floorplanGrid');

if(oldFloorPlan && newFloorPlan) {

oldFloorPlan.innerHTML = newFloorPlan.innerHTML;

}

})

.catch(err => console.log("Silent Refresh Error:", err));

}, 3000);



function searchTable() {

let input = document.getElementById("searchInput").value.toLowerCase();

document.querySelectorAll("#guestTable tbody tr").forEach(r => { r.style.display = r.innerText.toLowerCase().includes(input) ? "" : "none"; });

}



let html5QrcodeScanner;

function openScanner() {

document.getElementById('scannerModal').classList.remove('opacity-0', 'pointer-events-none');

setTimeout(() => { document.getElementById('scannerBox').classList.remove('scale-95'); }, 10);

html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: {width: 250, height: 150} }, false);

html5QrcodeScanner.render(t => {

closeScanner(); switchTab('guestlist');

document.getElementById('searchInput').value = t.trim(); searchTable();

}, () => {});

}

function closeScanner() {

if(html5QrcodeScanner) html5QrcodeScanner.clear();

document.getElementById('scannerBox').classList.add('scale-95');

setTimeout(() => { document.getElementById('scannerModal').classList.add('opacity-0', 'pointer-events-none'); }, 200);

}



function openBoardingPass(name, phone, room, type, checkin, checkout, id) {

document.getElementById('bp_name').innerText = name; document.getElementById('bp_phone').innerText = phone;

document.getElementById('bp_room').innerText = room; document.getElementById('bp_room_small').innerText = room;

document.getElementById('bp_type').innerText = type; document.getElementById('bp_in').innerText = checkin;

document.getElementById('bp_out').innerText = checkout;


let formatCode = "BK-" + id + "-" + room;

JsBarcode("#bp_barcode", formatCode, { format: "CODE128", width: 1.5, height: 35, displayValue: true, fontSize: 11, margin: 0, lineColor: "#0F172A" });

document.getElementById('bp_qr').src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(formatCode);



document.getElementById('boardingPassModal').classList.remove('opacity-0', 'pointer-events-none');

setTimeout(() => { document.getElementById('bpBox').classList.remove('scale-95'); }, 10);

}

function closeBoardingPass() {

document.getElementById('bpBox').classList.add('scale-95');

setTimeout(() => { document.getElementById('boardingPassModal').classList.add('opacity-0', 'pointer-events-none'); }, 200);

}



function openEditModal(id, name, phone, roomId) {

document.getElementById('edit_id').value = id; document.getElementById('edit_name').value = name;

document.getElementById('edit_phone').value = phone; document.getElementById('edit_room_id').value = roomId;

document.getElementById('editModal').classList.remove('opacity-0', 'pointer-events-none');

setTimeout(() => { document.getElementById('editBox').classList.remove('scale-95'); }, 10);

}

function closeEditModal() {

document.getElementById('editBox').classList.add('scale-95');

setTimeout(() => { document.getElementById('editModal').classList.add('opacity-0', 'pointer-events-none'); }, 200);

}

</script>
</body>
</html>
<?php ob_end_flush(); ?>