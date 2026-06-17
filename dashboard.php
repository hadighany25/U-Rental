<?php
ob_start(); 
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'tenant') {
    header("Location: auth.php");
    exit();
}

// 🛑 ដោះសោរ Session ការពារកុំឱ្យវា Logout ឯងៗពេល Auto-Refresh ដើរ
session_write_close();

include 'config.php';

$user_id = $_SESSION['user_id'];

// ទាញយកព័ត៌មាន User
$stmt_u = $conn->prepare("SELECT phone, fullname FROM users WHERE id = ?");
$stmt_u->bind_param("i", $user_id);
$stmt_u->execute();
$user_info = $stmt_u->get_result()->fetch_assoc();
$user_phone = $user_info['phone'];
$user_name = $user_info['fullname'];

// Auto-Sync ការកក់ដោយ Admin ចូលមក User Dashboard
$conn->query("UPDATE bookings SET user_id = $user_id WHERE tenant_phone = '$user_phone' AND user_id IS NULL");

$toast_msg = "";
$toast_type = "success";
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'booked') $toast_msg = "✅ សំណើកក់បន្ទប់ទទួលបានជោគជ័យ! សូមរង់ចាំការទូទាត់។";
    if ($_GET['msg'] == 'paid') $toast_msg = "✅ ការទូទាត់ប្រាក់ទទួលបានជោគជ័យ!";
    if ($_GET['msg'] == 'error') { $toast_msg = "❌ បរាជ័យ! កាលបរិច្ឆេទនេះមានគេកក់រួចហើយ។"; $toast_type = "error"; }
}

// ===============================================
// ២. មុខងារកក់បន្ទប់ដោយខ្លួនឯង (Self Booking)
// ===============================================
if (isset($_POST['user_book'])) {
    $room_id = intval($_POST['room_id']);
    $check_in = $_POST['check_in_time'];
    $check_out = $_POST['check_out_time'];
    $payment_method = $_POST['payment_method']; // យកពី Form របស់ User
    $status = ($payment_method === 'online') ? 'pending' : 'booked';
    $payment_status = 'unpaid'; 

    $stmt_check = $conn->prepare("SELECT id FROM bookings WHERE room_id = ? AND status != 'checked_out' AND (? < check_out_time AND ? > check_in_time)");
    $stmt_check->bind_param("iss", $room_id, $check_in, $check_out);
    $stmt_check->execute();
    
    if ($stmt_check->get_result()->num_rows > 0) {
        header("Location: dashboard.php?msg=error"); exit();
    } else {
        $stmt_book = $conn->prepare("INSERT INTO bookings (room_id, tenant_name, tenant_phone, user_id, check_in_time, check_out_time, status, payment_method, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_book->bind_param("ississsss", $room_id, $user_name, $user_phone, $user_id, $check_in, $check_out, $status, $payment_method, $payment_status);
        
        if ($stmt_book->execute()) {
            
            $new_booking_id = $conn->insert_id; // ចាប់យក ID ការកក់ថ្មី
            
            // --- បាញ់សារ Notification ទៅ Telegram ---
            $msg_tele = "🌐 ការកក់ថ្មីពីអនឡាញ (User Booking):\n" .
                        "🏢 បន្ទប់លេខ: " . $room_id . "\n" .
                        "👤 ភ្ញៀវ: " . $user_name . "\n" .
                        "📱 លេខ: " . $user_phone . "\n" .
                        "📅 ម៉ោងចូល: " . $check_in . "\n" .
                        "💳 បង់តាម: " . strtoupper($payment_method);
            
            // ហៅ Function ដែលមានក្នុង config.php
            if(function_exists('sendTelegramMessage')) {
                sendTelegramMessage($msg_tele);
            }

            // --- ត្រួតពិនិត្យការបញ្ជូន (Redirect Logic) ---
            if ($payment_method === 'online') {
                // បើគាត់រើស Online ឱ្យលោតទៅទំព័រ payment.php អូតូ
                header("Location: payment.php?id=" . $new_booking_id);
                exit();
            } else {
                // បើគាត់រើស Cash ឱ្យត្រឡប់មក Dashboard វិញ
                header("Location: dashboard.php?msg=booked"); 
                exit();
            }
        }
    }
}

// ទាញយកបន្ទប់ទំនេរសម្រាប់ Dropdown
$available_rooms = $conn->query("SELECT r.*, t.type_name FROM rooms r LEFT JOIN room_types t ON r.type_id = t.id WHERE r.status = 'available' ORDER BY r.room_number ASC");

// សម្រាប់ Floor Plan
$floor_plan_query = $conn->query("
    SELECT r.*, t.type_name, 
           (SELECT b.id FROM bookings b WHERE b.room_id = r.id AND b.status != 'checked_out' ORDER BY b.id DESC LIMIT 1) as is_booked
    FROM rooms r LEFT JOIN room_types t ON r.type_id = t.id ORDER BY r.room_number ASC
");

// ទាញយកប្រវត្តិការកក់
$my_bookings = $conn->prepare("SELECT b.*, r.room_number, t.type_name, r.price FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN room_types t ON r.type_id = t.id WHERE b.user_id = ? OR b.tenant_phone = ? ORDER BY b.id DESC");
$my_bookings->bind_param("is", $user_id, $user_phone);
$my_bookings->execute();
$bookings_result = $my_bookings->get_result();

$active_booking = null;
$bookings_array = [];
while ($row = $bookings_result->fetch_assoc()) {
    $bookings_array[] = $row;
    if (($row['status'] == 'booked' || $row['status'] == 'checked_in') && !$active_booking) {
        $active_booking = $row; 
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tenant Dashboard - U-Rental</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Kantumruy+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        .live-error-banner { display: none; background: #FEF2F2; color: #DC2626; border: 1px solid #FCA5A5; padding: 12px; border-radius: 8px; margin-top: 15px; font-weight: 600; font-size: 13px; }
        .slot-tag { background: #fff; border: 1px solid #FCD34D; padding: 4px 8px; border-radius: 4px; display: inline-block; font-size: 11px; margin: 3px; color: #D97706; font-weight: 600; }

        .clean-table th { background: transparent; color: #64748B; font-size: 11px; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; border-bottom: 1px solid #E2E8F0; padding-bottom: 12px; }
        .clean-table td { border-bottom: 1px dashed #F1F5F9; padding: 18px 12px; vertical-align: middle; }
        .clean-table tr:hover td { background: #F8FAFC; transition: background 0.2s; }

        #reader button { background-color: #0F172A !important; color: white !important; border-radius: 8px !important; padding: 8px 16px !important; border: none !important; font-weight: bold !important; cursor: pointer; margin: 5px; }
        #reader select { padding: 8px; border-radius: 8px; border: 1px solid #ccc; margin: 5px; outline: none; }
        #reader a { color: #3B82F6 !important; font-weight: bold; text-decoration: none; }

        .toast-show { transform: translateX(0) !important; opacity: 1 !important; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

    <div id="toastAlert" class="fixed top-6 right-6 z-[100] px-6 py-4 rounded-xl shadow-2xl flex items-center gap-3 transition-all duration-500 transform translate-x-full opacity-0 <?php echo $toast_type=='error' ? 'bg-red-600' : 'bg-slate-800'; ?> text-white">
        <span class="font-medium text-sm" id="toastMsg"><?php echo $toast_msg; ?></span>
    </div>
    <?php if ($toast_msg): ?>
        <script>
            setTimeout(() => { 
                let t = document.getElementById('toastAlert');
                t.classList.add('toast-show');
                setTimeout(() => { t.classList.remove('toast-show'); }, 3000);
            }, 100);
        </script>
    <?php endif; ?>

    <div id="customConfirmModal" class="fixed inset-0 z-[100] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300 no-print">
        <div class="bg-slate-900/60 backdrop-blur-sm absolute inset-0" onclick="closeConfirm()"></div>
        <div class="bg-white rounded-2xl p-8 relative z-10 w-[90%] max-w-sm shadow-2xl scale-95 transition-transform duration-300" id="confirmBox">
            <div id="confirmIcon" class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl bg-slate-100 text-slate-600">🚪</div>
            <h3 class="text-xl font-bold text-center text-slate-800 mb-2">ចាកចេញពីគណនី</h3>
            <p class="text-center text-slate-500 text-sm font-medium mb-6">តើអ្នកពិតជាចង់ចាកចេញមែនទេ?</p>
            <div class="flex justify-center gap-3">
                <button onclick="closeConfirm()" class="px-5 py-2.5 rounded-xl text-sm font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 w-full transition">បោះបង់</button>
                <a href="auth.php?action=logout" class="px-5 py-2.5 rounded-xl text-sm font-bold text-white w-full text-center shadow-lg transition bg-slate-800 hover:bg-slate-900">យល់ព្រម</a>
            </div>
        </div>
    </div>

    <aside class="w-[260px] bg-[#0B1120] text-slate-400 flex flex-col z-20 no-print">
        <div class="h-20 flex flex-col justify-center px-8 border-b border-slate-800/50">
            <h1 class="text-2xl font-bold text-white"><span class="text-[#10B981]">U-</span>RENTAL</h1>
            <div class="text-[10px] text-blue-400 font-bold tracking-widest uppercase mt-1">Tenant Portal</div>
        </div>
        <div class="flex-1 overflow-y-auto py-6 px-4 space-y-8">
            <div>
                <p class="px-4 text-[11px] font-bold tracking-[0.2em] text-slate-500 uppercase mb-3">Main Menu</p>
                <div class="space-y-1">
                    <button onclick="switchTab('dashboard')" id="nav-dashboard" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition text-sm font-medium hover:text-white hover:bg-white/5"><svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg> ផ្ទាំងស្វាគមន៍</button>
                    <button onclick="switchTab('bookroom')" id="nav-bookroom" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition text-sm font-medium hover:text-white hover:bg-white/5"><svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg> ស្នើសុំកក់បន្ទប់</button>
                    <button onclick="switchTab('floorplan')" id="nav-floorplan" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition text-sm font-medium hover:text-white hover:bg-white/5"><svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5z"></path></svg> ពិនិត្យប្លង់បន្ទប់</button>
                    <button onclick="switchTab('history')" id="nav-history" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition text-sm font-medium hover:text-white hover:bg-white/5"><svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg> ប្រវត្តិការជួល</button>
                </div>
            </div>
            <div>
                <p class="px-4 text-[11px] font-bold tracking-[0.2em] text-slate-500 uppercase mb-3">Support</p>
                <div class="space-y-1">
                    <button onclick="showSupportInfo()" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl transition text-sm font-medium hover:text-white hover:bg-white/5"><svg class="w-5 h-5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"></path></svg> ទំនាក់ទំនង</button>
                </div>
            </div>
        </div>
        <div class="p-4 border-t border-slate-800/50">
            <button onclick="openConfirm()" class="flex items-center justify-center gap-2 w-full px-4 py-3 bg-red-500/10 text-red-400 hover:bg-red-500 hover:text-white rounded-xl transition duration-300 font-bold text-sm shadow-md">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg> ចាកចេញ
            </button>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-y-auto">
        <header class="h-20 bg-white px-8 flex justify-between items-center sticky top-0 z-10 shadow-sm border-b border-slate-100 no-print">
            <h2 class="text-[22px] font-bold text-slate-800" id="headerTitle">ផ្ទាំងស្វាគមន៍</h2>
            <div class="flex items-center gap-4">
                <span class="flex items-center gap-2 bg-emerald-50 text-emerald-600 px-3 py-1.5 rounded-full text-xs border border-emerald-100 font-bold"><span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span></span> Tenant Portal</span>
                <div class="flex flex-col text-right">
                    <span class="font-bold text-sm text-slate-800"><?php echo htmlspecialchars($user_name); ?></span>
                    <span class="text-xs text-slate-500"><?php echo htmlspecialchars($user_phone); ?></span>
                </div>
                <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold border border-blue-200"><?php echo substr($user_name, 0, 1); ?></div>
            </div>
        </header>

        <div class="p-8">
            
            <div id="dashboard" class="tab-content active">
                <div class="mb-8 text-center md:text-left">
                    <h1 class="text-3xl font-extrabold text-slate-800">សួស្តី, <?php echo htmlspecialchars($user_name); ?> 👋</h1>
                    <p class="text-slate-500 mt-2 font-medium text-sm">នេះជាព័ត៌មាន និងវិក្កយបត្រនៃការស្នាក់នៅរបស់អ្នក។</p>
                </div>

                <?php if ($active_booking): 
                    $is_checked_in = $active_booking['status'] == 'checked_in';
                    $bg_color = $is_checked_in ? 'bg-[#3B82F6]' : 'bg-[#E11D48]'; 
                    $status_text = $is_checked_in ? 'ACTIVE' : 'PENDING';
                    $dataCode = "BK-" . $active_booking['id'] . "-" . $active_booking['room_number'];
                ?>
                <div id="printableBoardingPass" class="bg-white rounded-[16px] overflow-hidden flex w-full max-w-[850px] shadow-xl border border-slate-200 relative mx-auto mt-6">
                        <div class="flex-[2.8] relative bg-white border-r-2 border-dashed border-slate-300 z-10">
                            <div class="<?php echo $bg_color; ?> text-white px-8 py-4 flex justify-between items-center">
                                <h1 class="text-xl tracking-widest font-bold flex items-center gap-2">BOOKING TICKET</h1>
                                <span class="text-[11px] font-bold tracking-widest bg-white/20 px-3 py-1 rounded-full"><?php echo $status_text; ?></span>
                            </div>
                            <div class="p-8 relative">
                                <svg class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 rotate-45 opacity-5 w-64 h-64 pointer-events-none z-0" fill="currentColor" viewBox="0 0 20 20"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"></path></svg>
                                
                                <div class="flex justify-between mb-6 relative z-10">
                                    <div><div class="text-[10px] text-slate-400 font-bold tracking-widest">NAME OF GUEST</div><div class="text-lg font-black text-slate-900 uppercase"><?php echo htmlspecialchars($user_name); ?></div></div>
                                    <div class="text-right"><div class="text-[10px] text-slate-400 font-bold tracking-widest">PHONE NUMBER</div><div class="text-base font-bold text-slate-900"><?php echo htmlspecialchars($user_phone); ?></div></div>
                                </div>
                                <div class="flex justify-between relative z-10">
                                    <div><div class="text-[10px] text-slate-400 font-bold tracking-widest">ROOM NO.</div><div class="text-[40px] font-black <?php echo $is_checked_in ? 'text-[#3B82F6]':'text-[#E11D48]'; ?> leading-none mt-1"><?php echo $active_booking['room_number']; ?></div><div class="text-[11px] font-bold text-slate-500 mt-2 bg-slate-100 inline-block px-2 py-1 rounded"><?php echo $active_booking['type_name']; ?></div></div>
                                    <div class="text-right">
                                        <div class="text-[10px] text-slate-400 font-bold tracking-widest">CHECK-IN</div><div class="text-[15px] font-bold text-slate-900"><?php echo date('M d, H:i', strtotime($active_booking['check_in_time'])); ?></div>
                                        <div class="text-[10px] text-slate-400 font-bold tracking-widest mt-3">CHECK-OUT</div><div class="text-[15px] font-bold text-slate-900"><?php echo date('M d, H:i', strtotime($active_booking['check_out_time'])); ?></div>
                                    </div>
                                </div>
                                <div class="mt-6 pt-4 border-t-2 border-slate-100 flex justify-between items-center relative z-10">
                                    <svg id="dash_barcode"></svg>
                                    <div class="text-[10px] text-slate-400 font-bold">Powered by U-Rental</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex-[1.2] relative bg-slate-50 z-10">
                            <div class="bg-[#BE123C] text-white px-6 py-4 text-center"><h1 class="text-base font-bold tracking-widest">TICKET</h1></div>
                            <div class="p-6 text-center">
                                <div class="text-[10px] text-slate-400 font-bold tracking-widest">BOARDING ROOM</div>
                                <div class="text-[32px] font-black text-slate-900 mt-1"><?php echo $active_booking['room_number']; ?></div>
                                <div class="bg-white inline-block p-2 border-2 border-slate-200 rounded-xl mt-6 shadow-sm"><img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($dataCode); ?>" alt="QR" width="120"></div>
                                <div class="text-[9px] text-slate-400 font-bold mt-2 tracking-widest text-emerald-600">SHOW AT COUNTER</div>
                            </div>
                        </div>
                        <div class="absolute top-[60px] left-[70%] w-[34px] h-[34px] bg-[#F8FAFC] rounded-full transform -translate-x-1/2 z-20 shadow-inner"></div>
                        <div class="absolute bottom-[60px] left-[70%] w-[34px] h-[34px] bg-[#F8FAFC] rounded-full transform -translate-x-1/2 z-20 shadow-inner"></div>
                    </div>
                    <script>
                        document.addEventListener("DOMContentLoaded", function() {
                            JsBarcode("#dash_barcode", "<?php echo $dataCode; ?>", { format: "CODE128", width: 1.5, height: 35, displayValue: true, fontSize: 11, margin: 0, lineColor: "#0F172A" });
                        });
                    </script>
                <?php else: ?>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-12 text-center max-w-3xl mx-auto mt-6">
                        <div class="w-20 h-20 bg-slate-50 border-2 border-dashed border-slate-300 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-400">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        </div>
                        <h2 class="text-xl font-bold text-slate-800 mb-2">អ្នកមិនទាន់មានការកក់សកម្មទេ</h2>
                        <p class="text-slate-500 mb-8 text-sm">សូមពិនិត្យប្លង់បន្ទប់ ឬធ្វើការកក់បន្ទប់ដែលអ្នកពេញចិត្តឥឡូវនេះ ដើម្បីទទួលបានការស្នាក់នៅដ៏ងាយស្រួល។</p>
                        <button onclick="switchTab('floorplan')" class="bg-[#0B1120] text-white font-bold px-8 py-3 rounded-xl shadow-lg hover:bg-slate-800 transition">🔍 មើលប្លង់បន្ទប់</button>
                    </div>
                <?php endif; ?>
            </div>

            <div id="bookroom" class="tab-content max-w-4xl mx-auto">
                <div class="bg-white rounded-[16px] shadow-sm border border-slate-100 p-8">
                    <h2 class="text-xl font-bold text-slate-800 mb-6 border-l-4 border-blue-500 pl-3">ទម្រង់ស្នើសុំកក់បន្ទប់</h2>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label class="block text-[13px] font-bold text-slate-500 uppercase mb-2">ជ្រើសរើសបន្ទប់ទំនេរ</label>
                            <select name="room_id" id="roomSelect" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 transition" required onchange="fetchBookedSlots(); validateLiveDates();">
                                <option value="" disabled selected>-- ជ្រើសរើសបន្ទប់ --</option>
                                <?php $available_rooms->data_seek(0); while($r = $available_rooms->fetch_assoc()): ?>
                                    <option value='<?php echo $r['id']; ?>' data-price='<?php echo $r['price']; ?>'>បន្ទប់ <?php echo $r['room_number']; ?> - <?php echo $r['type_name']; ?> ($<?php echo $r['price']; ?>)</option>
                                <?php endwhile; ?>
                            </select>
                            <div id="liveStatusBox" class="mt-3 hidden bg-amber-50 border border-amber-200 p-3 rounded-lg shadow-sm">
                                <h4 class="text-xs font-bold text-amber-700 mb-2">⚠️ ម៉ោងដែលមានគេកក់រួចហើយ (មិនអាចរើសជាន់គ្នាបានទេ):</h4>
                                <div id="slotsContainer"></div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[13px] font-bold text-slate-500 uppercase mb-2">ថ្ងៃចូល (Check-In)</label>
                            <input type="datetime-local" name="check_in_time" id="checkInInput" onchange="validateLiveDates()" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500" required>
                        </div>
                        <div>
                            <label class="block text-[13px] font-bold text-slate-500 uppercase mb-2">ថ្ងៃចេញ (Check-Out)</label>
                            <input type="datetime-local" name="check_out_time" id="checkOutInput" onchange="validateLiveDates()" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500" required>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-[13px] font-bold text-slate-500 uppercase mb-2">របៀបបង់ប្រាក់ (Payment Method)</label>
                            <select name="payment_method" id="paymentMethod" class="w-full bg-white border border-blue-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500" required onchange="validateLiveDates()">
                                <option value="cash">💵 ទូទាត់សាច់ប្រាក់នៅបញ្ជរ (Cash at counter)</option>
                                <option value="online">💳 ផ្ទេរប្រាក់តាមអនឡាញ (Online / U-Pay)</option>
                            </select>
                            <p id="paymentNote" class="text-[11px] text-slate-500 mt-3 font-medium bg-slate-50 p-4 border border-slate-200 rounded-xl">
                                សូមជ្រើសរើសបន្ទប់ និងម៉ោងចេញចូលដើម្បីគណនាតម្លៃ។
                            </p>
                        </div>

                        <div id="liveErrorBanner" class="live-error-banner md:col-span-2 text-center shadow-sm">
                            ❌ ម៉ោងនេះមានគេកក់រួចហើយ! សូមប្តូរម៉ោងថ្មីដើម្បីបន្តការកក់។
                        </div>

                        <div class="md:col-span-2 mt-2">
                            <button type="submit" name="user_book" id="submitBookBtn" class="w-full bg-[#0B1120] text-white font-bold py-4 rounded-xl hover:bg-blue-600 shadow-lg transition text-base">
                                បញ្ជូនសំណើកក់
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="floorplan" class="tab-content">
                <div class="bg-white rounded-[16px] shadow-sm border border-slate-100 p-8">
                    <h2 class="text-xl font-bold text-slate-800 mb-6 border-l-4 border-slate-500 pl-3">Live Floor Plan</h2>
                    <p class="text-sm text-slate-500 mb-6 bg-slate-50 p-4 rounded-lg border border-slate-200">
                        🟩 ចុចលើបន្ទប់ <strong class="text-emerald-600">ពណ៌បៃតង</strong> វានឹងនាំអ្នកទៅទំព័រកក់ និងជ្រើសរើសបន្ទប់នោះដោយស្វ័យប្រវត្តិ។<br>
                        🟥 បន្ទប់ <strong class="text-red-500">ពណ៌ក្រហម</strong> គឺមានភ្ញៀវកក់រួចហើយ (មិនអាចមើលព័ត៌មាន ឬកក់បានទេ)។
                    </p>
                    <div id="floorplanGrid" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-6">
                        <?php $floor_plan_query->data_seek(0); while($r = $floor_plan_query->fetch_assoc()): 
                            if (empty($r['is_booked'])) {
                                $bg = 'bg-emerald-50 border-emerald-200 text-emerald-700 hover:bg-emerald-100 shadow-sm hover:shadow-md cursor-pointer transform hover:scale-105';
                                $onclick = "gotoBooking('{$r['id']}')";
                                $status_dot = ''; 
                            } else {
                                $bg = 'bg-red-50 border-red-200 text-red-700 shadow-sm opacity-80 cursor-not-allowed';
                                $onclick = "showToast('បន្ទប់នេះត្រូវបានកក់រួចហើយ! មិនអាចជ្រើសរើសបានទេ', 'error')";
                                $status_dot = '<span class="absolute top-3 right-3 flex h-3 w-3"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span></span>';
                            }
                        ?>
                        <div onclick="<?php echo $onclick; ?>" class="border-2 rounded-2xl p-6 <?php echo $bg; ?> flex flex-col items-center justify-center relative transition">
                            <?php echo $status_dot; ?>
                            <span class="text-3xl font-black tracking-tight"><?php echo $r['room_number']; ?></span>
                            <span class="text-[10px] font-bold uppercase mt-2 px-2 py-1 bg-white/50 rounded-lg"><?php echo $r['type_name'] ?? 'N/A'; ?></span>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
            <!-- History -->
            <div id="history" class="tab-content">
                <div class="bg-white rounded-[16px] shadow-sm border border-slate-100 overflow-hidden">
                    <div class="p-6 border-b border-slate-100 flex flex-col md:flex-row justify-between items-center bg-white gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-1 h-6 bg-blue-600 rounded"></div>
                            <h2 class="text-lg font-bold text-slate-800">ប្រវត្តិការជួលរបស់អ្នក</h2>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="openScanner()" class="bg-[#0B1120] text-white px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2 hover:bg-slate-800 transition shadow-sm">
                                📷 Scan
                            </button>
                            <input type="text" id="searchInput" onkeyup="searchTable()" placeholder="ស្វែងរក..." class="bg-slate-50 border border-slate-200 rounded-lg px-4 py-2 w-64 outline-none text-sm focus:border-blue-500 transition">
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full clean-table text-left" id="historyTable">
                            <thead>
                                <tr>
                                    <th class="px-6 pt-5">Ref & Room</th>
                                    <th class="px-6 pt-5">Schedule</th>
                                    <th class="px-6 pt-5">Payment</th>
                                    <th class="px-6 pt-5">Status</th>
                                    <th class="px-6 pt-5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm divide-y divide-slate-100">
                                <?php foreach ($bookings_array as $row): 
                                    $search_code = "BK-".$row['id']."-".$row['room_number'];
                                ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-6">
                                        <div class="font-black text-slate-800 text-base">Room <?php echo $row['room_number']; ?></div>
                                        <div class="text-[11px] text-blue-500 mt-1 font-mono font-bold searchable-code"><?php echo $search_code; ?></div>
                                    </td>
                                    <td class="px-6 text-xs font-semibold text-slate-600">
                                        <div class="text-emerald-600 mb-1">IN: <?php echo date('m/d/y h:i A', strtotime($row['check_in_time'])); ?></div>
                                        <div class="text-amber-600">OUT: <?php echo date('m/d/y h:i A', strtotime($row['check_out_time'])); ?></div>
                                    </td>
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
                                                <?php if ($row['payment_method'] == 'online'): ?>
                                                    <!-- ប៊ូតុង Pay Now ថ្មី ពេលបង់មិនជោគជ័យ -->
                                                    <a href="payment.php?id=<?php echo $row['id']; ?>" class="text-[10px] text-blue-600 font-bold hover:text-blue-800 hover:underline inline-block mt-1 bg-blue-50 border border-blue-200 px-2 py-0.5 rounded transition">🔄 Pay Now</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6">
                                        <div>
                                            <?php 
                                                if ($row['status'] == 'checked_in') {
                                                    echo '<span class="bg-emerald-50 text-emerald-600 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase">Active</span>';
                                                } elseif ($row['status'] == 'booked') {
                                                    echo '<span class="bg-amber-50 text-amber-600 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase">Booked</span>';
                                                } elseif ($row['status'] == 'pending' || ($row['payment_method'] == 'online' && $row['payment_status'] == 'unpaid' && $row['status'] != 'checked_out')) {
                                                    // កន្លែងកែថ្មី៖ បង្ខំឱ្យលោត Failed / Pending ឱ្យតែ Online ហើយអត់ទាន់បង់លុយ
                                                    echo '<span class="bg-red-50 text-red-600 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase">Failed / Pending</span>';
                                                } else {
                                                    echo '<span class="bg-slate-50 text-slate-500 px-2.5 py-1 rounded-md text-[11px] font-bold uppercase">Finished</span>';
                                                } 
                                            ?>
                                        </div>
                                    </td>
                                    <td class="px-6 text-right">
                                        <button onclick="openBoardingPass('<?php echo htmlspecialchars($user_name); ?>','<?php echo htmlspecialchars($user_phone); ?>','<?php echo $row['room_number']; ?>','<?php echo $row['type_name']; ?>','<?php echo date('M d, H:i', strtotime($row['check_in_time'])); ?>','<?php echo date('M d, H:i', strtotime($row['check_out_time'])); ?>','<?php echo $row['id']; ?>')" class="p-1.5 text-amber-500 bg-amber-50 hover:bg-amber-100 rounded transition shadow-sm" title="View Ticket">
                                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M4 6a2 2 0 00-2 2v2a1 1 0 011 1v2a1 1 0 01-1 1v2a2 2 0 002 2h16a2 2 0 002-2v-2a1 1 0 01-1-1v-2a1 1 0 011-1V8a2 2 0 00-2-2H4zm14 2h2v2h-2V8zm0 4h2v2h-2v-2zm0 4h2v2h-2v-2zm-2-8h-2v10h2V8zm-4 0H8v10h4V8z"></path></svg>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; if(empty($bookings_array)): ?>
                                    <tr><td colspan="5" class="p-8 text-center text-slate-500 font-medium">អ្នកមិនទាន់មានប្រវត្តិការជួលនៅឡើយទេ។</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <div id="supportModal" class="fixed inset-0 z-[80] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
        <div class="bg-slate-900/60 backdrop-blur-sm absolute inset-0" onclick="closeSupportInfo()"></div>
        <div class="bg-white rounded-2xl p-8 relative z-10 w-[90%] max-w-sm shadow-2xl scale-95 transition-transform" id="supportBox">
            <div class="w-16 h-16 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl font-black">📞</div>
            <h3 class="text-xl font-bold text-center text-slate-800 mb-6">ទំនាក់ទំនងយើងខ្ញុំ</h3>
            <div class="space-y-3 text-sm text-slate-600 font-medium bg-slate-50 p-4 rounded-xl border border-slate-100">
                <div class="flex flex-col border-b pb-2"><span class="text-xs text-slate-400">Email:</span><span class="text-slate-800 font-bold">support@urental.com</span></div>
                <div class="flex flex-col"><span class="text-xs text-slate-400">Hotline:</span><span class="text-blue-600 font-bold text-lg">+855 12 345 678</span></div>
            </div>
            <button onclick="closeSupportInfo()" class="mt-6 w-full bg-slate-800 hover:bg-slate-900 text-white font-bold py-3 rounded-xl transition">បិទ</button>
        </div>
    </div>

    <div id="scannerModal" class="fixed inset-0 z-[60] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
        <div class="bg-slate-900/80 backdrop-blur-sm absolute inset-0" onclick="closeScanner()"></div>
        <div class="bg-white rounded-2xl p-6 relative z-10 w-[90%] max-w-lg shadow-2xl scale-95 transition-transform" id="scannerBox">
            <h3 class="text-sm font-bold text-slate-800 mb-4 flex justify-between items-center border-b pb-3">
                <span class="flex items-center gap-2">📷 Smart Scanner</span>
                <button onclick="closeScanner()" class="bg-slate-100 rounded-full w-8 h-8 font-bold text-slate-500 hover:text-red-500 transition">X</button>
            </h3>
            <p class="text-[11px] text-slate-500 mb-4 text-center font-medium">Use Camera or choose <strong class="text-blue-600">"Scan an Image File"</strong>.<br>Format: BK-ID-ROOM</p>
            <div id="reader" class="rounded-xl overflow-hidden border-2 border-slate-200 shadow-inner bg-slate-50 pb-2"></div>
        </div>
    </div>

    <div id="boardingPassModal" class="fixed inset-0 z-[70] flex flex-col items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300 no-print">
        <div class="bg-slate-900/60 backdrop-blur-sm absolute inset-0" onclick="closeBoardingPass()"></div>
        <div class="relative z-10 scale-95 transition-transform duration-300 transform" id="bpBox">
            <div id="printableBoardingPass" class="bg-white rounded-[16px] overflow-hidden flex w-full max-w-[850px] shadow-2xl border border-slate-200 relative mx-auto">
                <div class="flex-[2.8] relative bg-white border-r-2 border-dashed border-slate-300 z-10">
                    <div class="bg-[#E11D48] text-white px-8 py-4 flex justify-between items-center">
                        <h1 class="text-xl tracking-widest font-bold flex items-center gap-2">BOOKING TICKET</h1>
                        <span class="text-[11px] font-bold tracking-widest bg-white/20 px-3 py-1 rounded-full">U-RENTAL</span>
                    </div>
                    <div class="p-8 relative">
                        <svg class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 rotate-45 opacity-5 w-64 h-64 pointer-events-none z-0" fill="currentColor" viewBox="0 0 20 20"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"></path></svg>
                        
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
                <button onclick="window.print()" class="bg-white text-slate-800 text-sm font-bold px-8 py-3 rounded-xl shadow-lg hover:bg-slate-50 transition transform hover:-translate-y-1">🖨️ Print Pass</button>
                <button onclick="closeBoardingPass()" class="bg-slate-800 text-white text-sm font-bold px-8 py-3 rounded-xl shadow-lg hover:bg-slate-900 transition transform hover:-translate-y-1">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Custom Toast logic
        function showToast(message, type='error') {
            let t = document.getElementById('toastAlert');
            let m = document.getElementById('toastMsg');
            m.innerText = message;
            if(type === 'error') {
                t.classList.add('bg-red-600'); t.classList.remove('bg-slate-800');
            } else {
                t.classList.add('bg-slate-800'); t.classList.remove('bg-red-600');
            }
            t.classList.add('toast-show');
            setTimeout(() => { t.classList.remove('toast-show'); }, 3000);
        }

        // Tab Management
        document.addEventListener('DOMContentLoaded', () => {
            let activeTab = localStorage.getItem('urental_user_tab') || 'dashboard';
            switchTab(activeTab);
        });

        function switchTab(tabId) {
            localStorage.setItem('urental_user_tab', tabId); 
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            
            const btns = ['dashboard', 'bookroom', 'floorplan', 'history'];
            const titles = {'dashboard': 'ផ្ទាំងស្វាគមន៍', 'bookroom': 'ស្នើសុំកក់បន្ទប់', 'floorplan': 'ពិនិត្យប្លង់បន្ទប់', 'history': 'ប្រវត្តិការជួល'};
            btns.forEach(id => {
                let btn = document.getElementById('nav-' + id);
                if(id === tabId) {
                    btn.classList.add('bg-white/10', 'text-white');
                    document.getElementById('headerTitle').innerText = titles[id];
                } else {
                    btn.classList.remove('bg-white/10', 'text-white');
                }
            });
        }

        // Floor Plan -> Booking Link
        function gotoBooking(roomId) {
            switchTab('bookroom');
            document.getElementById('roomSelect').value = roomId;
            fetchBookedSlots(); 
        }

        // Custom Confirm
        function triggerConfirm(actionType, linkUrl) {
            let title = document.getElementById('confirmTitle');
            let text = document.getElementById('confirmText');
            let icon = document.getElementById('confirmIcon');
            let btn = document.getElementById('confirmBtn');

            if(actionType === 'logout') {
                icon.innerText = "🚪"; icon.className = "w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl bg-slate-100 text-slate-600";
                title.innerText = "ចាកចេញពីគណនី"; text.innerText = "តើអ្នកពិតជាចង់ចាកចេញមែនទេ?";
            }
            btn.className = "px-5 py-2.5 rounded-xl text-sm font-bold text-white w-full text-center shadow-lg transition bg-slate-800 hover:bg-slate-900";
            btn.href = linkUrl;
            document.getElementById('customConfirmModal').classList.remove('opacity-0', 'pointer-events-none');
            setTimeout(() => { document.getElementById('confirmBox').classList.remove('scale-95'); }, 10);
        }

        function closeConfirm() {
            document.getElementById('confirmBox').classList.add('scale-95');
            setTimeout(() => { document.getElementById('customConfirmModal').classList.add('opacity-0', 'pointer-events-none'); }, 200);
        }

        function showSupportInfo() {
            document.getElementById('supportModal').classList.remove('opacity-0', 'pointer-events-none');
            setTimeout(() => { document.getElementById('supportBox').classList.remove('scale-95'); }, 10);
        }
        function closeSupportInfo() {
            document.getElementById('supportBox').classList.add('scale-95');
            setTimeout(() => { document.getElementById('supportModal').classList.add('opacity-0', 'pointer-events-none'); }, 200);
        }

        // Live Validation & Auto Set Check-Out
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

            // --- ១. Auto-Set ម៉ោងចេញ និងការពារកុំឱ្យរើសម៉ោងខុស ---
            if (checkIn) {
                checkOutInput.min = checkIn; // ហាមរើសម៉ោងចេញមុនម៉ោងចូលដាច់ខាត
                let inDate = new Date(checkIn);
                let outDate = new Date(checkOut);
                
                // បើអត់ទាន់រើសម៉ោងចេញ ឬ រើសម៉ោងចេញមុនម៉ោងចូល នោះវានឹង Set ម៉ោងស្អែកស្វ័យប្រវត្តិ
                if (!checkOut || outDate <= inDate) {
                    let nextDay = new Date(inDate.getTime() + (24 * 60 * 60 * 1000));
                    let tzoffset = (new Date()).getTimezoneOffset() * 60000;
                    checkOutInput.value = (new Date(nextDay - tzoffset)).toISOString().slice(0, 16);
                    checkOut = checkOutInput.value;
                }
            }

            // --- ២. ឆែកមើលក្រែងលោជាន់ម៉ោងគេ ---
            if (!checkIn || !checkOut || activeBookings.length === 0) {
                err.style.display = "none"; btn.disabled = false; btn.classList.remove('opacity-50', 'cursor-not-allowed');
            } else {
                let isOverlapping = activeBookings.some(b => (checkIn < b.check_out && checkOut > b.check_in));
                if (isOverlapping) {
                    err.style.display = "block"; btn.disabled = true; btn.classList.add('opacity-50', 'cursor-not-allowed');
                } else {
                    err.style.display = "none"; btn.disabled = false; btn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            }

            // --- ៣. គណនាតម្លៃឌីណាមិក (Dynamic Pricing) ---
            let roomSelect = document.getElementById('roomSelect');
            if (roomSelect.value && checkIn && checkOut && !btn.disabled) {
                let selectedOption = roomSelect.options[roomSelect.selectedIndex];
                let pricePerDay = parseFloat(selectedOption.getAttribute('data-price'));
                let inDate = new Date(checkIn);
                let outDate = new Date(checkOut);
                let hours = Math.abs(outDate - inDate) / 36e5; // រកចំនួនម៉ោងសរុប
                
                if (hours > 0) {
                    let totalPrice = (hours / 24) * pricePerDay;
                    let method = document.getElementById('paymentMethod').value;
                    let note = document.getElementById('paymentNote');
                    
                    if (method === 'online') {
                        note.innerHTML = `ចំណាំ៖ ប្រព័ន្ធនឹងនាំអ្នកទៅកាន់ទំព័រ <strong>U-Pay Checkout</strong> ភ្លាមៗដើម្បីទូទាត់ប្រាក់។ <br><span class="text-blue-600 font-black text-sm block mt-2">💰 តម្លៃសរុប (Total): $${totalPrice.toFixed(2)}</span>`;
                    } else {
                        note.innerHTML = `ចំណាំ៖ ការកក់របស់អ្នកនឹងស្ថិតក្នុងស្ថានភាព <strong class="text-red-500">❌ Unpaid</strong>។ សូមបង្ហាញសំបុត្រទៅកាន់ Admin។ <br><span class="text-slate-800 font-black text-sm block mt-2">💰 តម្លៃសរុប (Total): $${totalPrice.toFixed(2)}</span>`;
                    }
                }
            }
        }

        // Silent Refresh (Fixed Cache Issue)
        setInterval(() => {
            // 🛑 ផ្អាក Refresh ពេលកំពុងវាយអក្សរ
            const activeTag = document.activeElement ? document.activeElement.tagName : '';
            if (activeTag === 'INPUT' || activeTag === 'SELECT' || activeTag === 'TEXTAREA') {
                return; 
            }

            // ប្រើ pathname សុទ្ធ + ពេលវេលាជាក់ស្តែង ដើម្បីការពារ Browser Cache (កុំឱ្យវាយកទិន្នន័យចាស់)
            let cleanUrl = window.location.pathname + '?_t=' + new Date().getTime();

            fetch(cleanUrl, { cache: "no-store", headers: { 'Cache-Control': 'no-cache' } })
            .then(res => res.text())
            .then(html => {
                let doc = new DOMParser().parseFromString(html, 'text/html');
                
                // ១. Update តារាង (History)
                let searchInput = document.getElementById('searchInput');
                if(searchInput && searchInput.value === "") {
                    let oldHistory = document.querySelector('#historyTable tbody');
                    let newHistory = doc.querySelector('#historyTable tbody');
                    if(oldHistory && newHistory) oldHistory.innerHTML = newHistory.innerHTML;
                }
                
                // ២. Update ប្លង់បន្ទប់ (Floor Plan) ជានិច្ច
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
            document.querySelectorAll("#historyTable tbody tr").forEach(r => { r.style.display = r.innerText.toLowerCase().includes(input) ? "" : "none"; });
        }

        // Scanner
        let html5QrcodeScanner;
        function openScanner() {
            document.getElementById('scannerModal').classList.remove('opacity-0', 'pointer-events-none');
            setTimeout(() => { document.getElementById('scannerBox').classList.remove('scale-95'); }, 10);
            html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: {width: 250, height: 150} }, false);
            html5QrcodeScanner.render(t => {
                closeScanner(); switchTab('history');
                document.getElementById('searchInput').value = t.trim(); searchTable(); 
            }, () => {});
        }
        function closeScanner() {
            if(html5QrcodeScanner) html5QrcodeScanner.clear();
            document.getElementById('scannerBox').classList.add('scale-95');
            setTimeout(() => { document.getElementById('scannerModal').classList.add('opacity-0', 'pointer-events-none'); }, 200);
        }

        // Boarding Pass Modal
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
    </script>
</body>
</html>
<?php ob_end_flush(); ?>