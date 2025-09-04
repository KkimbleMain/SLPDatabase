<?php
// templates/dashboard.php
// Assumes $user_data, $total_students, $total_goals, $recent_sessions, $recent_updates are defined by the caller
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
				<div class="stat-number"><?php echo $total_students; ?></div>
			</div>
		</div>
        
		<div class="stats-card">
			<div class="stat-icon">🎯</div>
			<div class="stat-info">
				<h3>Active Goals</h3>
				<div class="stat-number"><?php echo $total_goals; ?></div>
			</div>
		</div>
        
		<div class="stats-card">
			<div class="stat-icon">📈</div>
			<div class="stat-info">
				<h3>Recent Reports</h3>
				<div class="stat-number"><?php echo $recent_sessions; ?></div>
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
				if (empty($recent_updates)) { ?>
					<div class="no-activity">
						<p>No recent activity. Start by adding students and tracking their progress!</p>
					</div>
				<?php } else { ?>
					<?php foreach ($recent_updates as $activity) { ?>
						<div class="activity-item">
							<div class="activity-icon"><?php echo $activity['icon']; ?></div>
							<div class="activity-content">
								<div class="activity-title">
									<strong><?php echo htmlspecialchars($activity['title']); ?></strong>
									<span class="activity-date"><?php echo date('M j, Y', $activity['timestamp']); ?></span>
								</div>
								<div class="activity-description">
									<?php echo htmlspecialchars($activity['description']); ?>
								</div>
							</div>
						</div>
					<?php } ?>
				<?php } ?>
			</div>
		</div>
	</div>
</div>