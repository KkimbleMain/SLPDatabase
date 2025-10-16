<?php
// templates/dashboard.php
// Ensure summary values are available by querying DB if caller didn't set them
require_once __DIR__ . '/../includes/sqlite.php';
try {
    $pdo = get_db();

    if (!isset($total_students)) {
        $total_students = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE archived = 0')->fetchColumn();
    }

    // compute total documents across canonical tables
    if (!isset($total_documents)) {
        $total_documents = 0;
        $docTables = ['goals','initial_evaluations','session_reports','other_documents','discharge_reports'];
        try {
            foreach ($docTables as $tbl) {
                $pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:tbl");
                $pi->execute([':tbl' => $tbl]);
                if ($pi->fetchColumn()) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM {$tbl} t JOIN students s ON t.student_id = s.id WHERE s.archived = 0");
                    $st->execute();
                    $total_documents += (int)$st->fetchColumn();
                }
            }
        } catch (Throwable $e) {
            // ignore and leave $total_documents as-is
            $total_documents = $total_documents ?? 0;
        }
    }

	// Compute active progress report count (prefer `student_reports`, fall back to legacy `reports`)
	if (!isset($recent_sessions)) {
		$recent_sessions = 0;
		try {
			$pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='student_reports'");
			$pi->execute();
			if ($pi->fetchColumn()) {
				$st = $pdo->prepare('SELECT COUNT(*) FROM student_reports sr JOIN students s ON sr.student_id = s.id WHERE s.archived = 0');
				$st->execute();
				$recent_sessions = (int)$st->fetchColumn();
			} else {
				$pj = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='reports'");
				$pj->execute();
				if ($pj->fetchColumn()) {
					$st2 = $pdo->prepare('SELECT COUNT(*) FROM reports r JOIN students s ON r.student_id = s.id WHERE s.archived = 0');
					$st2->execute();
					$recent_sessions = (int)$st2->fetchColumn();
				}
			}
		} catch (Throwable $e) {
			$recent_sessions = $recent_sessions ?? 0;
		}
	}

    // Ensure $activeReports is always defined to avoid "Undefined variable" warnings
    if (!isset($activeReports)) {
        // prefer a precomputed recent_sessions value when available
        $activeReports = isset($recent_sessions) ? (int)$recent_sessions : 0;
    }

    if (!isset($user_data) && isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['first_name' => 'User'];
    }
} catch (Exception $e) {
    // fall back to zeros on error
    $total_students = $total_students ?? 0;
    $total_goals = $total_goals ?? 0;
    $recent_sessions = $recent_sessions ?? 0;
    $recent_updates = $recent_updates ?? 0;
    $user_data = $user_data ?? ['first_name' => 'User'];
}
?>

<div class="container">
	<div class="dashboard-header">
		<h1>Dashboard</h1>
		<div class="user-welcome">
			Welcome back, <?php echo htmlspecialchars($user_data['first_name']); ?>!
		</div>
	</div>
    
	<div class="dashboard-stats">
		<div class="stats-card">
			<div class="stat-icon">👥</div>
			<div class="stat-info">
				<h3>Total Students</h3>
				<div id="stat-total-students" class="stat-number"><?php echo $total_students; ?></div>
			</div>
		</div>

		<div class="stats-card">
			<div class="stat-icon">📄</div>
			<div class="stat-info">
				<h3>Active Documents</h3>
				<div id="stat-total-documents" class="stat-number"><?php echo $total_documents; ?></div>
			</div>
		</div>
        
		<div class="stats-card">
			<div class="stat-icon">📈</div>
			<div class="stat-info">
				<h3>Active Progress Reports</h3>
				<div id="stat-recent-reports" class="stat-number"><?php echo $activeReports; ?></div>
			</div>
		</div>

	</div>

	<div class="dashboard-content">
		<div class="dashboard-section">
			<div class="section-header">
				<h2>Quick Actions</h2>
			</div>
			<div class="quick-actions">
				<a href="?view=students" class="action-card">
					<div class="action-icon">👥</div>
					<div class="action-info">
						<h3>Manage Students</h3>
						<p>View and manage student profiles</p>
					</div>
				</a>

				<a href="?view=documentation" class="action-card">
					<div class="action-icon">📄</div>
					<div class="action-info">
						<h3>Documentation</h3>
						<p>Create and manage documentation forms</p>
					</div>
				</a>

				<a href="?view=progress" class="action-card">
					<div class="action-icon">📈</div>
					<div class="action-info">
						<h3>Progress Tracker</h3>
						<p>Track student skills and session progress</p>
					</div>
				</a>

				<a href="?view=settings" class="action-card">
					<div class="action-icon">⚙️</div>
					<div class="action-info">
						<h3>Settings</h3>
						<p>Application settings and data export</p>
					</div>
				</a>
			</div>
		</div>
        
		<div class="dashboard-section">
			<div class="section-header">
				<h2>Recent Activity</h2>
			</div>
			<div class="activity-feed">
				<?php
				// Render recent activity. Prefer server-side aggregated $recent_activities (from getRecentActivity)
				try {
					if (isset($recent_activities) && is_array($recent_activities) && count($recent_activities) > 0) {
						foreach ($recent_activities as $act) {
							$icon = htmlspecialchars($act['icon'] ?? '📄');
							$title = htmlspecialchars($act['title'] ?? ($act['type'] ?? 'Activity'));
							$desc = htmlspecialchars($act['description'] ?? '');
							$date = !empty($act['date']) ? date('M j, Y', strtotime($act['date'])) : '';
							$studentName = htmlspecialchars($act['student_name'] ?? '');
							?>
								<div class="activity-item">
									<div class="activity-icon"><?php echo $icon; ?></div>
									<div class="activity-content">
										<div class="activity-title">
											<strong><?php echo $title; ?></strong>
											<span class="activity-date"><?php echo $date; ?></span>
										</div>
										<div class="activity-description">
											<?php echo $desc; ?>
										</div>
									</div>
								</div>
							<?php
						}
					} else {
						// fallback: show progress_reports / student_reports as before
						$items = [];
						$limit = 10;
						$pi = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='progress_reports'"); $pi->execute();
						if ($pi->fetchColumn()) {
							$pr = $pdo->prepare('SELECT pr.*, s.first_name, s.last_name FROM progress_reports pr LEFT JOIN students s ON pr.student_id = s.id ORDER BY pr.created_at DESC LIMIT :lim');
							$pr->bindValue(':lim', $limit, PDO::PARAM_INT); $pr->execute();
							$items = $pr->fetchAll(PDO::FETCH_ASSOC);
						} else {
							$pi2 = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='student_reports'"); $pi2->execute();
							if ($pi2->fetchColumn()) {
								$rq = $pdo->prepare('SELECT sr.*, s.first_name, s.last_name FROM student_reports sr LEFT JOIN students s ON sr.student_id = s.id ORDER BY sr.updated_at DESC LIMIT :lim');
								$rq->bindValue(':lim', $limit, PDO::PARAM_INT); $rq->execute();
								$items = $rq->fetchAll(PDO::FETCH_ASSOC);
							}
						}
						if (empty($items)) { ?>
							<div class="no-activity">
								<p>No recent activity. Start by adding students and creating a progress report.</p>
							</div>
						<?php } else { ?>
							<?php foreach ($items as $it) {
								$studentName = trim(($it['first_name'] ?? '') . ' ' . ($it['last_name'] ?? ''));
								$dateVal = $it['created_at'] ?? ($it['updated_at'] ?? date('c'));
								$displayDate = htmlspecialchars(date('M j, Y', strtotime($dateVal)));
								// Build title label (document type + action)
								$titleLabel = 'progress report created';
								// Determine document title for description
								$docTitle = $it['title'] ?? (!empty($it['path']) ? basename($it['path']) : 'Progress Report');
								$descLabel = $docTitle . ' created for ' . $studentName;
								?>
								<div class="activity-item" data-id="<?php echo htmlspecialchars($it['id'] ?? ''); ?>">
									<div class="activity-icon">📄</div>
									<div class="activity-content">
										<div class="activity-title">
											<strong><?php echo htmlspecialchars($titleLabel); ?></strong>
											<span class="activity-date"><?php echo $displayDate; ?></span>
										</div>
										<div class="activity-description">
											<?php echo htmlspecialchars($descLabel); ?>
										</div>
									</div>
								</div>
							<?php } ?>
						<?php }
					}
				} catch (Throwable $e) {
					// fallback
					?>
					<div class="no-activity">
						<p>No recent activity available.</p>
					</div>
				<?php } ?>
			</div>
		</div>
	</div>
</div>