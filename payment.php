<?php
session_start();
include 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'tenant') {
    header("Location: auth.php");
    exit();
}

if (!isset($_GET['id'])) {
    die("រកមិនឃើញលេខសម្គាល់ការកក់ (Booking ID)!");
}

$booking_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// ទាញយកព័ត៌មានការកក់ (ត្រូវប្រាកដថាជាការកក់របស់ User នេះពិតមែន)
$stmt = $conn->prepare("SELECT b.*, r.room_number, r.price, t.type_name FROM bookings b JOIN rooms r ON b.room_id = r.id LEFT JOIN room_types t ON r.type_id = t.id WHERE b.id = ? AND (b.user_id = ? OR b.tenant_phone = (SELECT phone FROM users WHERE id = ?))");
$stmt->bind_param("iii", $booking_id, $user_id, $user_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    die("ទិន្នន័យមិនត្រឹមត្រូវ ឬអ្នកគ្មានសិទ្ធិមើលការកក់នេះទេ!");
}

// គណនាតម្លៃសរុប (Dynamic Price ផ្អែកលើម៉ោងស្នាក់នៅ)
$inDate = new DateTime($booking['check_in_time']);
$outDate = new DateTime($booking['check_out_time']);
$hours = abs($outDate->getTimestamp() - $inDate->getTimestamp()) / 3600;
$total_price = ($hours / 24) * $booking['price'];
if ($total_price <= 0) $total_price = $booking['price'];

$formatCode = "BK-" . $booking['id'] . "-" . $booking['room_number'];
$account_number = "100000005"; // លេខគណនី U-Rental

// 🔥 ដូរមកប្រើទម្រង់ខ្លីវិញ ដើម្បីឱ្យកាមេរ៉ាងាយស្រួលស្កេន (លេខកុង : តម្លៃ : លេខកក់)
$qr_data = $account_number . ":" . number_format($total_price, 2, '.', '') . ":" . $formatCode;
// បើមាន Request បញ្ជាក់ការបង់ប្រាក់
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $method = $_POST['method']; // online, card
    
    // Update ទិន្នន័យទៅជា Paid (មិនប៉ះពាល់ដល់ Status ដើមទេ ទុកឱ្យ Admin អ្នក Check-in)
    $update = $conn->prepare("UPDATE bookings SET payment_status = 'paid', payment_method = ? WHERE id = ?");
    $update->bind_param("si", $method, $booking_id);
    
    if ($update->execute()) {
        header("Location: dashboard.php?msg=paid");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Checkout - U-Rental</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Kantumruy+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"/>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        body { font-family: 'Inter', 'Kantumruy Pro', sans-serif; background-color: #F8FAFC; color: #0F172A; }
        .tab-btn.active { background-color: #0B1120; color: white; border-color: #0B1120; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .tab-btn { background-color: white; color: #64748B; border: 1px solid #E2E8F0; }
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Card UI Style */
        .credit-card-input { background: url('https://cdn-icons-png.flaticon.com/512/25/25234.png') no-repeat; background-size: 24px; background-position: 96% 50%; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4 relative">
    
    <!-- ប៊ូតុងត្រឡប់ក្រោយ -->
    <a href="dashboard.php" class="absolute top-6 left-6 flex items-center gap-2 text-slate-500 hover:text-slate-800 font-bold bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg> ត្រឡប់ក្រោយ
    </a>

    <div class="bg-white w-full max-w-4xl rounded-[24px] shadow-2xl flex flex-col md:flex-row overflow-hidden border border-slate-100">
        
        <!-- ផ្នែកខាងឆ្វេង (វិក្កយបត្រ) -->
        <div class="bg-[#0B1120] text-white p-8 md:w-1/3 flex flex-col justify-between relative overflow-hidden">
            <svg class="absolute top-0 right-0 transform translate-x-1/3 -translate-y-1/3 opacity-10 w-64 h-64 pointer-events-none" fill="currentColor" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            
            <div class="relative z-10">
                <div class="text-[10px] font-bold tracking-widest text-emerald-400 uppercase mb-2">Secure Checkout</div>
                <h2 class="text-3xl font-black mb-8 leading-tight">វិក្កយបត្រ<br>ទូទាត់ប្រាក់</h2>
                
                <div class="space-y-4 text-sm text-slate-300 font-medium">
                    <div class="flex justify-between border-b border-slate-800 pb-2">
                        <span>លេខកក់ (Ref):</span>
                        <span class="text-white font-mono"><?php echo $formatCode; ?></span>
                    </div>
                    <div class="flex justify-between border-b border-slate-800 pb-2">
                        <span>បន្ទប់:</span>
                        <span class="text-white font-bold text-blue-400"><?php echo $booking['room_number']; ?></span>
                    </div>
                    <div class="flex justify-between border-b border-slate-800 pb-2">
                        <span>ឈ្មោះភ្ញៀវ:</span>
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

        <!-- ផ្នែកខាងស្តាំ (ប្រព័ន្ធទូទាត់ Online) -->
        <div class="p-8 md:w-2/3 bg-white">
            <div class="flex items-center justify-between mb-8">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 text-xl font-black">💳</div>
                    <h3 class="text-xl font-bold text-slate-800">ជ្រើសរើសវិធីសាស្ត្រទូទាត់</h3>
                </div>
            </div>

            <!-- Tabs Button (2 ជម្រើសសម្រាប់ Tenant) -->
            <div class="flex gap-2 mb-8 p-1.5 bg-slate-100 rounded-xl">
                <button onclick="switchPaymentTab('qr')" id="tab-qr" class="tab-btn active flex-1 py-3 px-4 rounded-lg font-bold text-sm transition flex justify-center items-center gap-2">
                    📱 U-Pay QR
                </button>
                <button onclick="switchPaymentTab('card')" id="tab-card" class="tab-btn flex-1 py-3 px-4 rounded-lg font-bold text-sm transition flex justify-center items-center gap-2">
                    💳 កាតធនាគារ
                </button>
            </div>

            <!-- Tab 1: QR Code -->
            <div id="content-qr" class="tab-content active text-center">
                <div class="bg-slate-50 border border-slate-200 rounded-3xl p-8 mb-6 relative overflow-hidden">
                    <p class="text-sm font-bold text-slate-600 mb-6">សូមស្កេន ឬ រក្សាទុក QR ខាងក្រោមដើម្បីទូទាត់</p>
                    
                    <div class="bg-white p-4 inline-block rounded-[1.5rem] border-2 border-slate-100 shadow-xl relative transform transition hover:scale-105">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=<?php echo urlencode($qr_data); ?>&color=0B1120" alt="Payment QR" class="rounded-xl">
                        
                        <!-- សញ្ញា Loading ចាំលុយចូល -->
                        <div class="absolute inset-0 bg-white/90 rounded-[1.2rem] flex flex-col items-center justify-center hidden backdrop-blur-sm" id="qrLoadingOverlay">
                            <div class="w-12 h-12 border-4 border-emerald-500 border-t-transparent rounded-full animate-spin mb-3 shadow-lg"></div>
                            <span class="text-xs font-black tracking-widest text-emerald-600 uppercase">កំពុងផ្ទៀងផ្ទាត់...</span>
                        </div>
                    </div>
                    
                    <div class="mt-8 grid grid-cols-2 gap-4">
                        <div class="bg-white p-3 rounded-xl border border-slate-200 shadow-sm text-left">
                            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">គណនី (A/C)</div>
                            <div class="text-slate-800 font-black font-mono text-sm"><?php echo $account_number; ?></div>
                        </div>
                        <div class="bg-white p-3 rounded-xl border border-slate-200 shadow-sm text-right">
                            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">ទឹកប្រាក់ (Amount)</div>
                            <div class="text-emerald-600 font-black text-sm">$<?php echo number_format($total_price, 2); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center justify-center gap-3 text-xs font-bold text-blue-600 bg-blue-50 py-3 rounded-xl border border-blue-200 shadow-sm animate-pulse">
                    <i class="fa-solid fa-satellite-dish"></i> ប្រព័ន្ធកំពុងរង់ចាំការទូទាត់ដោយស្វ័យប្រវត្តិ...
                </div>

                <form method="POST" id="qrSuccessForm" class="hidden">
                    <input type="hidden" name="method" value="online">
                    <button type="submit" name="confirm_payment" id="qrConfirmBtn"></button>
                </form>
            </div>

            <!-- Tab 2: Credit Card (Online Checkout) -->
            <div id="content-card" class="tab-content">
                <form method="POST" id="cardForm" onsubmit="processCardPayment(event)">
                    <input type="hidden" name="method" value="card">
                    
                    <div class="bg-white border-2 border-slate-100 rounded-3xl p-6 shadow-sm mb-6">
                        <div class="flex justify-between items-center mb-6">
                            <h4 class="font-bold text-slate-800">ព័ត៌មានកាត (Card Details)</h4>
                            <div class="flex gap-2">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/0/04/Visa.svg" alt="Visa" class="h-6">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/2/2a/Mastercard-logo.svg" alt="Mastercard" class="h-6">
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5">ឈ្មោះលើកាត (Name on Card)</label>
                                <input type="text" placeholder="e.g. SOK DARA" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 focus:bg-white transition uppercase font-medium text-sm" required>
                            </div>
                            
                            <div>
                                <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5">លេខកាត (Card Number)</label>
                                <input type="text" placeholder="0000 0000 0000 0000" maxlength="19" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 focus:bg-white transition font-mono text-sm credit-card-input" required>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5">ផុតកំណត់ (MM/YY)</label>
                                    <input type="text" placeholder="MM/YY" maxlength="5" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 focus:bg-white transition font-mono text-center text-sm" required>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5">លេខកូដសម្ងាត់ (CVC)</label>
                                    <input type="password" placeholder="***" maxlength="3" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 outline-none focus:border-blue-500 focus:bg-white transition font-mono text-center text-sm" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" id="btnSubmitCard" class="w-full bg-blue-600 text-white font-bold py-4 rounded-xl shadow-[0_10px_20px_-10px_rgba(37,99,235,0.5)] hover:bg-blue-700 hover:-translate-y-1 transition-all flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                        ទូទាត់ប្រាក់ឥឡូវនេះ (Pay $<?php echo number_format($total_price, 2); ?>)
                    </button>
                    <!-- Hidden button សម្រាប់ Submit ចូល PHP ក្រោយ JS ដើរចប់ -->
                    <button type="submit" name="confirm_payment" id="realCardSubmitBtn" class="hidden"></button>
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

        // --- មុខងារក្លែងធ្វើការកាត់លុយតាមកាត (Card Payment Simulation) ---
        function processCardPayment(e) {
            e.preventDefault(); // ទប់កុំឱ្យ Form Submit ភ្លាមៗ
            
            let btn = document.getElementById('btnSubmitCard');
            // ដូរ UI ប៊ូតុងទៅជា Loading
            btn.innerHTML = `<div class="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div> កំពុងដំណើរការកាត់ប្រាក់...`;
            btn.classList.add('opacity-80', 'cursor-not-allowed');
            btn.disabled = true;

            // ក្លែងធ្វើរង់ចាំ ៣ វិនាទី ដូចការកាត់លុយពីធនាគារពិតៗ
            setTimeout(() => {
                Swal.fire({
                    icon: 'success',
                    title: 'ទូទាត់តាមកាតជោគជ័យ!',
                    text: 'ប្រាក់ $<?php echo number_format($total_price, 2); ?> ត្រូវបានកាត់ចេញពីកាតរបស់អ្នក។',
                    confirmButtonColor: '#10B981',
                    confirmButtonText: 'ត្រឡប់ទៅផ្ទាំងដើម',
                    allowOutsideClick: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        // រុញ Form ពិតប្រាកដដើម្បី Save ចូល Database
                        document.getElementById('realCardSubmitBtn').click();
                    }
                });
            }, 3000);
        }

        // ==========================================
        // 🔥 AUTO DETECT PAYMENT VIA API (POLLING) សម្រាប់ U-Pay
        // ==========================================
        let paymentPollingInterval = null;
        let initialTrxCount = 0;
        const targetAccount = "<?php echo $account_number; ?>"; 
        const API_URL = "https://u-pay-bank.onrender.com/api/users"; 

        async function initPaymentListener() {
            try {
                const res = await fetch(API_URL); 
                const users = await res.json();
                
                const urentalAcc = users.find(u => u.accountNumber === targetAccount || u.username === "U-RENTAL");
                
                if (urentalAcc && urentalAcc.transactions) {
                    initialTrxCount = urentalAcc.transactions.length; 
                }
                
                if (paymentPollingInterval) clearInterval(paymentPollingInterval);
                paymentPollingInterval = setInterval(checkIncomingPayment, 3000);
            } catch (err) {
                console.log("API Init Error:", err);
            }
        }

        async function checkIncomingPayment() {
            try {
                const res = await fetch(API_URL);
                const users = await res.json();
                const urentalAcc = users.find(u => u.accountNumber === targetAccount || u.username === "U-RENTAL");

                if (urentalAcc && urentalAcc.transactions) {
                    if (urentalAcc.transactions.length > initialTrxCount) {
                        const latestTrx = urentalAcc.transactions[0]; 

                        if (latestTrx.amount > 0) {
                            clearInterval(paymentPollingInterval); 
                            
                            document.getElementById('qrLoadingOverlay').classList.remove('hidden');

                            setTimeout(() => {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'ទទួលបានប្រាក់ជោគជ័យ!',
                                    html: `ប្រព័ន្ធបានទូទាត់ប្រាក់ <b>$${latestTrx.amount}</b> រួចរាល់។`,
                                    confirmButtonColor: '#10B981',
                                    confirmButtonText: 'ត្រឡប់ទៅផ្ទាំងដើម',
                                    allowOutsideClick: false
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        document.getElementById('qrConfirmBtn').click(); 
                                    }
                                });
                            }, 1000);
                        } else {
                            initialTrxCount = urentalAcc.transactions.length;
                        }
                    }
                }
            } catch (err) {
                console.log("Polling Error:", err);
            }
        }

        document.getElementById('tab-qr').addEventListener('click', () => {
            initPaymentListener();
        });

        window.addEventListener('load', () => {
            initPaymentListener();
        });
    </script>
</body>
</html>