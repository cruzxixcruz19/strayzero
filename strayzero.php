<?php
session_start();

if(isset($_GET['logout'])) { 
    session_destroy(); 
    header('Location: ?page=home'); 
    exit;
}

$host = 'localhost';
$dbname = 'strayzero';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname");
    $pdo->exec("USE $dbname");

    // Initialize Schema
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'user') DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS dogs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        collar_id VARCHAR(20) UNIQUE NOT NULL,
        breed VARCHAR(50),
        color VARCHAR(30),
        size VARCHAR(50),
        gender ENUM('male', 'female', 'unknown'),
        health_condition TEXT,
        vaccination_status VARCHAR(255),
        capture_date DATE,
        capture_location VARCHAR(255),
        photo_url VARCHAR(255),
        status ENUM('captured', 'in_shelter', 'available', 'claimed', 'adopted') DEFAULT 'captured',
        status_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Migration: Update columns if they don't exist or need changing
    $columns = $pdo->query("SHOW COLUMNS FROM dogs")->fetchAll(PDO::FETCH_COLUMN);
    
    // Change size to VARCHAR if it's still ENUM or needs update
    $pdo->exec("ALTER TABLE dogs MODIFY COLUMN size VARCHAR(50)");

    if (!in_array('status_updated_at', $columns)) {
        $pdo->exec("ALTER TABLE dogs ADD COLUMN status_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status");
    }

    // Auto-Cleanup: Remove dogs claimed/adopted more than 3 days ago
    $pdo->exec("DELETE FROM dogs WHERE status IN ('claimed', 'adopted') AND status_updated_at <= DATE_SUB(NOW(), INTERVAL 3 DAY)");

    if (!in_array('health_condition', $columns)) {
        $pdo->exec("ALTER TABLE dogs ADD COLUMN health_condition TEXT AFTER gender");
    }
    if (!in_array('vaccination_status', $columns)) {
        $pdo->exec("ALTER TABLE dogs ADD COLUMN vaccination_status VARCHAR(255) AFTER health_condition");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS stray_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        title VARCHAR(100),
        description TEXT,
        dog_color VARCHAR(30),
        dog_size VARCHAR(50),
        location VARCHAR(255),
        photo_url VARCHAR(255),
        status ENUM('pending', 'processed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )");

    // Migration for stray_reports: Add location if it doesn't exist
    $columns_reports = $pdo->query("SHOW COLUMNS FROM stray_reports")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('location', $columns_reports)) {
        $pdo->exec("ALTER TABLE stray_reports ADD COLUMN location VARCHAR(255) AFTER dog_size");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS adoption_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dog_id INT,
        user_id INT,
        reason TEXT,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (dog_id) REFERENCES dogs(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Migration: Add missing columns to adoption_requests
    $columns_adopt = $pdo->query("SHOW COLUMNS FROM adoption_requests")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reason', $columns_adopt)) {
        $pdo->exec("ALTER TABLE adoption_requests ADD COLUMN reason TEXT AFTER user_id");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        message TEXT,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Seed Admin if not exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['admin', 'admin@strayzero.org', password_hash('password', PASSWORD_DEFAULT), 'admin']);
    }

} catch(PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

$page = $_GET['page'] ?? 'home';
$role = $_SESSION['role'] ?? null;
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if ($action == 'login') {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=?");
    $stmt->execute([$_POST['username']]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header('Location: ?page=dashboard');
        exit;
    }
}

if ($action == 'register') {
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')");
    $stmt->execute([$_POST['username'], $_POST['email'], password_hash($_POST['password'], PASSWORD_DEFAULT)]);
    $_SESSION['success_msg'] = "Account created! You can now login.";
    header('Location: ?page=login');
    exit;
}

if ($action == 'add_dog' && $role == 'admin') {
    // Get next ID for collar_id (format: DOG0001, DOG0002, etc.)
    $stmt = $pdo->query("SELECT MAX(id) FROM dogs");
    $max_id = $stmt->fetchColumn() ?: 0;
    $collar_id = 'DOG' . str_pad($max_id + 1, 4, '0', STR_PAD_LEFT);
    
    $photo_url = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $target_dir = "uploads/dogs/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $photo_url = $target_dir . time() . '_' . basename($_FILES["photo"]["name"]);
        move_uploaded_file($_FILES["photo"]["tmp_name"], $photo_url);
    }
    $stmt = $pdo->prepare("INSERT INTO dogs (collar_id, breed, color, size, gender, health_condition, vaccination_status, capture_date, capture_location, photo_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'captured')");
    $stmt->execute([
        $collar_id, 
        $_POST['breed'], 
        $_POST['color'], 
        $_POST['size'], 
        $_POST['gender'],
        $_POST['health_condition'],
        $_POST['vaccination_status'],
        $_POST['capture_date'], 
        $_POST['location'], 
        $photo_url
    ]);
    $_SESSION['success_msg'] = "Dog added successfully! Collar ID: $collar_id";
    header('Location: ?page=dashboard');
    exit;
}

if ($action == 'adoption_request' && $role) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM adoption_requests WHERE dog_id = ? AND user_id = ?");
    $stmt->execute([$_POST['dog_id'], $_SESSION['user_id']]);
    if ($stmt->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO adoption_requests (dog_id, user_id, reason) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['dog_id'], $_SESSION['user_id'], $_POST['reason']]);
        $_SESSION['success_msg'] = "Adoption request submitted successfully!";
    } else {
        $_SESSION['error_msg'] = "You have already submitted a request for this dog.";
    }
    header('Location: ?page=dogs');
    exit;
}

if ($action == 'update_status' && $role == 'admin') {
    $stmt = $pdo->prepare("UPDATE dogs SET status=?, status_updated_at=NOW() WHERE id=?");
    $stmt->execute([$_GET['status'], $_GET['id']]);
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}

if ($action == 'handle_adoption' && $role == 'admin') {
    $stmt = $pdo->prepare("UPDATE adoption_requests SET status=? WHERE id=?");
    $stmt->execute([$_GET['status'], $_GET['id']]);
    
    // Get user_id and dog_id for notification
    $stmt = $pdo->prepare("SELECT user_id, dog_id FROM adoption_requests WHERE id=?");
    $stmt->execute([$_GET['id']]);
    $req = $stmt->fetch();
    
    $stmt = $pdo->prepare("SELECT collar_id FROM dogs WHERE id=?");
    $stmt->execute([$req['dog_id']]);
    $collar_id = $stmt->fetchColumn();
    
    $msg = "Your adoption request for dog $collar_id has been " . $_GET['status'] . ".";
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $stmt->execute([$req['user_id'], $msg]);

    if ($_GET['status'] == 'approved') {
        $stmt = $pdo->prepare("UPDATE dogs SET status='adopted', status_updated_at=NOW() WHERE id=?");
        $stmt->execute([$req['dog_id']]);
    }
    header('Location: ?page=dashboard');
    exit;
}

if ($action == 'report_stray') {
    $photo_url = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $target_dir = "uploads/reports/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $photo_url = $target_dir . time() . '_' . basename($_FILES["photo"]["name"]);
        move_uploaded_file($_FILES["photo"]["tmp_name"], $photo_url);
    }
    $user_id = $_SESSION['user_id'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO stray_reports (user_id, title, location, description, dog_color, dog_size, photo_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$user_id, $_POST['location'], $_POST['location'], $_POST['description'], $_POST['color'], $_POST['size'], $photo_url]);
    
    // Notify Admins
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role='admin'");
    $stmt->execute();
    $admins = $stmt->fetchAll();
    foreach($admins as $admin) {
        $msg = "New stray dog reported at: " . $_POST['location'];
        $n = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $n->execute([$admin['id'], $msg]);
    }
    
    $_SESSION['success_msg'] = "Stray dog reported! Thank you for your help.";
    header('Location: ?page=report');
    exit;
}

if ($action == 'process_report' && $role == 'admin') {
    $stmt = $pdo->prepare("UPDATE stray_reports SET status='processed' WHERE id=?");
    $stmt->execute([$_GET['id']]);
    $_SESSION['success_msg'] = "Report marked as processed.";
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit;
}

if ($action == 'update_profile') {
    if (!empty($_POST['password'])) {
        $stmt = $pdo->prepare("UPDATE users SET email=?, password=? WHERE id=?");
        $stmt->execute([$_POST['email'], password_hash($_POST['password'], PASSWORD_DEFAULT), $_SESSION['user_id']]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET email=? WHERE id=?");
        $stmt->execute([$_POST['email'], $_SESSION['user_id']]);
    }
    $_SESSION['success_msg'] = "Profile updated successfully!";
    header('Location: ?page=profile');
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StrayZero - Modern Stray Dog Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
            --secondary-gradient: linear-gradient(135deg, #3b82f6 0%, #2dd4bf 100%);
            --bg-soft: #f8fafc;
            --text-main: #1e293b;
            --sidebar-width: 280px;
        }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg-soft); color: var(--text-main); }
        
        .hero { 
            background: var(--primary-gradient); 
            color: white; 
            padding: 100px 0; 
            border-radius: 0 0 50px 50px;
            box-shadow: 0 20px 40px rgba(99, 102, 241, 0.2);
        }
        .navbar { background: rgba(255, 255, 255, 0.8) !important; backdrop-filter: blur(10px); }
        .card { 
            border: none; 
            border-radius: 20px; 
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .card:hover { box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        .stats-card { 
            border-radius: 24px; 
            padding: 24px;
            background: white;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .btn-primary-custom { 
            background: var(--primary-gradient); 
            border: none; 
            color: white; 
            font-weight: 600;
            padding: 12px 24px;
            border-radius: 12px;
        }
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: white;
            padding: 2rem;
            border-right: 1px solid #e2e8f0;
            z-index: 1000;
        }
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 2rem;
        }
        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 12px;
            color: #64748b;
            text-decoration: none;
            transition: 0.2s;
            margin-bottom: 8px;
        }
        .nav-link-custom:hover, .nav-link-custom.active {
            background: #f1f5f9;
            color: #6366f1;
        }
        .nav-link-custom i { font-size: 20px; }
        .badge-status { padding: 6px 12px; border-radius: 8px; font-weight: 600; font-size: 12px; }
        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .form-control:focus {
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            border-color: #6366f1;
        }
        @media (max-width: 991px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body class="bg-light">

<?php if($role && $page != 'home'): ?>
<!-- Sidebar for Dashboards -->
<div class="sidebar d-none d-lg-block">
    <div class="mb-5">
        <a class="navbar-brand fw-bold fs-4 text-primary" href="?page=home">
            <i class="bi bi-paw-fill"></i> StrayZero
        </a>
    </div>
    
    <nav>
        <a href="?page=dashboard" class="nav-link-custom <?php echo $page == 'dashboard' ? 'active' : ''; ?>">
            <i class="bi bi-grid-fill"></i> Dashboard
        </a>
        <a href="?page=dogs" class="nav-link-custom <?php echo $page == 'dogs' ? 'active' : ''; ?>">
            <i class="bi bi-collection-fill"></i> Dog Catalog
        </a>
        <?php if($role == 'user'): ?>
        <a href="?page=report" class="nav-link-custom <?php echo $page == 'report' ? 'active' : ''; ?>">
            <i class="bi bi-megaphone-fill"></i> Report Stray
        </a>
        <?php endif; ?>
        <a href="?page=profile" class="nav-link-custom <?php echo $page == 'profile' ? 'active' : ''; ?>">
            <i class="bi bi-person-fill-gear"></i> Profile
        </a>
        <hr class="my-4 text-muted">
        <a href="?logout=1" class="nav-link-custom text-danger">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </nav>
</div>

<!-- Mobile Nav for Dashboards -->
<nav class="navbar navbar-expand-lg navbar-light bg-white d-lg-none shadow-sm sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold text-primary" href="?page=home"><i class="bi bi-paw-fill"></i> StrayZero</a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#mobileDashboardNav">
            <i class="bi bi-list fs-1"></i>
        </button>
        <div class="collapse navbar-collapse" id="mobileDashboardNav">
            <div class="navbar-nav pt-3">
                <a class="nav-link <?php echo $page == 'dashboard' ? 'active fw-bold text-primary' : ''; ?>" href="?page=dashboard">Dashboard</a>
                <a class="nav-link <?php echo $page == 'dogs' ? 'active fw-bold text-primary' : ''; ?>" href="?page=dogs">Dog Catalog</a>
                <?php if($role == 'user'): ?>
                <a class="nav-link <?php echo $page == 'report' ? 'active fw-bold text-primary' : ''; ?>" href="?page=report">Report Stray</a>
                <?php endif; ?>
                <a class="nav-link <?php echo $page == 'profile' ? 'active fw-bold text-primary' : ''; ?>" href="?page=profile">Profile</a>
                <a class="nav-link text-danger" href="?logout=1">Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="main-content">
<?php else: ?>
<!-- Standard Navbar for Public Pages -->
<nav class="navbar navbar-expand-lg navbar-light sticky-top shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold fs-4 text-primary" href="?page=home">
            <i class="bi bi-paw-fill"></i> StrayZero
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <div class="navbar-nav ms-auto align-items-center">
                <a class="nav-link px-3" href="?page=dogs">Browse Dogs</a>
                <a class="nav-link px-3" href="?page=report">Report Stray</a>
                <?php if($role): ?>
                    <a class="btn btn-primary-custom ms-lg-3" href="?page=dashboard">Dashboard</a>
                <?php else: ?>
                    <a class="nav-link px-3" href="?page=login">Admin Login</a>
                    <a class="btn btn-primary-custom ms-lg-3" href="?page=register">Join Community</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
<div class="container mt-4">
<?php endif; ?>

<?php if($page == 'home'): ?>
<div class="row align-items-center mb-5 py-5">
    <div class="col-lg-6 mb-5 mb-lg-0">
        <span class="badge bg-primary-subtle text-primary px-3 py-2 rounded-pill mb-3 fw-bold">Helping Stray Dogs Every Day</span>
        <h1 class="display-3 fw-bold mb-4" style="line-height: 1.1;">Ensuring Every Stray Finds a <span class="text-primary">Safe Home.</span></h1>
        <p class="lead text-muted mb-5 fs-5">StrayZero is a comprehensive LGU-led platform to monitor, rescue, and find homes for stray animals in our community.</p>
        <div class="d-flex flex-column flex-sm-row gap-3">
            <a href="?page=report" class="btn btn-primary-custom btn-lg px-4">
                <i class="bi bi-megaphone me-2"></i> Report a Stray
            </a>
            <a href="?page=dogs" class="btn btn-outline-secondary btn-lg px-4 rounded-4">
                <i class="bi bi-search me-2"></i> Find a Companion
            </a>
        </div>
        <div class="mt-5 d-flex align-items-center gap-4">
            <div>
                <h4 class="fw-bold mb-0"><?php echo $pdo->query("SELECT COUNT(*) FROM dogs")->fetchColumn(); ?>+</h4>
                <small class="text-muted">Dogs Rescued</small>
            </div>
            <div class="vr"></div>
            <div>
                <h4 class="fw-bold mb-0"><?php echo $pdo->query("SELECT COUNT(*) FROM dogs WHERE status='adopted'")->fetchColumn(); ?>+</h4>
                <small class="text-muted">Successful Adoptions</small>
            </div>
        </div>
    </div>
    <div class="col-lg-6 text-center">
        <div class="position-relative">
            <div class="position-absolute top-50 start-50 translate-middle w-100 h-100 bg-primary opacity-10 rounded-circle" style="transform: scale(1.2) translate(-40%, -40%) !important;"></div>
            <img src="https://images.unsplash.com/photo-1548199973-03cce0bbc87b?q=80&w=2069&auto=format&fit=crop" class="img-fluid rounded-5 shadow-2xl position-relative z-1" alt="Happy Dogs">
        </div>
    </div>
</div>

<div class="row g-4 py-5">
    <div class="col-md-4">
        <div class="card p-4 h-100 border-0 shadow-sm text-center">
            <div class="stats-icon bg-primary-subtle text-primary mx-auto mb-4">
                <i class="bi bi-shield-check"></i>
            </div>
            <h5 class="fw-bold">Report & Monitor</h5>
            <p class="text-muted">Citizens can quickly report sightings of stray dogs with photos and location details.</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-4 h-100 border-0 shadow-sm text-center">
            <div class="stats-icon bg-success-subtle text-success mx-auto mb-4">
                <i class="bi bi-heart-pulse"></i>
            </div>
            <h5 class="fw-bold">Shelter Care</h5>
            <p class="text-muted">LGU officers manage captured dogs, ensuring they receive proper care and identification.</p>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-4 h-100 border-0 shadow-sm text-center">
            <div class="stats-icon bg-warning-subtle text-warning mx-auto mb-4">
                <i class="bi bi-house-heart"></i>
            </div>
            <h5 class="fw-bold">Easy Adoption</h5>
            <p class="text-muted">Find your new best friend through our verified adoption process and community support.</p>
        </div>
    </div>
</div>

<div class="row mt-5 py-5 bg-white rounded-5 shadow-sm mx-0">
    <div class="col-12 text-center mb-5">
        <h2 class="fw-bold">How It Works</h2>
        <p class="text-muted">Simple steps to find a pet and help our community</p>
    </div>
    <div class="col-md-6 px-5 border-end">
        <h4 class="fw-bold mb-4 text-primary"><i class="bi bi-megaphone-fill me-2"></i>Reporting a Stray</h4>
        <ul class="list-unstyled text-start d-inline-block mx-auto">
            <li class="mb-3 d-flex gap-3">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px; font-size: 12px;">1</div>
                <span>Click <strong>"Report Stray"</strong> in the menu.</span>
            </li>
            <li class="mb-3 d-flex gap-3">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px; font-size: 12px;">2</div>
                <span>Fill in the dog's details and upload a photo.</span>
            </li>
            <li class="mb-3 d-flex gap-3">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px; font-size: 12px;">3</div>
                <span>Our LGU team will be notified instantly!</span>
            </li>
        </ul>
    </div>
    <div class="col-md-6 px-5 text-center">
        <h4 class="fw-bold mb-4 text-success"><i class="bi bi-heart-fill me-2"></i>Adopting a Dog</h4>
        <ul class="list-unstyled text-start d-inline-block mx-auto">
            <li class="mb-3 d-flex gap-3">
                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px; font-size: 12px;">1</div>
                <span>Visit the <strong>"Browse Dogs"</strong> catalog.</span>
            </li>
            <li class="mb-3 d-flex gap-3">
                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px; font-size: 12px;">2</div>
                <span>Look for dogs with the green <strong>"Available"</strong> badge.</span>
            </li>
            <li class="mb-3 d-flex gap-3">
                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 24px; height: 24px; font-size: 12px;">3</div>
                <span>Click <strong>"Adopt Me"</strong> to start the process!</span>
            </li>
        </ul>
    </div>
</div>

<div class="row mt-4 py-5 bg-info-subtle rounded-5 shadow-sm mx-0">
    <div class="col-12 text-center mb-4">
        <h3 class="fw-bold text-info"><i class="bi bi-person-check-fill me-2"></i>Lost your dog?</h3>
        <p class="text-dark">If you identify your pet in our system, please follow the physical claiming process:</p>
    </div>
    <div class="col-md-8 mx-auto text-center">
        <div class="d-flex flex-column flex-md-row justify-content-center gap-4">
            <div class="p-3">
                <i class="bi bi-building fs-2 d-block mb-2"></i>
                <h6 class="fw-bold">Visit the Shelter</h6>
                <small>Go directly to the LGU Animal Shelter during office hours.</small>
            </div>
            <div class="p-3">
                <i class="bi bi-file-earmark-person fs-2 d-block mb-2"></i>
                <h6 class="fw-bold">Bring Proof</h6>
                <small>Present photos, vaccination records, or vet documents.</small>
            </div>
            <div class="p-3">
                <i class="bi bi-check2-circle fs-2 d-block mb-2"></i>
                <h6 class="fw-bold">Verify & Release</h6>
                <small>Our team will verify ownership and release your pet.</small>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($page == 'login'): ?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-lg">
            <div class="card-body p-5">
                <h3 class="text-center mb-4"><i class="bi bi-shield-lock text-primary"></i> Admin Login</h3>
                <?php if(isset($_SESSION['success_msg'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-4">
                        <input type="text" name="username" class="form-control form-control-lg" placeholder="Username" value="admin" required>
                    </div>
                    <div class="mb-4">
                        <input type="password" name="password" class="form-control form-control-lg" placeholder="Password" value="password" required>
                    </div>
                    <button class="btn btn-primary w-100 py-3 fs-5">Login to Dashboard</button>
                </form>
                <div class="text-center mt-3">
                    <small class="text-muted">Demo: admin / password</small>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($page == 'register'): ?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-lg">
            <div class="card-body p-5">
                <h3 class="text-center mb-4"><i class="bi bi-person-plus text-success"></i> Citizen Register</h3>
                <?php if(isset($success_msg)): ?>
                    <div class="alert alert-success"><?php echo $success_msg; ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="register">
                    <div class="mb-3"><input type="text" name="username" class="form-control" placeholder="Username" required></div>
                    <div class="mb-3"><input type="email" name="email" class="form-control" placeholder="Email" required></div>
                    <div class="mb-4"><input type="password" name="password" class="form-control" placeholder="Password" required></div>
                    <button class="btn btn-success w-100 py-3">Register Now</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($page == 'profile' && $role): ?>
<?php
$user_info = $pdo->prepare("SELECT * FROM users WHERE id=?");
$user_info->execute([$_SESSION['user_id']]);
$u = $user_info->fetch();
?>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card shadow">
            <div class="card-header bg-dark text-white"><h5><i class="bi bi-person-gear"></i> My Profile</h5></div>
            <div class="card-body p-4">
                <?php if(isset($_SESSION['success_msg'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="mb-3">
                        <label>Username</label>
                        <input type="text" class="form-control" value="<?php echo $u['username']; ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label>Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?php echo $u['email']; ?>" required>
                    </div>
                    <div class="mb-4">
                        <label>New Password (Leave blank to keep current)</label>
                        <input type="password" name="password" class="form-control" placeholder="********">
                    </div>
                    <button class="btn btn-primary w-100">Update Profile</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($page == 'dashboard' && $role == 'user'): ?>
<?php
// Mark notifications as read
$stmt = $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?");
$stmt->execute([$_SESSION['user_id']]);
?>
<h2 class="mb-4"><i class="bi bi-person-circle"></i> Citizen Dashboard</h2>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-header bg-primary text-white"><h5><i class="bi bi-bell"></i> Notifications</h5></div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php
                    $notifs = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 5");
                    $notifs->execute([$_SESSION['user_id']]);
                    $notif_list = $notifs->fetchAll();
                    foreach($notif_list as $n): ?>
                        <div class="list-group-item <?php echo $n['is_read'] ? '' : 'bg-light'; ?>">
                            <small class="text-muted d-block"><?php echo date('M j, g:i a', strtotime($n['created_at'])); ?></small>
                            <p class="mb-0 small"><?php echo $n['message']; ?></p>
                        </div>
                    <?php endforeach; if(empty($notif_list)) echo "<div class='p-3 text-center text-muted'>No notifications</div>"; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-warning text-dark"><h5><i class="bi bi-heart"></i> My Adoption Requests</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Dog ID</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                            <?php
                            $reqs = $pdo->prepare("
                                SELECT d.collar_id, a.status, a.created_at 
                                FROM adoption_requests a JOIN dogs d ON a.dog_id=d.id WHERE a.user_id=?
                                ORDER BY created_at DESC");
                            $reqs->execute([$_SESSION['user_id']]);
                            $all_reqs = $reqs->fetchAll();
                            foreach($all_reqs as $r): ?>
                                <tr>
                                    <td><strong><?php echo $r['collar_id']; ?></strong></td>
                                    <td><span class="badge bg-<?php echo $r['status']=='approved' ? 'success' : ($r['status']=='pending' ? 'warning' : 'danger'); ?>"><?php echo ucfirst($r['status']); ?></span></td>
                                    <td><?php echo date('M j', strtotime($r['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; if(empty($all_reqs)) echo "<tr><td colspan='3' class='text-center p-3'>No requests yet</td></tr>"; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($page == 'dashboard' && $role == 'admin'): ?>
<h2 class="mb-4"><i class="bi bi-speedometer2 text-primary"></i> Admin Dashboard</h2>

<div class="row g-4 mb-5">
    <?php 
    $stats = [
        ['label' => 'In Shelter', 'val' => $pdo->query("SELECT COUNT(*) FROM dogs WHERE status IN ('captured','in_shelter')")->fetchColumn(), 'icon' => 'bi-building', 'color' => 'danger'],
        ['label' => 'Available', 'val' => $pdo->query("SELECT COUNT(*) FROM dogs WHERE status='available'")->fetchColumn(), 'icon' => 'bi-heart', 'color' => 'success'],
        ['label' => 'Adoptions', 'val' => $pdo->query("SELECT COUNT(*) FROM adoption_requests WHERE status='pending'")->fetchColumn(), 'icon' => 'bi-house-check', 'color' => 'warning'],
    ];
    foreach($stats as $s): ?>
    <div class="col">
        <div class="stats-card">
            <div class="stats-icon bg-<?php echo $s['color']; ?>-subtle text-<?php echo $s['color']; ?>">
                <i class="bi <?php echo $s['icon']; ?>"></i>
            </div>
            <div>
                <h3 class="fw-bold mb-0"><?php echo $s['val']; ?></h3>
                <small class="text-muted fw-semibold"><?php echo $s['label']; ?></small>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-12">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-heart-fill me-2"></i>Pending Adoption Requests</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="text-muted small uppercase">
                                <th class="ps-4">Dog</th>
                                <th>Citizen</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $adoptions = $pdo->query("SELECT a.*, d.collar_id, u.username FROM adoption_requests a JOIN dogs d ON a.dog_id=d.id JOIN users u ON a.user_id=u.id WHERE a.status='pending'")->fetchAll();
                            foreach($adoptions as $a): ?>
                            <tr>
                                <td class="ps-4">
                                    <span class="fw-bold text-dark"><?php echo $a['collar_id']; ?></span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-light rounded-circle p-2 me-2"><i class="bi bi-person text-muted"></i></div>
                                        <span class="small"><?php echo $a['username']; ?></span>
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-group">
                                        <a href="?action=handle_adoption&status=approved&id=<?php echo $a['id']; ?>" class="btn btn-sm btn-success rounded-start-pill"><i class="bi bi-check-lg"></i></a>
                                        <a href="?action=handle_adoption&status=rejected&id=<?php echo $a['id']; ?>" class="btn btn-sm btn-danger rounded-end-pill"><i class="bi bi-x-lg"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; if(empty($adoptions)) echo "<tr><td colspan='3' class='text-center py-4 text-muted'>No pending requests</td></tr>"; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h5><i class="bi bi-plus-circle"></i> Register New Dog</h5>
            </div>
            <div class="card-body">
                <?php if(isset($_SESSION['success_msg'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?></div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_dog">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <input type="text" name="breed" class="form-control" placeholder="Breed" required>
                        </div>
                        <div class="col-md-6">
                            <input type="text" name="color" class="form-control" placeholder="Color" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Size (cm)</label>
                            <input type="number" name="size" class="form-control" placeholder="e.g. 45" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Gender</label>
                            <select name="gender" class="form-select" required>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="unknown">Unknown</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Capture Date</label>
                            <input type="date" name="capture_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <input type="text" name="location" class="form-control" placeholder="Capture Location" required>
                        </div>
                        <div class="col-md-6">
                            <input type="text" name="vaccination_status" class="form-control" placeholder="Vaccination Status">
                        </div>
                        <div class="col-12">
                            <textarea name="health_condition" class="form-control" rows="2" placeholder="Health Condition..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small text-muted">Dog Photo (Optional)</label>
                            <input type="file" name="photo" class="form-control" accept="image/*">
                        </div>
                        <div class="col-12">
                            <button class="btn btn-success w-100 py-2">Add Dog to System</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-warning text-dark">
                <h5><i class="bi bi-exclamation-circle"></i> Pending Stray Reports</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr><th>Location</th><th>Color/Size</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $reports = $pdo->query("SELECT * FROM stray_reports WHERE status='pending' ORDER BY id DESC")->fetchAll();
                            foreach($reports as $report): ?>
                            <tr>
                                <td>
                                    <strong><?php echo $report['location'] ?: $report['title']; ?></strong>
                                    <br><small class="text-muted"><?php echo substr($report['description'], 0, 50); ?>...</small>
                                </td>
                                <td><?php echo $report['dog_color']; ?> / <?php echo ucfirst($report['dog_size']); ?></td>
                                <td>
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reportPreview<?php echo $report['id']; ?>">
                                            Preview
                                        </button>
                                        <a href="?action=process_report&id=<?php echo $report['id']; ?>" class="btn btn-sm btn-outline-success">Done</a>
                                    </div>

                                    <!-- Report Preview Modal -->
                                    <div class="modal fade" id="reportPreview<?php echo $report['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg rounded-4">
                                                <div class="modal-header border-0 pb-0">
                                                    <h5 class="fw-bold mb-0">Report at: <?php echo $report['location'] ?: $report['title']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body p-4">
                                                    <?php if($report['photo_url']): ?>
                                                        <img src="<?php echo $report['photo_url']; ?>" class="img-fluid rounded-4 mb-3 w-100 shadow-sm" alt="Stray Dog">
                                                    <?php else: ?>
                                                        <div class="bg-light rounded-4 mb-3 d-flex align-items-center justify-content-center py-5">
                                                            <i class="bi bi-camera-fill display-4 text-muted opacity-25"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="row g-2 mb-3">
                                                        <div class="col-6">
                                                            <div class="bg-light p-2 rounded-3 text-center">
                                                                <small class="text-muted d-block small uppercase">Color</small>
                                                                <span class="fw-bold"><?php echo $report['dog_color']; ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="col-6">
                                                            <div class="bg-light p-2 rounded-3 text-center">
                                                                <small class="text-muted d-block small uppercase">Size</small>
                                                                <span class="fw-bold"><?php echo ucfirst($report['dog_size']); ?></span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <label class="form-label fw-bold small">Description</label>
                                                    <p class="text-muted small mb-0"><?php echo nl2br($report['description']); ?></p>
                                                </div>
                                                <div class="modal-footer border-0 pt-0">
                                                    <button type="button" class="btn btn-light px-4 rounded-pill" data-bs-dismiss="modal">Close</button>
                                                    <a href="?action=process_report&id=<?php echo $report['id']; ?>" class="btn btn-success px-4 rounded-pill">Mark as Processed</a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; if(empty($reports)) echo "<tr><td colspan='3' class='text-center p-3 text-muted'>No pending reports</td></tr>"; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5><i class="bi bi-list-ul"></i> Recent Dogs in System</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark">
                            <tr><th>Collar ID</th><th>Breed</th><th>Size</th><th>Status</th><th>Days in Shelter</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $dogs = $pdo->query("SELECT *, DATEDIFF(CURDATE(), capture_date) as days_in FROM dogs ORDER BY id DESC LIMIT 10")->fetchAll();
                            foreach($dogs as $dog): ?>
                            <tr>
                                <td><strong><?php echo $dog['collar_id']; ?></strong></td>
                                <td><?php echo $dog['breed']; ?></td>
                                <td><?php echo $dog['size']; ?> cm</td>
                                <td><span class="badge bg-<?php echo $dog['status']=='captured' ? 'primary' : 'success'; ?>"><?php echo ucfirst($dog['status']); ?></span></td>
                                <td><?php echo $dog['days_in']; ?> days</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#dogPreview<?php echo $dog['id']; ?>">Preview</button>

                                    <!-- Dog Preview Modal -->
                                    <div class="modal fade" id="dogPreview<?php echo $dog['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg rounded-4 text-dark">
                                                <div class="modal-header border-0 pb-0">
                                                    <h5 class="fw-bold mb-0">Dog Details - <?php echo $dog['collar_id']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body p-4 text-center">
                                                    <?php if($dog['photo_url']): ?>
                                                        <img src="<?php echo $dog['photo_url']; ?>" class="img-fluid rounded-4 mb-3 shadow-sm" style="max-height: 250px; width: 100%; object-fit: cover;" alt="Dog">
                                                    <?php else: ?>
                                                        <div class="bg-light rounded-4 mb-3 d-flex align-items-center justify-content-center py-5">
                                                            <i class="bi bi-paw-fill display-4 text-muted opacity-25"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="row g-3 text-start">
                                                        <div class="col-6">
                                                            <small class="text-muted d-block small uppercase">Breed</small>
                                                            <span class="fw-bold"><?php echo $dog['breed'] ?: 'Mixed Breed'; ?></span>
                                                        </div>
                                                        <div class="col-6">
                                                            <small class="text-muted d-block small uppercase">Status</small>
                                                            <span class="badge bg-<?php echo $dog['status']=='captured' ? 'primary' : 'success'; ?>"><?php echo ucfirst($dog['status']); ?></span>
                                                        </div>
                                                        <div class="col-6">
                                                            <small class="text-muted d-block small uppercase">Color</small>
                                                            <span class="fw-bold"><?php echo $dog['color']; ?></span>
                                                        </div>
                                                        <div class="col-6">
                                                            <small class="text-muted d-block small uppercase">Size</small>
                                                            <span class="fw-bold"><?php echo $dog['size']; ?> cm</span>
                                                        </div>
                                                        <div class="col-12">
                                                            <small class="text-muted d-block small uppercase">Location Captured</small>
                                                            <span class="fw-bold small"><?php echo $dog['capture_location']; ?></span>
                                                        </div>
                                                        <div class="col-12">
                                                            <small class="text-muted d-block small uppercase">Health Condition</small>
                                                            <span class="text-muted small"><?php echo $dog['health_condition'] ?: 'Good'; ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-0 pt-0">
                                                    <button type="button" class="btn btn-light px-5 rounded-pill w-100" data-bs-dismiss="modal">Close</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if($page == 'report'): ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-5 overflow-hidden mb-5">
            <div class="card-header bg-primary text-white p-4 border-0">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-white bg-opacity-25 rounded-circle p-3">
                        <i class="bi bi-megaphone-fill fs-3"></i>
                    </div>
                    <div>
                        <h3 class="fw-bold mb-0">Report a Stray Dog</h3>
                        <p class="mb-0 opacity-75">Help us keep our community safe and animals cared for.</p>
                    </div>
                </div>
            </div>
            <div class="card-body p-4 p-md-5">
                <?php if(isset($_SESSION['success_msg'])): ?>
                    <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4 p-3">
                        <i class="bi bi-check-circle-fill me-2"></i> <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="report_stray">
                    
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label fw-bold">Location</label>
                            <input type="text" name="location" class="form-control" placeholder="e.g., Near Central Park, Brgy. San Jose" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Dog Color</label>
                            <input type="text" name="color" class="form-control" placeholder="e.g., Brown and White" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Estimated Size</label>
                            <select name="size" class="form-select" required>
                                <option value="small">Small (Puppy-sized)</option>
                                <option value="medium" selected>Medium (Average dog)</option>
                                <option value="large">Large (Big dog)</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Location & Details</label>
                            <textarea name="description" class="form-control" rows="4" placeholder="Please provide specific location details and any observations about the dog's condition..." required></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Upload Photo (Optional)</label>
                            <div class="upload-zone p-4 bg-light border-2 border-dashed rounded-4 text-center position-relative">
                                <input type="file" name="photo" class="form-control position-absolute top-0 start-0 w-100 h-100 opacity-0 cursor-pointer" accept="image/*" onchange="previewImage(this)">
                                <div id="upload-preview">
                                    <i class="bi bi-camera fs-1 text-muted d-block mb-2"></i>
                                    <span class="text-muted">Click to upload or drag and drop</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 mt-5">
                            <button type="submit" class="btn btn-primary-custom w-100 py-3 fs-5 shadow-sm">
                                Submit Report <i class="bi bi-send-fill ms-2"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="alert bg-info-subtle border-0 rounded-4 p-4">
            <div class="d-flex gap-3">
                <i class="bi bi-info-circle-fill text-info fs-4"></i>
                <div>
                    <h6 class="fw-bold text-info">Important Note</h6>
                    <p class="text-dark small mb-0">Your report will be sent directly to our LGU Animal Management team. We may contact you if we need more information. Thank you for being a responsible citizen!</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function previewImage(input) {
    const preview = document.getElementById('upload-preview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `<img src="${e.target.result}" class="img-fluid rounded-3 shadow-sm" style="max-height: 200px;">`;
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
<?php endif; ?>

<?php if($page == 'dogs'): ?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-4 mb-5">
    <div>
        <h2 class="fw-bold mb-1">Dog Catalog</h2>
        <p class="text-muted mb-0">Browse and find your future best friend</p>
    </div>
    <div class="card border-0 shadow-sm p-2">
        <form class="d-flex gap-2" method="GET">
            <input type="hidden" name="page" value="dogs">
            <div class="input-group">
                <span class="input-group-text bg-transparent border-end-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="ID, Breed, Color..." value="<?php echo $_GET['search'] ?? ''; ?>" style="min-width: 200px;">
            </div>
            <select name="status" class="form-select" onchange="this.form.submit()" style="min-width: 150px;">
                <option value="">All Status</option>
                <option value="captured" <?php echo ($_GET['status'] ?? '') == 'captured' ? 'selected' : ''; ?>>Captured</option>
                <option value="in_shelter" <?php echo ($_GET['status'] ?? '') == 'in_shelter' ? 'selected' : ''; ?>>In Shelter</option>
                <option value="available" <?php echo ($_GET['status'] ?? '') == 'available' ? 'selected' : ''; ?>>Available</option>
            </select>
            <button class="btn btn-primary-custom px-4">Filter</button>
        </form>
    </div>
</div>

<?php if(isset($_SESSION['success_msg'])): ?>
    <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4">
        <i class="bi bi-check-circle-fill me-2"></i> <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
    </div>
<?php endif; ?>
<?php if(isset($_SESSION['error_msg'])): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4">
        <i class="bi bi-exclamation-circle-fill me-2"></i> <?php echo $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <?php
    $query = "SELECT * FROM dogs WHERE status NOT IN ('claimed', 'adopted')";
    $params = [];
    if (!empty($_GET['search'])) {
        $query .= " AND (collar_id LIKE ? OR breed LIKE ? OR color LIKE ?)";
        $params = array_fill(0, 3, "%".$_GET['search']."%");
    }
    if (!empty($_GET['status'])) {
        $query .= " AND status = ?";
        $params[] = $_GET['status'];
    }
    $query .= " ORDER BY id DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $alldogs = $stmt->fetchAll();
    
    foreach($alldogs as $dog):
    $status_cfg = [
        'captured' => ['color' => 'primary', 'label' => 'Captured'],
        'in_shelter' => ['color' => 'info', 'label' => 'In Shelter'],
        'available' => ['color' => 'success', 'label' => 'Available'],
        'claimed' => ['color' => 'secondary', 'label' => 'Claimed'],
        'adopted' => ['color' => 'dark', 'label' => 'Adopted']
    ][$dog['status']];
    ?>
    <div class="col-xl-3 col-lg-4 col-md-6">
        <div class="card h-100 border-0 shadow-sm overflow-hidden">
            <div class="position-relative">
                <?php if($dog['photo_url']): ?>
                    <img src="<?php echo $dog['photo_url']; ?>" class="card-img-top object-fit-cover" style="height: 220px;" alt="Dog">
                <?php else: ?>
                    <div class="bg-light d-flex align-items-center justify-content-center" style="height: 220px;">
                        <i class="bi bi-paw-fill display-1 text-muted opacity-25"></i>
                    </div>
                <?php endif; ?>
                <span class="position-absolute top-0 end-0 m-3 badge-status bg-<?php echo $status_cfg['color']; ?> text-white shadow-sm">
                    <?php echo $status_cfg['label']; ?>
                </span>
            </div>
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h5 class="fw-bold text-dark mb-0"><?php echo $dog['breed'] ?: 'Mixed Breed'; ?></h5>
                    <small class="text-primary fw-bold"><?php echo $dog['collar_id']; ?></small>
                </div>
                <p class="text-muted small mb-3"><i class="bi bi-geo-alt me-1"></i> <?php echo $dog['capture_location']; ?></p>
                
                <div class="d-flex gap-3 mb-4">
                    <div class="text-center bg-light rounded-3 p-2 flex-fill">
                        <small class="text-muted d-block small uppercase" style="font-size: 10px;">Color</small>
                        <span class="fw-bold small"><?php echo $dog['color']; ?></span>
                    </div>
                    <div class="text-center bg-light rounded-3 p-2 flex-fill">
                        <small class="text-muted d-block small uppercase" style="font-size: 10px;">Size</small>
                        <span class="fw-bold small"><?php echo $dog['size']; ?> cm</span>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="d-flex justify-content-between mb-1">
                        <small class="text-muted">Health Status</small>
                        <small class="fw-bold text-success">Good</small>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-success" style="width: 100%"></div>
                    </div>
                </div>
                
                <?php if($role == 'admin' && !in_array($dog['status'], ['claimed', 'adopted'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-dark w-100 dropdown-toggle rounded-pill" data-bs-toggle="dropdown">
                            Manage Status
                        </button>
                        <ul class="dropdown-menu shadow border-0 rounded-4">
                            <?php if($dog['status'] == 'captured'): ?>
                                <li><a class="dropdown-item py-2" href="?action=update_status&status=in_shelter&id=<?php echo $dog['id']; ?>"><i class="bi bi-building me-2"></i> Move to Shelter</a></li>
                            <?php endif; ?>
                            
                            <?php if($dog['status'] == 'in_shelter'): ?>
                                <li><a class="dropdown-item py-2" href="?action=update_status&status=available&id=<?php echo $dog['id']; ?>"><i class="bi bi-heart me-2"></i> Make Available</a></li>
                                <li><a class="dropdown-item py-2" href="?action=update_status&status=claimed&id=<?php echo $dog['id']; ?>"><i class="bi bi-person-check me-2"></i> Mark as Claimed</a></li>
                            <?php endif; ?>

                            <?php if($dog['status'] == 'available'): ?>
                                <li><a class="dropdown-item py-2" href="?action=update_status&status=claimed&id=<?php echo $dog['id']; ?>"><i class="bi bi-person-check me-2"></i> Mark as Claimed</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php elseif($role == 'admin'): ?>
                    <button class="btn btn-light w-100 rounded-pill disabled"><i class="bi bi-check-circle-fill me-1"></i> Completed</button>
                <?php elseif($role == 'user'): ?>
                    <?php if($dog['status'] == 'available'): ?>
                        <?php 
                        $check_req = $pdo->prepare("SELECT COUNT(*) FROM adoption_requests WHERE dog_id = ? AND user_id = ?");
                        $check_req->execute([$dog['id'], $_SESSION['user_id']]);
                        $already_requested = $check_req->fetchColumn() > 0;
                        ?>
                        <?php if($already_requested): ?>
                            <button class="btn btn-secondary w-100 rounded-pill disabled">
                                <i class="bi bi-check2-circle me-1"></i> Request Sent
                            </button>
                        <?php else: ?>
                            <button class="btn btn-primary-custom w-100 rounded-pill" data-bs-toggle="modal" data-bs-target="#adoptModal<?php echo $dog['id']; ?>">
                                Adopt Me <i class="bi bi-heart-fill ms-1"></i>
                            </button>
                        <?php endif; ?>
                    <?php elseif($dog['status'] == 'captured' || $dog['status'] == 'in_shelter'): ?>
                        <div class="alert alert-info py-2 px-3 rounded-pill mb-0 text-center" style="font-size: 11px;">
                            <i class="bi bi-info-circle me-1"></i> Visit shelter to claim
                        </div>
                    <?php else: ?>
                        <button class="btn btn-light w-100 rounded-pill disabled"><?php echo ucfirst($dog['status']); ?></button>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="?page=login" class="btn btn-outline-primary w-100 rounded-pill">Login to Adopt</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Modern Adoption Modal -->
        <div class="modal fade" id="adoptModal<?php echo $dog['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg rounded-5">
                    <form method="POST">
                        <input type="hidden" name="action" value="adoption_request">
                        <input type="hidden" name="dog_id" value="<?php echo $dog['id']; ?>">
                        <div class="modal-header border-0 p-4 pb-0">
                            <h5 class="fw-bold mb-0">Adopt <?php echo $dog['collar_id']; ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div class="bg-primary-subtle p-3 rounded-4 mb-4 text-primary small">
                                <i class="bi bi-info-circle-fill me-2"></i> Your adoption request will be reviewed by the LGU team. We'll notify you once it's approved!
                            </div>
                            <label class="form-label fw-bold small">Why do you want to adopt this dog?</label>
                            <textarea name="reason" class="form-control" rows="4" placeholder="Tell us about your home, experience with pets, etc." required></textarea>
                        </div>
                        <div class="modal-footer border-0 p-4 pt-0">
                            <button type="button" class="btn btn-light px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-primary-custom px-4 rounded-pill shadow-sm">Submit Request</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; if(empty($alldogs)) echo "<div class='col-12 text-center py-5'><div class='stats-icon bg-light text-muted mx-auto mb-3'><i class='bi bi-search'></i></div><p class='text-muted'>No dogs found matching your search.</p></div>"; ?>
</div>
<?php endif; ?>

</div> <!-- Close container or main-content -->
<?php if($role && $page != 'home'): ?></div><?php endif; ?>

<footer class="bg-white py-4 mt-5 border-top">
    <div class="container text-center">
        <p class="text-muted mb-1">&copy; 2026 StrayZero - All Rights Reserved</p>
        <p class="small text-muted mb-0">
            <strong>Developer:</strong> John Lloyd Manulang Cruz | 
            <a href="https://facebook.com/JohnManulangCruz" target="_blank" class="text-decoration-none text-primary ms-1">
                <i class="bi bi-facebook"></i> John Manulang Cruz
            </a> | 
            <a href="https://instagram.com/Tiradores357" target="_blank" class="text-decoration-none text-danger ms-1">
                <i class="bi bi-instagram"></i> Tiradores357
            </a>
        </p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>