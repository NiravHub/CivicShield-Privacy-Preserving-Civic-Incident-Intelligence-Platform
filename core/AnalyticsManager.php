<?php
declare(strict_types=1);

/**
 * CivicShield - Civic Intelligence Analytics & Reporting Manager
 *
 * Provides SQL-aggregated municipal performance metrics, status distributions,
 * civic category breakdowns, priority stratification, and longitudinal trends
 * while preserving citizen whistleblower anonymity via obfuscated map centroids.
 */
class AnalyticsManager
{
    /**
     * Standard Municipal Status Definitions & Visual Palette
     */
    public const STATUS_METADATA = [
        'reported'    => ['label' => 'Reported (New)',       'color' => '#6c757d', 'bg' => 'bg-secondary'],
        'triaged'     => ['label' => 'Triaged / Validated',   'color' => '#0d6efd', 'bg' => 'bg-primary'],
        'assigned'    => ['label' => 'Assigned (Dispatched)', 'color' => '#dc3545', 'bg' => 'bg-danger'],
        'in_progress'          => ['label' => 'In Progress (Active)',  'color' => '#ffc107', 'bg' => 'bg-warning'],
        'resolution_submitted' => ['label' => 'Resolution Submitted', 'color' => '#0dcaf0', 'bg' => 'bg-info'],
        'resolved'             => ['label' => 'Resolved (Remediated)', 'color' => '#198754', 'bg' => 'bg-success'],
        'closed'      => ['label' => 'Closed (Verified)',     'color' => '#212529', 'bg' => 'bg-dark'],
        'rejected'    => ['label' => 'Rejected / Out of Scope','color' => '#adb5bd', 'bg' => 'bg-light']
    ];

    /**
     * Standard Priority Tiers
     */
    public const PRIORITY_TIERS = [
        'low'      => ['label' => 'Low (0–39)',       'min' => 0,  'max' => 39,  'color' => '#0dcaf0'],
        'medium'   => ['label' => 'Medium (40–59)',   'min' => 40, 'max' => 59,  'color' => '#ffc107'],
        'high'     => ['label' => 'High (60–79)',     'min' => 60, 'max' => 79,  'color' => '#fd7e14'],
        'critical' => ['label' => 'Critical (80–100)','min' => 80, 'max' => 100, 'color' => '#dc3545']
    ];

    /**
     * Validate, sanitize, and normalize incoming filter parameters.
     * Handles inverted dates, invalid formats, and bounds safely.
     *
     * @param array $rawFilters
     * @return array Normalized filter array
     */
    public static function normalizeFilters(array $rawFilters): array
    {
        $normalized = [
            'date_from'     => null,
            'date_to'       => null,
            'category_id'   => null,
            'status'        => null,
            'min_priority'  => null,
            'priority_tier' => null,
            'search'        => null
        ];

        // 1. Date Range Validation (YYYY-MM-DD)
        $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
        $dateFrom = !empty($rawFilters['date_from']) && preg_match($datePattern, (string)$rawFilters['date_from'])
            ? (string)$rawFilters['date_from']
            : null;
        $dateTo = !empty($rawFilters['date_to']) && preg_match($datePattern, (string)$rawFilters['date_to'])
            ? (string)$rawFilters['date_to']
            : null;

        // Inverted date range protection: swap if date_from > date_to
        if ($dateFrom !== null && $dateTo !== null && strtotime($dateFrom) > strtotime($dateTo)) {
            $temp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $temp;
        }

        $normalized['date_from'] = $dateFrom;
        $normalized['date_to']   = $dateTo;

        // 2. Category Filter
        if (isset($rawFilters['category_id']) && is_numeric($rawFilters['category_id']) && (int)$rawFilters['category_id'] > 0) {
            $normalized['category_id'] = (int)$rawFilters['category_id'];
        }

        // 3. Status Filter
        $validStatuses = array_keys(self::STATUS_METADATA);
        if (!empty($rawFilters['status']) && in_array($rawFilters['status'], $validStatuses, true)) {
            $normalized['status'] = (string)$rawFilters['status'];
        }

        // 4. Minimum Priority Score
        if (isset($rawFilters['min_priority']) && is_numeric($rawFilters['min_priority'])) {
            $normalized['min_priority'] = max(0, min(100, (int)$rawFilters['min_priority']));
        }

        // 5. Priority Tier
        if (!empty($rawFilters['priority_tier']) && array_key_exists($rawFilters['priority_tier'], self::PRIORITY_TIERS)) {
            $normalized['priority_tier'] = (string)$rawFilters['priority_tier'];
        }

        // 6. Search Keyword
        if (!empty($rawFilters['search'])) {
            $search = trim((string)$rawFilters['search']);
            if ($search !== '') {
                $normalized['search'] = $search;
            }
        }

        return $normalized;
    }

    /**
     * Build parameterized WHERE SQL clauses and parameter map.
     *
     * @param array  $filters
     * @param string $prefix Table alias prefix (e.g. 'i.')
     * @return array [string $whereSql, array $params]
     */
    public static function buildWhere(array $filters, string $prefix = 'i.'): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $clauses[] = "{$prefix}created_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $clauses[] = "{$prefix}created_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['category_id'])) {
            $clauses[] = "{$prefix}category_id = :category_id";
            $params[':category_id'] = (int)$filters['category_id'];
        }

        if (!empty($filters['status'])) {
            $clauses[] = "{$prefix}status = :status";
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['min_priority']) && is_numeric($filters['min_priority']) && (int)$filters['min_priority'] > 0) {
            $clauses[] = "{$prefix}priority_score >= :min_priority";
            $params[':min_priority'] = (int)$filters['min_priority'];
        }

        if (!empty($filters['priority_tier']) && isset(self::PRIORITY_TIERS[$filters['priority_tier']])) {
            $tier = self::PRIORITY_TIERS[$filters['priority_tier']];
            $clauses[] = "{$prefix}priority_score >= :tier_min AND {$prefix}priority_score <= :tier_max";
            $params[':tier_min'] = $tier['min'];
            $params[':tier_max'] = $tier['max'];
        }

        if (!empty($filters['search'])) {
            $clauses[] = "({$prefix}incident_reference LIKE :search_ref OR {$prefix}title LIKE :search_title OR {$prefix}neighborhood LIKE :search_hood)";
            $params[':search_ref']   = '%' . $filters['search'] . '%';
            $params[':search_title'] = '%' . $filters['search'] . '%';
            $params[':search_hood']  = '%' . $filters['search'] . '%';
        }

        $whereSql = !empty($clauses) ? ' WHERE ' . implode(' AND ', $clauses) : '';
        return [$whereSql, $params];
    }

    /**
     * Retrieve aggregate summary KPI metrics for the selected filter window.
     *
     * @param array    $filters
     * @param PDO|null $pdo
     * @return array
     */
    public static function getSummaryMetrics(array $filters = [], ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $filters = self::normalizeFilters($filters);
        [$whereSql, $params] = self::buildWhere($filters, 'i.');

        $sql = "SELECT 
                    COUNT(*) AS total_incidents,
                    COALESCE(SUM(CASE WHEN i.status IN ('reported', 'triaged', 'assigned', 'in_progress', 'resolution_submitted') THEN 1 ELSE 0 END), 0) AS open_incidents,
                    COALESCE(SUM(CASE WHEN i.status = 'in_progress' THEN 1 ELSE 0 END), 0) AS in_progress_incidents,
                    COALESCE(SUM(CASE WHEN i.status = 'assigned' THEN 1 ELSE 0 END), 0) AS assigned_incidents,
                    COALESCE(SUM(CASE WHEN i.status = 'resolved' THEN 1 ELSE 0 END), 0) AS resolved_incidents,
                    COALESCE(SUM(CASE WHEN i.status = 'closed' THEN 1 ELSE 0 END), 0) AS closed_incidents,
                    COALESCE(SUM(CASE WHEN i.status IN ('resolved', 'closed') THEN 1 ELSE 0 END), 0) AS completed_incidents,
                    COALESCE(SUM(CASE WHEN i.status = 'rejected' THEN 1 ELSE 0 END), 0) AS rejected_incidents,
                    COALESCE(SUM(CASE WHEN i.priority_score >= 70 THEN 1 ELSE 0 END), 0) AS high_priority_incidents,
                    COALESCE(SUM(CASE WHEN i.priority_score >= 85 THEN 1 ELSE 0 END), 0) AS critical_priority_incidents,
                    COALESCE(SUM(i.reports_count), 0) AS total_citizen_reports,
                    ROUND(AVG(i.reports_count), 2) AS avg_reports_per_incident,
                    ROUND(AVG(i.priority_score), 1) AS avg_priority_score,
                    ROUND(AVG(
                        CASE 
                            WHEN i.resolved_at IS NOT NULL AND i.created_at IS NOT NULL AND i.resolved_at >= i.created_at 
                            THEN TIMESTAMPDIFF(SECOND, i.created_at, i.resolved_at) / 3600.0 
                            ELSE NULL 
                        END
                    ), 1) AS avg_resolution_hours
                FROM incidents i
                {$whereSql}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $metrics = $stmt->fetch();

        if (!$metrics) {
            return [
                'total_incidents'            => 0,
                'open_incidents'             => 0,
                'in_progress_incidents'      => 0,
                'assigned_incidents'         => 0,
                'resolved_incidents'         => 0,
                'closed_incidents'           => 0,
                'completed_incidents'        => 0,
                'rejected_incidents'         => 0,
                'high_priority_incidents'    => 0,
                'critical_priority_incidents'=> 0,
                'total_citizen_reports'      => 0,
                'avg_reports_per_incident'   => 0.0,
                'avg_priority_score'         => 0.0,
                'avg_resolution_hours'       => null,
                'resolution_rate_percent'    => 0.0
            ];
        }

        $total = (int)$metrics['total_incidents'];
        $completed = (int)$metrics['completed_incidents'];
        $resolutionRate = $total > 0 ? round(($completed / $total) * 100.0, 1) : 0.0;

        return [
            'total_incidents'            => $total,
            'open_incidents'             => (int)$metrics['open_incidents'],
            'in_progress_incidents'      => (int)$metrics['in_progress_incidents'],
            'assigned_incidents'         => (int)$metrics['assigned_incidents'],
            'resolved_incidents'         => (int)$metrics['resolved_incidents'],
            'closed_incidents'           => (int)$metrics['closed_incidents'],
            'completed_incidents'        => $completed,
            'rejected_incidents'         => (int)$metrics['rejected_incidents'],
            'high_priority_incidents'    => (int)$metrics['high_priority_incidents'],
            'critical_priority_incidents'=> (int)$metrics['critical_priority_incidents'],
            'total_citizen_reports'      => (int)$metrics['total_citizen_reports'],
            'avg_reports_per_incident'   => $metrics['avg_reports_per_incident'] !== null ? (float)$metrics['avg_reports_per_incident'] : 0.0,
            'avg_priority_score'         => $metrics['avg_priority_score'] !== null ? (float)$metrics['avg_priority_score'] : 0.0,
            'avg_resolution_hours'       => $metrics['avg_resolution_hours'] !== null ? (float)$metrics['avg_resolution_hours'] : null,
            'resolution_rate_percent'    => $resolutionRate
        ];
    }

    /**
     * Retrieve status distribution breakdown with counts and percentages.
     *
     * @param array    $filters
     * @param PDO|null $pdo
     * @return array
     */
    public static function getStatusBreakdown(array $filters = [], ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $filters = self::normalizeFilters($filters);
        [$whereSql, $params] = self::buildWhere($filters, 'i.');

        $sql = "SELECT i.status, COUNT(*) AS count
                FROM incidents i
                {$whereSql}
                GROUP BY i.status
                ORDER BY count DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $total = 0;
        $statusCounts = [];
        foreach ($rows as $row) {
            $statusCounts[$row['status']] = (int)$row['count'];
            $total += (int)$row['count'];
        }

        $breakdown = [];
        foreach (self::STATUS_METADATA as $statusKey => $meta) {
            $count = $statusCounts[$statusKey] ?? 0;
            $pct = $total > 0 ? round(($count / $total) * 100.0, 1) : 0.0;
            $breakdown[] = [
                'status'     => $statusKey,
                'label'      => $meta['label'],
                'count'      => $count,
                'percentage' => $pct,
                'color'      => $meta['color'],
                'bg'         => $meta['bg']
            ];
        }

        return [
            'total'     => $total,
            'breakdown' => $breakdown
        ];
    }

    /**
     * Retrieve category distribution breakdown with counts, percentages, and avg priority.
     *
     * @param array    $filters
     * @param PDO|null $pdo
     * @return array
     */
    public static function getCategoryBreakdown(array $filters = [], ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $filters = self::normalizeFilters($filters);
        [$whereSql, $params] = self::buildWhere($filters, 'i.');

        $sql = "SELECT 
                    c.id AS category_id,
                    c.name AS category_name,
                    c.icon AS category_icon,
                    c.department_code,
                    COUNT(i.id) AS incident_count,
                    COALESCE(SUM(i.reports_count), 0) AS report_count,
                    ROUND(AVG(i.priority_score), 1) AS avg_priority
                FROM incident_categories c
                LEFT JOIN incidents i ON i.category_id = c.id
                " . (!empty($whereSql) ? "AND " . ltrim($whereSql, ' WHERE') : "") . "
                WHERE c.is_active = 1
                GROUP BY c.id, c.name, c.icon, c.department_code
                ORDER BY incident_count DESC, c.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $totalIncidents = 0;
        foreach ($rows as $r) {
            $totalIncidents += (int)$r['incident_count'];
        }

        $categories = [];
        foreach ($rows as $r) {
            $count = (int)$r['incident_count'];
            $pct = $totalIncidents > 0 ? round(($count / $totalIncidents) * 100.0, 1) : 0.0;
            $categories[] = [
                'category_id'     => (int)$r['category_id'],
                'name'            => $r['category_name'],
                'icon'            => $r['category_icon'] ?? 'bi-tag',
                'department_code' => $r['department_code'],
                'incident_count'  => $count,
                'report_count'    => (int)$r['report_count'],
                'avg_priority'    => $r['avg_priority'] !== null ? (float)$r['avg_priority'] : 0.0,
                'percentage'      => $pct
            ];
        }

        return [
            'total_incidents' => $totalIncidents,
            'categories'      => $categories
        ];
    }

    /**
     * Retrieve priority score stratification across standard municipal tiers.
     *
     * @param array    $filters
     * @param PDO|null $pdo
     * @return array
     */
    public static function getPriorityDistribution(array $filters = [], ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $filters = self::normalizeFilters($filters);
        [$whereSql, $params] = self::buildWhere($filters, 'i.');

        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN i.priority_score BETWEEN 0 AND 39 THEN 1 ELSE 0 END), 0) AS tier_low,
                    COALESCE(SUM(CASE WHEN i.priority_score BETWEEN 40 AND 59 THEN 1 ELSE 0 END), 0) AS tier_medium,
                    COALESCE(SUM(CASE WHEN i.priority_score BETWEEN 60 AND 79 THEN 1 ELSE 0 END), 0) AS tier_high,
                    COALESCE(SUM(CASE WHEN i.priority_score BETWEEN 80 AND 100 THEN 1 ELSE 0 END), 0) AS tier_critical,
                    COUNT(*) AS total
                FROM incidents i
                {$whereSql}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        $total = $row ? (int)$row['total'] : 0;
        $tiers = [];

        foreach (self::PRIORITY_TIERS as $key => $meta) {
            $col = 'tier_' . $key;
            $count = $row ? (int)$row[$col] : 0;
            $pct = $total > 0 ? round(($count / $total) * 100.0, 1) : 0.0;
            $tiers[] = [
                'tier'       => $key,
                'label'      => $meta['label'],
                'count'      => $count,
                'percentage' => $pct,
                'color'      => $meta['color']
            ];
        }

        return [
            'total' => $total,
            'tiers' => $tiers
        ];
    }

    /**
     * Retrieve chronological incident volume trend grouped by day.
     *
     * @param array    $filters
     * @param PDO|null $pdo
     * @return array [labels => array, created_series => array, resolved_series => array]
     */
    public static function getVolumeTrends(array $filters = [], ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $filters = self::normalizeFilters($filters);
        [$whereSql, $params] = self::buildWhere($filters, 'i.');

        $sql = "SELECT 
                    DATE(i.created_at) AS date_point,
                    COUNT(*) AS created_count,
                    COALESCE(SUM(CASE WHEN i.status IN ('resolved', 'closed') THEN 1 ELSE 0 END), 0) AS resolved_count
                FROM incidents i
                {$whereSql}
                GROUP BY DATE(i.created_at)
                ORDER BY date_point ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $labels = [];
        $createdSeries = [];
        $resolvedSeries = [];

        foreach ($rows as $row) {
            $labels[] = date('M d', strtotime($row['date_point']));
            $createdSeries[] = (int)$row['created_count'];
            $resolvedSeries[] = (int)$row['resolved_count'];
        }

        return [
            'labels'          => $labels,
            'created_series'  => $createdSeries,
            'resolved_series' => $resolvedSeries,
            'total_points'    => count($labels)
        ];
    }

    /**
     * Retrieve privacy-preserving geospatial incident centroids for aggregate mapping.
     * Strictly excludes citizen names, contact numbers, email addresses, and raw GPS intake coordinates.
     *
     * @param array    $filters
     * @param int      $limit Maximum markers to plot (default 200)
     * @param PDO|null $pdo
     * @return array
     */
    public static function getMapIncidents(array $filters = [], int $limit = 200, ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $filters = self::normalizeFilters($filters);
        [$whereSql, $params] = self::buildWhere($filters, 'i.');
        $limit = max(1, min(500, $limit));

        $sql = "SELECT 
                    i.id,
                    i.incident_reference,
                    i.title,
                    i.severity,
                    i.priority_score,
                    i.status,
                    i.neighborhood,
                    i.city,
                    i.reports_count,
                    i.obfuscated_lat,
                    i.obfuscated_lng,
                    i.fuzz_radius_meters,
                    i.created_at,
                    c.name AS category_name,
                    c.icon AS category_icon
                FROM incidents i
                JOIN incident_categories c ON c.id = i.category_id
                {$whereSql}
                ORDER BY i.priority_score DESC, i.created_at DESC
                LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
