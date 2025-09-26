<?php
// Load core helpers first so functions like loadJsonData() are available
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/require_login.php';
// Defensive: if helpers still missing, stop with a clear message
if (!function_exists('loadJsonData')) {
    http_response_code(500);
    echo 'Server configuration error: required helper functions not loaded.';
    exit();
}

// If the request is for the root path (no view query) explicitly redirect to the dashboard
// This keeps the URL consistent and ensures opening http://localhost:8000 shows the dashboard
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$hasView = isset($_GET['view']);
if (($path === '/' || $path === '/index.php' || $path === '') && !$hasView) {
    header('Location: /?view=dashboard');
    exit();
}

// Ensure user is logged in and we have a user id
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    header('Location: /login.php');
    exit();
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    // fallback: not authenticated properly
    header('Location: /login.php');
    exit();
}

$view = $_GET['view'] ?? 'dashboard';

// Load user data safely (helpers accept table name or arrays)
require_once __DIR__ . '/includes/sqlite.php';
try {
    $pdo = get_db();
    $users = $pdo->query('SELECT id, username, first_name, last_name FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $progress_updates = $pdo->query('SELECT p.*, s.first_name AS student_first, s.last_name AS student_last FROM progress_updates p LEFT JOIN students s ON s.id = p.student_id ORDER BY p.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $users = [];
    $progress_updates = [];
}

$user_data = findRecord('users', 'id', $user_id);
if (!is_array($user_data)) $user_data = [];

// Load students assigned to this user (ensure array returned)
$user_students = findRecords('students', ['assigned_therapist' => $user_id]);
if (!is_array($user_students)) $user_students = [];

// Get statistics with safe defaults
$total_students = count($user_students);
$goals = findRecords('goals', ['therapist_id' => $user_id]);
if (!is_array($goals)) $goals = [];
$total_goals = count($goals);

// Load comprehensive recent activity
require_once 'includes/activity_tracker.php';
$recent_activities = getRecentActivity($user_id, 10);

// Calculate recent sessions count for stats
$recent_sessions = 0;
foreach ($progress_updates as $update) {
    if (empty($update['student_id'])) continue;
    if (!in_array($update['student_id'], array_column($user_students, 'id'))) continue;
    if (!empty($update['date_recorded']) && strtotime($update['date_recorded']) >= strtotime('-7 days')) {
        $recent_sessions++;
    }
}

// Keep backward compatibility - dashboard still expects $recent_updates
$recent_updates = $recent_activities;

// Make sure variables used by templates are defined
$selected_student = $selected_student ?? null;
$student_progress = $student_progress ?? [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SLP Database - <?php echo htmlspecialchars((string) ucfirst($view)); ?></title>
    <link rel="stylesheet" href="/assets/css/common.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <header class="main-header">
        <div class="header-content">
            <h1>SLP Database</h1>
            <nav class="main-nav">
                <a href="?view=dashboard" class="<?php echo $view == 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
                <a href="?view=students" class="<?php echo $view == 'students' ? 'active' : ''; ?>">Students</a>
                <a href="?view=documentation" class="<?php echo $view == 'documentation' ? 'active' : ''; ?>">Documentation</a>
                <a href="?view=settings" class="<?php echo $view == 'settings' ? 'active' : ''; ?>">Settings</a>
            </nav>
            <div class="user-menu">
                <div class="muted">Welcome, <?php echo htmlspecialchars((string) ($user_data['first_name'] ?? '')); ?></div>
                <form method="POST" action="/includes/logout.php" style="margin:0;">
                    <button type="submit" class="btn btn-outline logout-btn">Logout</button>
                </form>
            </div>
        </div>
    </header>

    <main class="main-content">
        <?php
        // Include the requested view from templates/ using a whitelist to avoid path traversal.
        $allowed = [
            'dashboard' => __DIR__ . '/templates/dashboard.php',
            'students' => __DIR__ . '/templates/students.php',
            'documentation' => __DIR__ . '/templates/documentation.php',
            'settings' => __DIR__ . '/templates/settings.php',
        ];

        $sel = $view;
        if (!isset($allowed[$sel])) $sel = 'dashboard';
        // Set variables expected by templates (already computed above)
        include $allowed[$sel];
        ?>
    </main>

    <!-- Chart Scripts -->
    <script>
        // Chart data for progress view - guard with isset and is_array to avoid notices
        <?php if ($view == 'progress' && isset($selected_student) && is_array($student_progress) && count($student_progress) > 0): ?>
            const progressData = <?php echo json_encode(array_reverse($student_progress)); ?>;
            const ctx = document.getElementById('progressChart');
            if (ctx && progressData.length > 0) {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: progressData.map(p => p.date_recorded),
                        datasets: [{
                            label: 'Progress Score (%)',
                            data: progressData.map(p => p.score),
                            borderColor: '#4CAF50',
                            backgroundColor: 'rgba(76, 175, 80, 0.1)',
                            tension: 0.1,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                title: {
                                    display: true,
                                    text: 'Score (%)'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Date'
                                }
                            }
                        }
                    }
                });
            }
        <?php endif; ?>
    </script>
    <?php include_once __DIR__ . '/templates/modal_templates.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script type="module" src="/assets/js/main.js"></script>
    <script type="module">
        // Dynamically import a page-specific module if it exists under /assets/js/pages/{view}.js
        (async () => {
            try {
                const view = '<?php echo addslashes($view); ?>';
                const path = `/assets/js/pages/${view}.js`;
                // attempt to dynamically import; if the file is missing, catch and ignore
                await import(path);
                console.log('Loaded page module:', path);
            } catch (e) {
                // ignore missing module 404s and other import failures; keep console for debugging
                console.debug('Page module not loaded (optional):', e && e.message ? e.message : e);
            }
        })();
    </script>
    <?php include_once __DIR__ . '/templates/footer.php'; ?>
</body>
</html>
