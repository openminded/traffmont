<?php
/**
 * TraffMont Login Page
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Auth.php';

Auth::startSession();

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    if (!Auth::verifyCsrfToken($csrf)) {
        $error = 'Invalid security token. Silakan muat ulang halaman.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            Auth::login($user['id'], $user['username']);
            header('Location: index.php');
            exit;
        } else {
            $error = 'Username atau password salah.';
        }
    }
}

$csrfToken = Auth::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - TraffMont MikroTik Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #0b1120;
            color: #f1f5f9;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .login-card {
            background-color: #1e293b;
            border: 1px solid #334155;
            border-radius: 14px;
            width: 100%;
            max-width: 420px;
            padding: 35px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.4);
        }
        .form-control {
            background-color: #0f172a;
            border: 1px solid #334155;
            color: #f8fafc;
            padding: 12px 14px;
        }
        .form-control:focus {
            background-color: #0f172a;
            color: #fff;
            border-color: #38bdf8;
            box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.25);
        }
        .btn-primary {
            background-color: #0284c7;
            border-color: #0284c7;
            padding: 12px;
            font-weight: 600;
        }
        .btn-primary:hover {
            background-color: #0369a1;
            border-color: #0369a1;
        }
    </style>
</head>
<body>
<div class="login-card">
    <div class="text-center mb-4">
        <div class="text-info mb-2"><i class="bi bi-activity display-4"></i></div>
        <h4 class="fw-bold mb-1">TraffMont</h4>
        <p class="text-secondary small">MikroTik Traffic, Uptime & SLA Monitor</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2 small">
            <i class="bi bi-exclamation-circle-fill me-1"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

        <div class="mb-3">
            <label class="form-label text-secondary small fw-semibold">Username</label>
            <div class="input-group">
                <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-person"></i></span>
                <input type="text" name="username" class="form-control" placeholder="Masukkan username" required autofocus>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label text-secondary small fw-semibold">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-lock"></i></span>
                <input type="password" name="password" class="form-control" placeholder="Masukkan password" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-box-arrow-in-right me-1"></i> Masuk Dashboard
        </button>
    </form>
</div>
</body>
</html>

