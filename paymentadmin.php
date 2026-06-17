<?php
session_start();
include 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: auth.php");
    exit();
}

if (!isset($_GET['id'])) {
    die("រកមិនឃើញលេខសម្គាល់ការកក់ (Booking ID)!");
}

$booking_id = intval($_GET['id']);

// ទាញយកព័ត៌មានការកក់
$stmt = $conn->prepare("SELECT b.*, r.room_number, r.price, t.type_name FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN room_types t ON r.type_id = t.id WHERE b.id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    die("ទិន្នន័យមិនត្រឹមត្រូវ!");
}

// គណនាតម្លៃសរុប (Dynamic Price ផ្អែកលើម៉ោងស្នាក់នៅ)
$inDate = new DateTime($booking['check_in_time']);
$outDate = new DateTime($booking['check_out_time']);
$hours = abs($outDate->getTimestamp() - $inDate->getTimestamp()) / 3600;
$total_price = ($hours / 24) * $booking['price'];
// ការពារកុំឱ្យតម្លៃក្រោមតម្លៃដើម១ថ្ងៃ បើកក់តិចម៉ោង
if ($total_price <= 0) $total_price = $booking['price'];

$formatCode = "BK-" . $booking['id'] . "-" . $booking['room_number'];
$account_number = "100000005"; // លេខគណនី U-Rental

// 🔥 ដូរមកប្រើទម្រង់ខ្លីវិញ ដើម្បីឱ្យកាមេរ៉ាងាយស្រួលស្កេន (លេខកុង : តម្លៃ : លេខកក់)
$qr_data = $account_number . ":" . number_format($total_price, 2, '.', '') . ":" . $formatCode;
// បើមាន Request បញ្ជាក់ការបង់ប្រាក់ (ពីការ Submit Form ពេល QR លោតជោគជ័យ ឬចុច Cash)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $method = $_POST['method']; // cash, online, card
    
    // 🔴 កន្លែងកែថ្មី៖ Update តែប្រាក់ប៉ុណ្ណោះ មិនប៉ះពាល់ Status ការកក់ដើមទេ
    $update = $conn->prepare("UPDATE bookings SET payment_status = 'paid', payment_method = ? WHERE id = ?");
    $update->bind_param("si", $method, $booking_id);
    
    if ($update->execute()) {
        header("Location: admin.php?msg=paid");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart POS - U-Rental Payment</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Kantumruy+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        body { font-family: 'Inter', 'Kantumruy Pro', sans-serif; background-color: #F1F5F9; color: #0F172A; }
        .tab-btn.active { background-color: #0B1120; color: white; border-color: #0B1120; }
        .tab-btn { background-color: white; color: #64748B; border: 1px solid #E2E8F0; }
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Animation សម្រាប់ Card Swipe */
        .pos-machine { position: relative; width: 120px; height: 160px; background: #1E293B; border-radius: 12px; margin: 0 auto; border-bottom: 8px solid #0F172A; }
        .pos-screen { width: 90px; height: 50px; background: #60A5FA; margin: 20px auto 10px; border-radius: 6px; border: 2px solid #0F172A; animation: screenBlink 2s infinite; }
        .pos-card { position: absolute; width: 80px; height: 50px; background: #FCD34D; border-radius: 6px; top: -20px; left: 50%; transform: translateX(-50%); animation: swipeCard 3s infinite; z-index: -1; box-shadow: inset 0 0 0 2px #F59E0B; }
        .pos-slot { position: absolute; width: 100px; height: 6px; background: #000; top: -3px; left: 50%; transform: translateX(-50%); border-radius: 10px; z-index: 1; }
        
        @keyframes swipeCard {
            0% { top: -60px; opacity: 0; }
            20% { top: -60px; opacity: 1; }
            50% { top: 0px; opacity: 1; }
            80% { top: 40px; opacity: 0; }
            100% { top: -60px; opacity: 0; }
        }
        @keyframes screenBlink {
            0%, 100% { background: #60A5FA; }
            50% { background: #3B82F6; }
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4 relative">
    
    <a href="admin.php" class="absolute top-6 left-6 flex items-center gap-2 text-slate-500 hover:text-slate-800 font-bold bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg> ថយក្រោយ
    </a>

    <div class="bg-white w-full max-w-4xl rounded-3xl shadow-2xl flex flex-col md:flex-row overflow-hidden border border-slate-100">
        
        <div class="bg-[#0B1120] text-white p-8 md:w-1/3 flex flex-col justify-between relative overflow-hidden">
            <svg class="absolute top-0 right-0 transform translate-x-1/3 -translate-y-1/3 opacity-10 w-64 h-64 pointer-events-none" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"></path></svg>
            
            <div class="relative z-10">
                <div class="text-[10px] font-bold tracking-widest text-blue-400 uppercase mb-2">Order Summary</div>
                <h2 class="text-3xl font-black mb-8 leading-tight">វិក្កយបត្រ<br>ទូទាត់ប្រាក់</h2>
                
                <div class="space-y-4 text-sm text-slate-300 font-medium">
                    <div class="flex justify-between border-b border-slate-800 pb-2">
                        <span>លេខកក់ (Ref):</span>
                        <span class="text-white font-mono"><?php echo $formatCode; ?></span>
                    </div>
                    <div class="flex justify-between border-b border-slate-800 pb-2">
                        <span>បន្ទប់:</span>
                        <span class="text-white">បន្ទប់ <?php echo $booking['room_number']; ?></span>
                    </div>
                    <div class="flex justify-between border-b border-slate-800 pb-2">
                        <span>ភ្ញៀវ:</span>
                        <span class="text-white uppercase"><?php echo htmlspecialchars($booking['tenant_name']); ?></span>
                    </div>
                    <div class="flex justify-between pb-2">
                        <span>រយៈពេលស្នាក់នៅ:</span>
                        <span class="text-white"><?php echo number_format($hours, 1); ?> ម៉ោង</span>
                    </div>
                </div>
            </div>
            
            <div class="mt-8 relative z-10 bg-white/10 p-5 rounded-2xl border border-white/20">
                <div class="text-[11px] text-slate-300 font-bold uppercase mb-1">Total Amount Due</div>
                <div class="text-4xl font-black text-emerald-400">$<?php echo number_format($total_price, 2); ?></div>
            </div>
        </div>

        <div class="p-8 md:w-2/3 bg-white">
            <div class="flex items-center gap-3 mb-8">
                <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 text-xl font-black">💳</div>
                <h3 class="text-xl font-bold text-slate-800">ជ្រើសរើសវិធីសាស្ត្រទូទាត់</h3>
            </div>

            <div class="flex gap-2 mb-8 p-1 bg-slate-100 rounded-xl">
                <button onclick="switchPaymentTab('qr')" id="tab-qr" class="tab-btn active flex-1 py-3 px-4 rounded-lg font-bold text-sm transition">
                    📱 U-Pay QR
                </button>
                <button onclick="switchPaymentTab('cash')" id="tab-cash" class="tab-btn flex-1 py-3 px-4 rounded-lg font-bold text-sm transition">
                    💵 សាច់ប្រាក់
                </button>
                <button onclick="switchPaymentTab('card')" id="tab-card" class="tab-btn flex-1 py-3 px-4 rounded-lg font-bold text-sm transition">
                    💳 ឆូតកាត
                </button>
            </div>

            <div id="content-qr" class="tab-content active text-center">
                <div class="bg-blue-50 border border-blue-100 rounded-2xl p-6 mb-6 relative overflow-hidden">
                    <p class="text-sm font-bold text-blue-800 mb-4">សូមភ្ញៀវស្កេន QR ខាងក្រោម ដើម្បីទូទាត់</p>
                    <div class="bg-white p-3 inline-block rounded-2xl border-2 border-slate-200 shadow-md relative">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($qr_data); ?>&color=0B1120" alt="Payment QR" class="rounded-xl">
                        
                        <div class="absolute inset-0 bg-white/90 rounded-xl flex flex-col items-center justify-center hidden" id="qrLoadingOverlay">
                            <div class="w-10 h-10 border-4 border-emerald-500 border-t-transparent rounded-full animate-spin mb-2"></div>
                            <span class="text-xs font-bold text-emerald-700">កំពុងផ្ទៀងផ្ទាត់ប្រាក់...</span>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-col gap-1 text-[11px] font-bold text-slate-500 uppercase tracking-widest">
                        <span>U-REANTAL</span>
                        <span>Amount: <span class="text-emerald-600 text-sm">$<?php echo number_format($total_price, 2); ?></span></span>
                    </div>
                </div>
                
                <div class="flex items-center justify-center gap-3 text-sm font-bold text-blue-600 bg-blue-50 py-3 rounded-xl border border-blue-200 shadow-sm animate-pulse">
                    <i class="fa-solid fa-satellite-dish"></i> ប្រព័ន្ធកំពុងរង់ចាំការទូទាត់ដោយស្វ័យប្រវត្តិ...
                </div>

                <form method="POST" id="qrSuccessForm" class="hidden">
                    <input type="hidden" name="method" value="online">
                    <button type="submit" name="confirm_payment" id="qrConfirmBtn"></button>
                </form>
            </div>

            <div id="content-cash" class="tab-content">
                <form method="POST">
                    <input type="hidden" name="method" value="cash">
                    <div class="bg-slate-50 border border-slate-200 rounded-2xl p-6 mb-6">
                        <div class="mb-5">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">ប្រាក់ត្រូវបង់ (Total Due)</label>
                            <div class="text-2xl font-black text-slate-800">$<span id="cashDue"><?php echo number_format($total_price, 2); ?></span></div>
                        </div>
                        
                        <div class="mb-5">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-2">ប្រាក់ទទួលពីភ្ញៀវ (Received)</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 transform -translate-y-1/2 text-slate-400 font-bold text-lg">$</span>
                                <input type="number" step="0.01" id="cashReceived" oninput="calculateChange()" class="w-full bg-white border-2 border-blue-100 rounded-xl pl-8 pr-4 py-4 outline-none focus:border-blue-500 text-lg font-bold text-slate-800" placeholder="0.00" required>
                            </div>
                        </div>

                        <div class="bg-white p-4 rounded-xl border border-slate-200 flex justify-between items-center shadow-inner">
                            <span class="text-sm font-bold text-slate-500 uppercase">ប្រាក់អាប់ (Change)</span>
                            <span class="text-2xl font-black text-emerald-500">$<span id="cashChange">0.00</span></span>
                        </div>
                    </div>
                    
                    <button type="submit" name="confirm_payment" id="btnSubmitCash" class="w-full bg-[#0B1120] text-white font-bold py-4 rounded-xl shadow-lg hover:bg-slate-800 transition disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                        យល់ព្រមទទួលសាច់ប្រាក់
                    </button>
                </form>
            </div>

            <div id="content-card" class="tab-content text-center">
                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-8 mb-6 relative overflow-hidden flex flex-col items-center justify-center min-h-[250px]">
                    <div class="pos-machine relative">
                        <div class="pos-slot"></div>
                        <div class="pos-card"></div>
                        <div class="pos-screen flex items-center justify-center">
                            <span class="text-[8px] font-bold text-white">$<?php echo number_format($total_price, 2); ?></span>
                        </div>
                        <div class="grid grid-cols-3 gap-1 px-3 mt-1">
                            <div class="w-full h-2 bg-slate-700 rounded"></div><div class="w-full h-2 bg-slate-700 rounded"></div><div class="w-full h-2 bg-slate-700 rounded"></div>
                            <div class="w-full h-2 bg-slate-700 rounded"></div><div class="w-full h-2 bg-slate-700 rounded"></div><div class="w-full h-2 bg-slate-700 rounded"></div>
                        </div>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 mt-6">រង់ចាំការឆូតកាត...</h3>
                    <p class="text-[11px] font-bold text-blue-500 tracking-widest uppercase mt-1">Coming Soon</p>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="method" value="card">
                    <button type="submit" name="confirm_payment" class="w-full bg-blue-600 text-white font-bold py-4 rounded-xl shadow-lg hover:bg-blue-700 transition">
                        [TEST] ក្លែងធ្វើឆូតកាតជោគជ័យ
                    </button>
                </form>
            </div>

        </div>
    </div>

    <script>
        // --- មុខងារដូរ Tab ---
        function switchPaymentTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active', 'bg-[#0B1120]', 'text-white'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            let targetBtn = document.getElementById('tab-' + tabId);
            targetBtn.classList.add('active', 'bg-[#0B1120]', 'text-white');
            document.getElementById('content-' + tabId).classList.add('active');
        }

        // --- មុខងារគណនាប្រាក់អាប់ ---
        function calculateChange() {
            let due = parseFloat(document.getElementById('cashDue').innerText);
            let received = parseFloat(document.getElementById('cashReceived').value);
            let btn = document.getElementById('btnSubmitCash');
            let changeDisplay = document.getElementById('cashChange');

            if (isNaN(received)) received = 0;

            let change = received - due;
            
            if (change >= 0) {
                changeDisplay.innerText = change.toFixed(2);
                changeDisplay.parentElement.classList.replace('text-red-500', 'text-emerald-500');
                btn.disabled = false; // បើកប៊ូតុងឱ្យចុច
            } else {
                changeDisplay.innerText = "0.00";
                changeDisplay.parentElement.classList.replace('text-emerald-500', 'text-red-500');
                btn.disabled = true; // បិទប៊ូតុង
            }
        }

       // ==========================================
        // 🔥 AUTO DETECT PAYMENT VIA API (POLLING)
        // ==========================================
        let paymentPollingInterval = null;
        let initialTrxCount = 0;
        const targetAccount = "<?php echo $account_number; ?>"; // 100000005
        
        // 👉 បញ្ចូល URL API ពេញលេញនៅទីនេះ
        const API_URL = "https://u-pay-bank.onrender.com/api/users"; 

        // មុខងារទាញចំនួន Transaction ដើមមុនពេលស្កេន
        async function initPaymentListener() {
            try {
                const res = await fetch(API_URL); 
                const users = await res.json();
                
                // រកមើលគណនី U-RENTAL (100000005)
                const urentalAcc = users.find(u => u.accountNumber === targetAccount || u.username === "U-RENTAL");
                
                if (urentalAcc && urentalAcc.transactions) {
                    initialTrxCount = urentalAcc.transactions.length; // កត់ត្រាចំនួនចាស់
                    console.log("Tracking API Connected! Current Trx Count:", initialTrxCount);
                } else {
                    console.log("រកមិនឃើញគណនី U-RENTAL នៅក្នុង API ទេ!");
                }
                
                if (paymentPollingInterval) clearInterval(paymentPollingInterval);
                paymentPollingInterval = setInterval(checkIncomingPayment, 3000); // ឆែករៀងរាល់ 3 វិនាទី
            } catch (err) {
                console.log("API Init Error (អាចមកពីដាច់អ៊ីនធឺណិត ឬ CORS):", err);
            }
        }

        // មុខងារឆែករង់ចាំលុយចូល
        async function checkIncomingPayment() {
            try {
                const res = await fetch(API_URL);
                const users = await res.json();
                const urentalAcc = users.find(u => u.accountNumber === targetAccount || u.username === "U-RENTAL");

                if (urentalAcc && urentalAcc.transactions) {
                    // ប្រសិនបើមាន Transaction ថ្មីកើតឡើង
                    if (urentalAcc.transactions.length > initialTrxCount) {
                        const latestTrx = urentalAcc.transactions[0]; // យក Transaction ថ្មីបំផុត

                        // ឆែកមើលថាវាជាការទទួលប្រាក់ (+) 
                        if (latestTrx.amount > 0) {
                            clearInterval(paymentPollingInterval); // បញ្ឈប់ការឆែក
                            
                            // បង្ហាញ Loading លើ QR
                            document.getElementById('qrLoadingOverlay').classList.remove('hidden');

                            setTimeout(() => {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'ទូទាត់ជោគជ័យ!',
                                    html: `ទទួលបានប្រាក់ <b>$${latestTrx.amount}</b> ពី <b>${latestTrx.senderName || 'អតិថិជន'}</b> រួចរាល់។`,
                                    confirmButtonColor: '#10B981',
                                    confirmButtonText: 'បញ្ចប់ការកត់ត្រា',
                                    allowOutsideClick: false
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        // Auto click Submit Form ដើម្បី Update Database
                                        document.getElementById('qrConfirmBtn').click(); 
                                    }
                                });
                            }, 1000);
                        } else {
                            // បើកាត់លុយចេញ គ្រាន់តែអាប់ដេត Count ទុក
                            initialTrxCount = urentalAcc.transactions.length;
                        }
                    }
                }
            } catch (err) {
                console.log("Polling Error:", err);
            }
        }

        // ចាប់ផ្តើមស្តាប់ (Listen) នៅពេលចុចលើ Tab QR Code
        document.getElementById('tab-qr').addEventListener('click', () => {
            initPaymentListener();
        });

        // ហៅឱ្យវាដើរអូតូពេលបើកទំព័រនេះដំបូង
        window.addEventListener('load', () => {
            initPaymentListener();
        });
    </script>
</body>
</html>