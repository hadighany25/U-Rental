<?php
ob_start(); 
session_start();
include 'config.php';

$error_msg = "";
$success_msg = "";

// ១. មុខងារ Logout (ចាកចេញ)
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_unset();
    session_destroy();
    header("Location: auth.php");
    exit();
}

// ២. មុខងារ Login (ចូលប្រើប្រាស់)
if (isset($_POST['login_btn'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Admin Login Verification
    if (strtolower($username) === 'admin' && $password === '123') {
        session_regenerate_id(true);
        $_SESSION['role'] = 'admin';
        $_SESSION['fullname'] = 'Administrator';
        header("Location: admin.php");
        exit();
    } else {
        // User Login Verification (អាច Login តាម ឈ្មោះ ឬ លេខទូរស័ព្ទ)
        $stmt = $conn->prepare("SELECT id, fullname, phone, password FROM users WHERE username = ? OR phone = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            // ផ្ទៀងផ្ទាត់ Password
            if ($password === $user['password']) {
                session_regenerate_id(true);
                $_SESSION['role'] = 'tenant';
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['fullname'] = $user['fullname'];
                $_SESSION['phone'] = $user['phone'];
                header("Location: dashboard.php");
                exit();
            } else {
                $error_msg = "លេខសម្ងាត់របស់អ្នកមិនត្រឹមត្រូវទេ!";
            }
        } else {
            $error_msg = "មិនមានគណនីនេះក្នុងប្រព័ន្ធឡើយ!";
        }
    }
}

// ៣. មុខងារ Register (ចុះឈ្មោះគណនីថ្មី)
if (isset($_POST['register_btn'])) {
    $username = trim($_POST['reg_username']);
    $fullname = trim($_POST['reg_fullname']);
    $phone = trim($_POST['reg_phone']);
    $password = trim($_POST['reg_password']);

    // ឆែកមើលថាតើឈ្មោះ ឬ លេខទូរស័ព្ទ មានគេប្រើរួចហើយឬនៅ
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR phone = ?");
    $check->bind_param("ss", $username, $phone);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $error_msg = "ឈ្មោះគណនី ឬលេខទូរស័ព្ទនេះមានគេប្រើប្រាស់រួចហើយ!";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, password, fullname, phone) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $password, $fullname, $phone);
        if ($stmt->execute()) {
            $success_msg = "ចុះឈ្មោះទទួលបានជោគជ័យ! សូមធ្វើការចូលប្រើប្រាស់។";
        } else {
            $error_msg = "មានបញ្ហាក្នុងការចុះឈ្មោះ សូមព្យាយាមម្តងទៀត!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="km">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to U-Rental</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Kantumruy+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Kantumruy Pro', sans-serif; background-color: #0B1120; }
        .form-panel { display: none; opacity: 0; transform: translateY(10px); transition: all 0.4s ease; }
        .form-panel.active { display: block; opacity: 1; transform: translateY(0); }
        
        /* Background Animated Orbs for Premium Look */
        .blob { position: absolute; filter: blur(80px); z-index: 0; opacity: 0.4; animation: float 10s infinite ease-in-out alternate; }
        .blob-1 { top: -10%; left: -10%; width: 400px; height: 400px; background: #3B82F6; }
        .blob-2 { bottom: -10%; right: -10%; width: 500px; height: 500px; background: #10B981; animation-delay: -5s; }
        
        @keyframes float { 
            0% { transform: translateY(0px) scale(1); } 
            100% { transform: translateY(50px) scale(1.1); } 
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen relative overflow-hidden text-slate-800">

    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>

    <div class="bg-white/95 backdrop-blur-xl rounded-[24px] shadow-2xl w-full max-w-[420px] p-10 relative z-10 border border-white/20">
        
        <div class="text-center mb-8">
            <h1 class="text-3xl font-black tracking-tight text-[#0B1120]"><span class="text-[#10B981]">U-</span>RENTAL</h1>
            <p class="text-sm text-slate-500 font-medium mt-1">Enterprise Property Management</p>
        </div>

        <?php if ($error_msg): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-xl text-sm font-bold mb-6 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <?php echo $error_msg; ?>
            </div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-600 px-4 py-3 rounded-xl text-sm font-bold mb-6 flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <?php echo $success_msg; ?>
            </div>
        <?php endif; ?>

        <div class="flex p-1 bg-slate-100 rounded-xl mb-8">
            <button onclick="switchTab('login')" id="btn-login" class="flex-1 py-2.5 rounded-lg text-sm font-bold transition-all shadow bg-white text-[#0B1120]">ចូលប្រើប្រាស់</button>
            <button onclick="switchTab('register')" id="btn-register" class="flex-1 py-2.5 rounded-lg text-sm font-bold transition-all text-slate-500 hover:text-slate-800">បង្កើតគណនី</button>
        </div>

        <form method="POST" id="loginForm" class="form-panel <?php echo (!$success_msg && !isset($_POST['register_btn'])) ? 'active' : ''; ?>">
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">ឈ្មោះគណនី ឬទូរស័ព្ទ</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        </div>
                        <input type="text" name="username" placeholder="Username / Phone" class="w-full bg-slate-50 border border-slate-200 rounded-xl py-3 pl-11 pr-4 text-sm outline-none focus:border-[#3B82F6] focus:ring-1 focus:ring-[#3B82F6] transition" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">លេខសម្ងាត់</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                        </div>
                        <input type="password" name="password" placeholder="••••••••" class="w-full bg-slate-50 border border-slate-200 rounded-xl py-3 pl-11 pr-4 text-sm outline-none focus:border-[#3B82F6] focus:ring-1 focus:ring-[#3B82F6] transition" required>
                    </div>
                </div>
            </div>
            
            <button type="submit" name="login_btn" class="w-full mt-8 bg-[#0B1120] hover:bg-[#1E293B] text-white font-bold py-3.5 rounded-xl shadow-lg transition transform hover:-translate-y-0.5 flex items-center justify-center gap-2">
                ចូលប្រព័ន្ធ <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
            </button>
            <div class="text-center mt-6">
                <span class="text-xs text-slate-400">គ្មានគណនី? <button type="button" onclick="switchTab('register')" class="text-[#10B981] font-bold hover:underline">ចុះឈ្មោះឥឡូវនេះ</button></span>
            </div>
        </form>

        <form method="POST" id="registerForm" class="form-panel <?php echo ($success_msg || isset($_POST['register_btn'])) ? 'active' : ''; ?>">
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">ឈ្មោះគណនី (Login Name)</label>
                    <input type="text" name="reg_username" placeholder="ឧ. chanchhay" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-[#10B981] transition" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">ឈ្មោះពេញ (Full Name)</label>
                    <input type="text" name="reg_fullname" placeholder="ឈ្មោះលើវិក្កយបត្រ" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-[#10B981] transition" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">លេខទូរស័ព្ទ (Phone)</label>
                    <input type="text" name="reg_phone" placeholder="012345678" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-[#10B981] transition" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">លេខសម្ងាត់ (Password)</label>
                    <input type="password" name="reg_password" placeholder="••••••••" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-[#10B981] transition" required>
                </div>
            </div>

            <button type="submit" name="register_btn" class="w-full mt-8 bg-[#10B981] hover:bg-[#059669] text-white font-bold py-3.5 rounded-xl shadow-lg shadow-emerald-500/30 transition transform hover:-translate-y-0.5 flex items-center justify-center gap-2">
                បង្កើតគណនីថ្មី <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
            </button>
        </form>

    </div>

    <script>
        function switchTab(type) {
            let lForm = document.getElementById('loginForm');
            let rForm = document.getElementById('registerForm');
            let lBtn = document.getElementById('btn-login');
            let rBtn = document.getElementById('btn-register');

            if (type === 'login') {
                rForm.classList.remove('active');
                setTimeout(() => { lForm.classList.add('active'); }, 100);
                
                lBtn.className = "flex-1 py-2.5 rounded-lg text-sm font-bold transition-all shadow bg-white text-[#0B1120]";
                rBtn.className = "flex-1 py-2.5 rounded-lg text-sm font-bold transition-all text-slate-500 hover:text-slate-800";
            } else {
                lForm.classList.remove('active');
                setTimeout(() => { rForm.classList.add('active'); }, 100);
                
                rBtn.className = "flex-1 py-2.5 rounded-lg text-sm font-bold transition-all shadow bg-white text-[#0B1120]";
                lBtn.className = "flex-1 py-2.5 rounded-lg text-sm font-bold transition-all text-slate-500 hover:text-slate-800";
            }
        }
    </script>
</body>
</html>
<?php ob_end_flush(); ?>