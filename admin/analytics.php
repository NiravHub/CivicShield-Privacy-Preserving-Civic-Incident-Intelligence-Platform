<?php
declare(strict_types=1);

$pageTitle = 'Civic Intelligence & Analytics';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'auth_guard.php';

// Enforce strict administrator authorization
requireAdmin();

$pdo = Database::getConnection();

// Parse and normalize incoming filters
$rawFilters = [
    'date_from'     => $_GET['date_from'] ?? null,
    'date_to'       => $_GET['date_to'] ?? null,
    'category_id'   => $_GET['category_id'] ?? null,
    'status'        => $_GET['status'] ?? null,
    'min_priority'  => $_GET['min_priority'] ?? null,
    'priority_tier' => $_GET['priority_tier'] ?? null,
    'search'        => $_GET['search'] ?? null
];
$filters = AnalyticsManager::normalizeFilters($rawFilters);

// Retrieve active categories for filter selection
$categoriesStmt = $pdo->query('SELECT id, name, icon FROM incident_categories WHERE is_active = 1 ORDER BY name ASC');
$categories = $categoriesStmt->fetchAll();

// Execute analytical aggregations
$metrics = AnalyticsManager::getSummaryMetrics($filters, $pdo);
$statusData = AnalyticsManager::getStatusBreakdown($filters, $pdo);
$categoryData = AnalyticsManager::getCategoryBreakdown($filters, $pdo);
$priorityData = AnalyticsManager::getPriorityDistribution($filters, $pdo);
$trendsData = AnalyticsManager::getVolumeTrends($filters, $pdo);
$mapIncidents = AnalyticsManager::getMapIncidents($filters, 200, $pdo);

// Helper to preserve active query string parameters
function buildAnalyticsQuery(array $overrides = []): string {
    $params = array_filter($_GET, fn($v) => $v !== null && $v !== '');
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return http_build_query($params);
}
$loadLeaflet = true;
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<!-- Chart.js 4.4.2 UMD (graceful fallback when CDN unreachable) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"
        onerror="window.__civicChartUnavailable = true;"></script>

<div class="container-fluid px-lg-5 py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/" class="text-secondary text-decoration-none small">
                    <i class="bi bi-arrow-left me-1"></i> Admin Console
                </a>
                <span class="text-muted">/</span>
                <h3 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-graph-up-arrow text-primary me-2"></i>Civic Intelligence &amp; Analytics
                </h3>
            </div>
            <p class="text-secondary mb-0">
                Municipal performance telemetry, resolution velocity, category clustering, and privacy-preserving spatial patterns.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-shield-exclamation me-1"></i> Incident Ledger
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print Intelligence Report
            </button>
        </div>
    </div>

    <!-- Filter & Date Range Toolbar -->
    <div class="card cs-card shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="GET" action="<?= htmlspecialchars(BASE_URL) ?>/admin/analytics.php" class="row g-3 align-items-end">
                <!-- Date From -->
                <div class="col-sm-6 col-md-2">
                    <label for="dateFrom" class="form-label small fw-semibold text-muted mb-1">Date From</label>
                    <input type="date" class="form-control form-control-sm" id="dateFrom" name="date_from" 
                           value="<?= htmlspecialchars((string)($filters['date_from'] ?? '')) ?>">
                </div>

                <!-- Date To -->
                <div class="col-sm-6 col-md-2">
                    <label for="dateTo" class="form-label small fw-semibold text-muted mb-1">Date To</label>
                    <input type="date" class="form-control form-control-sm" id="dateTo" name="date_to" 
                           value="<?= htmlspecialchars((string)($filters['date_to'] ?? '')) ?>">
                </div>

                <!-- Category -->
                <div class="col-sm-6 col-md-3">
                    <label for="filterCategory" class="form-label small fw-semibold text-muted mb-1">Civic Category</label>
                    <select class="form-select form-select-sm" id="filterCategory" name="category_id">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= $filters['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status -->
                <div class="col-sm-6 col-md-2">
                    <label for="filterStatus" class="form-label small fw-semibold text-muted mb-1">Status</label>
                    <select class="form-select form-select-sm" id="filterStatus" name="status">
                        <option value="">All Statuses</option>
                        <?php foreach (AnalyticsManager::STATUS_METADATA as $stKey => $stMeta): ?>
                            <option value="<?= $stKey ?>" <?= $filters['status'] === $stKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($stMeta['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Priority Tier -->
                <div class="col-sm-6 col-md-2">
                    <label for="filterPriorityTier" class="form-label small fw-semibold text-muted mb-1">Priority Tier</label>
                    <select class="form-select form-select-sm" id="filterPriorityTier" name="priority_tier">
                        <option value="">All Tiers</option>
                        <?php foreach (AnalyticsManager::PRIORITY_TIERS as $ptKey => $ptMeta): ?>
                            <option value="<?= $ptKey ?>" <?= $filters['priority_tier'] === $ptKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ptMeta['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Submit / Reset Actions -->
                <div class="col-sm-6 col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-cs-primary btn-sm flex-grow-1" title="Apply Filters">
                        <i class="bi bi-funnel-fill"></i>
                    </button>
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/analytics.php" class="btn btn-outline-secondary btn-sm" title="Clear Filters">
                        <i class="bi bi-x-circle"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- KPI Metric Summary Cards -->
    <div class="row g-3 mb-4">
        <!-- Card 1: Total Incidents -->
        <div class="col-6 col-lg-3 col-xl">
            <div class="card cs-card p-3 shadow-sm h-100 border-start border-4 border-primary">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small fw-bold text-uppercase">Total Issues</span>
                    <i class="bi bi-shield-exclamation text-primary fs-5"></i>
                </div>
                <div class="fs-2 fw-bold text-dark"><?= number_format($metrics['total_incidents']) ?></div>
                <div class="text-secondary small mt-1">
                    Corroborated by <strong><?= number_format($metrics['total_citizen_reports']) ?></strong> citizen reports
                </div>
            </div>
        </div>

        <!-- Card 2: Open vs In Progress -->
        <div class="col-6 col-lg-3 col-xl">
            <div class="card cs-card p-3 shadow-sm h-100 border-start border-4 border-warning">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small fw-bold text-uppercase">Active Open Queue</span>
                    <i class="bi bi-hourglass-split text-warning fs-5"></i>
                </div>
                <div class="fs-2 fw-bold text-dark"><?= number_format($metrics['open_incidents']) ?></div>
                <div class="text-secondary small mt-1">
                    <span class="badge bg-warning-subtle text-warning border"><?= $metrics['in_progress_incidents'] ?> in field repair</span>
                </div>
            </div>
        </div>

        <!-- Card 3: Completed / Resolution Rate -->
        <div class="col-6 col-lg-3 col-xl">
            <div class="card cs-card p-3 shadow-sm h-100 border-start border-4 border-success">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small fw-bold text-uppercase">Remediated &bull; Closed</span>
                    <i class="bi bi-check2-circle text-success fs-5"></i>
                </div>
                <div class="fs-2 fw-bold text-success"><?= number_format($metrics['completed_incidents']) ?></div>
                <div class="text-secondary small mt-1">
                    <strong><?= $metrics['resolution_rate_percent'] ?>%</strong> resolution clearance rate
                </div>
            </div>
        </div>

        <!-- Card 4: High / Critical Priority -->
        <div class="col-6 col-lg-3 col-xl">
            <div class="card cs-card p-3 shadow-sm h-100 border-start border-4 border-danger">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small fw-bold text-uppercase">High / Critical Urgency</span>
                    <i class="bi bi-fire text-danger fs-5"></i>
                </div>
                <div class="fs-2 fw-bold text-danger"><?= number_format($metrics['high_priority_incidents']) ?></div>
                <div class="text-secondary small mt-1">
                    <?= $metrics['critical_priority_incidents'] ?> critical (Score &ge; 85)
                </div>
            </div>
        </div>

        <!-- Card 5: Average Resolution Time -->
        <div class="col-12 col-lg-3 col-xl">
            <div class="card cs-card p-3 shadow-sm h-100 border-start border-4 border-info">
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="text-muted small fw-bold text-uppercase">Avg Resolution Time</span>
                    <i class="bi bi-stopwatch text-info fs-5"></i>
                </div>
                <div class="fs-2 fw-bold text-dark">
                    <?= $metrics['avg_resolution_hours'] !== null ? $metrics['avg_resolution_hours'] . 'h' : '<span class="text-muted fs-5">N/A</span>' ?>
                </div>
                <div class="text-secondary small mt-1">
                    <?= $metrics['avg_resolution_hours'] !== null ? 'From intake to officer remediation' : 'No resolved records in window' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart Row 1: Volume Trend & Status Distribution -->
    <div class="row g-4 mb-4">
        <!-- Longitudinal Trends -->
        <div class="col-lg-8">
            <div class="card cs-card shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-graph-up me-2 text-primary"></i>Incident Intake &amp; Resolution Velocity
                        </h5>
                        <small class="text-muted">Longitudinal volume over time</small>
                    </div>
                    <span class="badge bg-light text-secondary border">Daily Aggregation</span>
                </div>
                <div class="card-body p-4">
                    <?php if (empty($trendsData['labels'])): ?>
                        <div class="p-5 text-center text-muted">
                            <i class="bi bi-calendar-x fs-1 d-block mb-2 text-secondary"></i>
                            <h6 class="fw-semibold">No timeline data found for the selected filter window.</h6>
                            <p class="small text-secondary mb-0">Try widening your date range or clearing category filters.</p>
                        </div>
                    <?php else: ?>
                        <div style="height: 300px; position: relative;">
                            <canvas id="volumeTrendChart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Status Breakdown -->
        <div class="col-lg-4">
            <div class="card cs-card shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-pie-chart me-2 text-primary"></i>Lifecycle Status Distribution
                        </h5>
                        <small class="text-muted">Current state of civic queue</small>
                    </div>
                </div>
                <div class="card-body p-4">
                    <?php if ($statusData['total'] === 0): ?>
                        <div class="p-5 text-center text-muted">
                            <i class="bi bi-pie-chart fs-1 d-block mb-2 text-secondary"></i>
                            <h6 class="fw-semibold">No status data available.</h6>
                        </div>
                    <?php else: ?>
                        <div style="height: 220px; position: relative;" class="mb-3">
                            <canvas id="statusDoughnutChart"></canvas>
                        </div>
                        <div class="row g-2 small">
                            <?php foreach ($statusData['breakdown'] as $sb): ?>
                                <?php if ($sb['count'] > 0): ?>
                                    <div class="col-6 d-flex align-items-center justify-content-between py-1 border-bottom">
                                        <div class="d-flex align-items-center gap-1 text-truncate">
                                            <span class="d-inline-block rounded-circle" style="width: 10px; height: 10px; background-color: <?= htmlspecialchars($sb['color']) ?>;"></span>
                                            <span class="text-secondary text-truncate"><?= htmlspecialchars($sb['label']) ?></span>
                                        </div>
                                        <strong><?= $sb['count'] ?></strong>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart Row 2: Category Distribution & Priority Stratification -->
    <div class="row g-4 mb-4">
        <!-- Civic Categories Breakdown -->
        <div class="col-lg-7">
            <div class="card cs-card shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-bar-chart-steps me-2 text-primary"></i>Incidents by Municipal Category
                        </h5>
                        <small class="text-muted">Issue frequency and departmental distribution</small>
                    </div>
                </div>
                <div class="card-body p-4">
                    <?php if ($categoryData['total_incidents'] === 0): ?>
                        <div class="p-5 text-center text-muted">
                            <i class="bi bi-folder-x fs-1 d-block mb-2 text-secondary"></i>
                            <h6 class="fw-semibold">No category records match your filter criteria.</h6>
                        </div>
                    <?php else: ?>
                        <div style="height: 280px; position: relative;">
                            <canvas id="categoryBarChart"></canvas>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Priority Tier Stratification -->
        <div class="col-lg-5">
            <div class="card cs-card shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-shield-shaded me-2 text-primary"></i>Priority Score Stratification
                        </h5>
                        <small class="text-muted">Distribution across standard heuristic tiers</small>
                    </div>
                </div>
                <div class="card-body p-4">
                    <?php if ($priorityData['total'] === 0): ?>
                        <div class="p-5 text-center text-muted">
                            <i class="bi bi-bar-chart fs-1 d-block mb-2 text-secondary"></i>
                            <h6 class="fw-semibold">No priority data available.</h6>
                        </div>
                    <?php else: ?>
                        <div style="height: 200px; position: relative;" class="mb-3">
                            <canvas id="priorityBarChart"></canvas>
                        </div>
                        <div class="list-group list-group-flush small">
                            <?php foreach ($priorityData['tiers'] as $pt): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="d-inline-block rounded-circle" style="width: 12px; height: 12px; background-color: <?= htmlspecialchars($pt['color']) ?>;"></span>
                                        <strong><?= htmlspecialchars($pt['label']) ?></strong>
                                    </div>
                                    <div>
                                        <span class="badge bg-light text-dark border me-1"><?= $pt['count'] ?> issues</span>
                                        <span class="text-muted"><?= $pt['percentage'] ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Geospatial Spatial Density Map (Obfuscated Coordinates) -->
    <div class="card cs-card shadow-sm mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-geo-alt-fill text-danger me-2"></i>Municipal Spatial Density &amp; Hazard Clusters
                </h5>
                <small class="text-muted">Incident coordinates are randomly displaced within the configured radius (typically 150m).</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-danger-subtle text-danger border border-danger small">
                    <i class="bi bi-shield-lock-fill me-1"></i> Coordinate Obfuscation Active
                </span>
                <span class="badge bg-light text-secondary border small">
                    Showing <?= count($mapIncidents) ?> Centroids
                </span>
            </div>
        </div>
        <div class="card-body p-0">
            <div id="analyticsClusterMap" style="height: 420px; width: 100%;"></div>
        </div>
        <div class="card-footer bg-light py-2 small text-muted d-flex justify-content-between align-items-center flex-wrap">
            <span>
                <i class="bi bi-info-circle me-1"></i> Circles show the configured coordinate-displacement radius. This is a privacy aid, not a formal differential-privacy guarantee.
            </span>
            <span class="text-secondary">
                Map data &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" class="text-decoration-none">OpenStreetMap</a> contributors
            </span>
        </div>
    </div>

    <!-- Detailed Departmental Telemetry Table -->
    <div class="card cs-card shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="bi bi-table me-2 text-primary"></i>Departmental Performance &amp; SLA Overview
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light text-uppercase">
                        <tr>
                            <th>Category</th>
                            <th>Department Code</th>
                            <th>Total Incidents</th>
                            <th>Citizen Reports</th>
                            <th>Avg Priority</th>
                            <th>Share of Queue</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoryData['categories'] as $catRow): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <i class="bi <?= htmlspecialchars($catRow['icon']) ?> text-primary me-2"></i>
                                        <?= htmlspecialchars($catRow['name']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border font-monospace">
                                        <?= htmlspecialchars($catRow['department_code'] ?? 'SMC') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold"><?= number_format($catRow['incident_count']) ?></span>
                                </td>
                                <td>
                                    <?= number_format($catRow['report_count']) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $catRow['avg_priority'] >= 75 ? 'bg-danger' : ($catRow['avg_priority'] >= 50 ? 'bg-warning text-dark' : 'bg-info') ?>">
                                        <?= $catRow['avg_priority'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2" style="max-width: 140px;">
                                        <div class="progress flex-grow-1" style="height: 6px;">
                                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $catRow['percentage'] ?>%"></div>
                                        </div>
                                        <span><?= $catRow['percentage'] ?>%</span>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incidents.php?category_id=<?= $catRow['category_id'] ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-filter me-1"></i> View Incidents
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Visualizations Initialization Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var chartAvailable = (typeof Chart !== 'undefined') && !window.__civicChartUnavailable;

    function showChartFallback(canvasId, message) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !canvas.parentNode) return;
        var parent = canvas.parentNode;
        parent.innerHTML =
            '<div class="alert alert-secondary mb-0 small d-flex align-items-start gap-2" role="status">' +
                '<i class="bi bi-bar-chart-line flex-shrink-0 mt-1"></i>' +
                '<div><strong>Chart unavailable.</strong> ' + message +
                ' KPI cards and tables above remain authoritative.</div>' +
            '</div>';
    }

    if (!chartAvailable) {
        ['volumeTrendChart', 'statusDoughnutChart', 'categoryBarChart', 'priorityBarChart'].forEach(function(id) {
            showChartFallback(id, 'The Chart.js CDN could not be loaded (offline network or blocked script).');
        });
    }

    // -------------------------------------------------------------------------
    // 1. Chart: Volume Trend Over Time
    // -------------------------------------------------------------------------
    var trendCtx = document.getElementById('volumeTrendChart');
    if (chartAvailable && trendCtx) {
        var trendLabels = <?= json_encode($trendsData['labels']) ?>;
        var createdData = <?= json_encode($trendsData['created_series']) ?>;
        var resolvedData = <?= json_encode($trendsData['resolved_series']) ?>;

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    {
                        label: 'Incidents Reported',
                        data: createdData,
                        borderColor: '#0d6efd',
                        backgroundColor: 'rgba(13, 110, 253, 0.1)',
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#0d6efd'
                    },
                    {
                        label: 'Incidents Remediated',
                        data: resolvedData,
                        borderColor: '#198754',
                        backgroundColor: 'rgba(25, 135, 84, 0.1)',
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#198754'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: { mode: 'index', intersect: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // 2. Chart: Status Doughnut Distribution
    // -------------------------------------------------------------------------
    var statusCtx = document.getElementById('statusDoughnutChart');
    if (chartAvailable && statusCtx) {
        var statusRows = <?= json_encode($statusData['breakdown']) ?>;
        var activeStatusRows = statusRows.filter(function(r) { return r.count > 0; });

        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: activeStatusRows.map(function(r) { return r.label; }),
                datasets: [{
                    data: activeStatusRows.map(function(r) { return r.count; }),
                    backgroundColor: activeStatusRows.map(function(r) { return r.color; }),
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                cutout: '65%'
            }
        });
    }

    // -------------------------------------------------------------------------
    // 3. Chart: Category Bar Chart
    // -------------------------------------------------------------------------
    var catCtx = document.getElementById('categoryBarChart');
    if (chartAvailable && catCtx) {
        var catRows = <?= json_encode($categoryData['categories']) ?>;
        var filteredCats = catRows.filter(function(c) { return c.incident_count > 0; });

        new Chart(catCtx, {
            type: 'bar',
            data: {
                labels: filteredCats.map(function(c) { return c.name; }),
                datasets: [{
                    label: 'Incidents Count',
                    data: filteredCats.map(function(c) { return c.incident_count; }),
                    backgroundColor: 'rgba(13, 110, 253, 0.75)',
                    borderColor: '#0d6efd',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // 4. Chart: Priority Tier Bar Chart
    // -------------------------------------------------------------------------
    var prioCtx = document.getElementById('priorityBarChart');
    if (chartAvailable && prioCtx) {
        var prioTiers = <?= json_encode($priorityData['tiers']) ?>;

        new Chart(prioCtx, {
            type: 'bar',
            data: {
                labels: prioTiers.map(function(t) { return t.label; }),
                datasets: [{
                    label: 'Issues Count',
                    data: prioTiers.map(function(t) { return t.count; }),
                    backgroundColor: prioTiers.map(function(t) { return t.color; }),
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // 5. Leaflet Aggregate Spatial Map (Privacy-Preserving Obfuscated Centroids)
    // -------------------------------------------------------------------------
    var mapEl = document.getElementById('analyticsClusterMap');
    if (mapEl) {
        var defaultLat = 21.1963;
        var defaultLng = 72.7958;
        var incidents = <?= json_encode($mapIncidents) ?>;

        if (incidents.length > 0 && incidents[0].obfuscated_lat) {
            defaultLat = parseFloat(incidents[0].obfuscated_lat);
            defaultLng = parseFloat(incidents[0].obfuscated_lng);
        }

        var map = L.map('analyticsClusterMap').setView([defaultLat, defaultLng], 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19
        }).addTo(map);

        var bounds = [];

        incidents.forEach(function(inc) {
            var lat = parseFloat(inc.obfuscated_lat);
            var lng = parseFloat(inc.obfuscated_lng);
            var radius = parseInt(inc.fuzz_radius_meters, 10) || 150;
            var score = parseInt(inc.priority_score, 10);

            if (isNaN(lat) || isNaN(lng)) return;

            var color = '#0dcaf0';
            if (score >= 80) {
                color = '#dc3545';
            } else if (score >= 60) {
                color = '#fd7e14';
            } else if (score >= 40) {
                color = '#ffc107';
            }

            // Spatial privacy fuzz boundary circle
            L.circle([lat, lng], {
                color: color,
                fillColor: color,
                fillOpacity: 0.15,
                weight: 1,
                radius: radius
            }).addTo(map);

            // Centroid marker with clean popup (zero PII)
            var popupContent = 
                '<div style="min-width: 200px;">' +
                    '<div class="fw-bold font-monospace text-primary">' + escapeHtml(inc.incident_reference) + '</div>' +
                    '<div class="fw-semibold text-dark my-1">' + escapeHtml(inc.title) + '</div>' +
                    '<div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1"></i>' + escapeHtml(inc.neighborhood) + '</div>' +
                    '<div class="d-flex justify-content-between align-items-center mt-2 pt-1 border-top">' +
                        '<span class="badge bg-secondary text-uppercase">' + escapeHtml(inc.status) + '</span>' +
                        '<span class="badge bg-danger">Score: ' + score + '</span>' +
                    '</div>' +
                    '<div class="mt-2 text-center">' +
                        '<a href="<?= htmlspecialchars(BASE_URL) ?>/admin/incident_detail.php?id=' + inc.id + '" class="btn btn-sm btn-outline-primary py-0" style="font-size:0.75rem;">View Dossier</a>' +
                    '</div>' +
                '</div>';

            L.marker([lat, lng]).addTo(map).bindPopup(popupContent);
            bounds.push([lat, lng]);
        });

        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 });
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
