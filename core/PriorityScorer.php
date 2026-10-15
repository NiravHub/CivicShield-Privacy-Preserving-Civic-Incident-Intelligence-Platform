<?php
declare(strict_types=1);

/**
 * CivicShield - Incident Priority Scoring Engine
 *
 * Implements a transparent, rule-based decision-support priority score (0–100)
 * based on four deterministic municipal criteria:
 * 1. Submitted Severity (35% weight)
 * 2. Independent Report Volume (25% weight)
 * 3. Elapsed Time / Resolution SLA Proximity (20% weight)
 * 4. Category Urgency / SLA Speed (20% weight)
 *
 * NOTE: This is a decision-support heuristic, NOT an AI-generated assessment.
 */
class PriorityScorer
{
    // Component Weights
    public const WEIGHT_SEVERITY = 0.35;
    public const WEIGHT_REPORTS  = 0.25;
    public const WEIGHT_ELAPSED  = 0.20;
    public const WEIGHT_CATEGORY = 0.20;

    /**
     * Compute rule-based priority score and transparent breakdown.
     *
     * @param string   $severity       'low' | 'medium' | 'high' | 'critical'
     * @param int      $reportsCount   Count of independent unique reports linked
     * @param float    $elapsedHours   Hours elapsed since incident was first reported
     * @param int|null $slaHours       Category SLA in hours (e.g. 12, 24, 48)
     * @return array [score => int, breakdown => array]
     */
    public static function computeScore(
        string $severity,
        int $reportsCount,
        float $elapsedHours,
        ?int $slaHours = null
    ): array {
        // 1. Severity Base Score (0 - 100)
        $sevLower = strtolower(trim($severity));
        $sevScores = [
            'critical' => 100.0,
            'high'     => 75.0,
            'medium'   => 50.0,
            'low'      => 25.0,
        ];
        $sevScore = $sevScores[$sevLower] ?? 50.0;
        $sevContribution = $sevScore * self::WEIGHT_SEVERITY;

        // 2. Report Volume / Public Convergence (0 - 100)
        // Strictly prevents unbounded growth and scales with distinct citizen reports
        $distinctReports = max(1, $reportsCount);
        if ($distinctReports === 1) {
            $repScore = 20.0;
        } elseif ($distinctReports === 2) {
            $repScore = 45.0;
        } elseif ($distinctReports === 3) {
            $repScore = 70.0;
        } elseif ($distinctReports === 4) {
            $repScore = 85.0;
        } else {
            // 5 or more reports reaches maximum convergence signal
            $repScore = min(100.0, 85.0 + ($distinctReports - 4) * 5.0);
        }
        $repContribution = $repScore * self::WEIGHT_REPORTS;

        // 3. Elapsed Time / Escalation Urgency (0 - 100)
        // Older unresolved incidents gradually gain priority
        $hours = max(0.0, $elapsedHours);
        if ($hours <= 6.0) {
            $elapsedScore = 20.0;
        } elseif ($hours <= 24.0) {
            $elapsedScore = 40.0;
        } elseif ($hours <= 48.0) {
            $elapsedScore = 65.0;
        } elseif ($hours <= 72.0) {
            $elapsedScore = 85.0;
        } else {
            $elapsedScore = 100.0;
        }
        $elapsedContribution = $elapsedScore * self::WEIGHT_ELAPSED;

        // 4. Category Urgency / SLA Speed (0 - 100)
        if ($slaHours !== null && $slaHours > 0) {
            if ($slaHours <= 12) {
                $catScore = 100.0; // Urgent hazard: electrical / immediate danger
            } elseif ($slaHours <= 24) {
                $catScore = 75.0;  // Water burst / drainage / garbage
            } elseif ($slaHours <= 36) {
                $catScore = 50.0;  // Stray animals
            } else {
                $catScore = 35.0;  // Potholes / standard civil repairs
            }
        } else {
            $catScore = 50.0;
        }
        $catContribution = $catScore * self::WEIGHT_CATEGORY;

        // Total Composite Score clamped strictly to [0, 100]
        $totalRaw = $sevContribution + $repContribution + $elapsedContribution + $catContribution;
        $finalScore = (int)round(min(100.0, max(0.0, $totalRaw)));

        $breakdown = [
            'severity' => [
                'input'        => $sevLower,
                'subscore'     => round($sevScore, 1),
                'weight'       => self::WEIGHT_SEVERITY,
                'contribution' => round($sevContribution, 2)
            ],
            'report_volume' => [
                'input_count'  => $distinctReports,
                'subscore'     => round($repScore, 1),
                'weight'       => self::WEIGHT_REPORTS,
                'contribution' => round($repContribution, 2)
            ],
            'elapsed_time' => [
                'input_hours'  => round($hours, 1),
                'subscore'     => round($elapsedScore, 1),
                'weight'       => self::WEIGHT_ELAPSED,
                'contribution' => round($elapsedContribution, 2)
            ],
            'category_urgency' => [
                'sla_hours'    => $slaHours ?? 24,
                'subscore'     => round($catScore, 1),
                'weight'       => self::WEIGHT_CATEGORY,
                'contribution' => round($catContribution, 2)
            ],
            'composite_priority_score' => $finalScore,
            'calculated_at'            => date('c')
        ];

        return [
            'score'     => $finalScore,
            'breakdown' => $breakdown
        ];
    }

    /**
     * Recalculate and update the priority score for a specific consolidated incident.
     *
     * @param int      $incidentId Target incident ID
     * @param PDO|null $pdo        Optional active PDO instance
     * @return array [score => int, breakdown => array]
     */
    public static function updateIncidentPriority(int $incidentId, ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        // Fetch incident data joined with category SLA
        $stmt = $pdo->prepare(
            'SELECT i.id, i.severity, i.created_at, c.resolution_sla_hours
             FROM incidents i
             LEFT JOIN incident_categories c ON c.id = i.category_id
             WHERE i.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $incidentId]);
        $incident = $stmt->fetch();

        if (!$incident) {
            return ['score' => 0, 'breakdown' => []];
        }

        // Count independent reports linked to this incident (distinct ID)
        $countStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT id) FROM incident_reports WHERE incident_id = :id'
        );
        $countStmt->execute([':id' => $incidentId]);
        $distinctReports = (int)$countStmt->fetchColumn();

        // If reports_count is greater than 0, use it, otherwise at least 1
        $effectiveReports = max(1, $distinctReports);

        // Calculate hours elapsed since creation
        $createdAt = strtotime((string)$incident['created_at']);
        $elapsedHours = $createdAt ? max(0.0, (time() - $createdAt) / 3600.0) : 0.0;

        $slaHours = isset($incident['resolution_sla_hours']) ? (int)$incident['resolution_sla_hours'] : null;

        $result = self::computeScore(
            (string)$incident['severity'],
            $effectiveReports,
            $elapsedHours,
            $slaHours
        );

        // Update incidents table: priority_score, reports_count, priority_breakdown
        // Check if priority_breakdown column is present
        try {
            $updateStmt = $pdo->prepare(
                'UPDATE incidents 
                 SET priority_score = :score,
                     reports_count = :reports_count,
                     priority_breakdown = :breakdown,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $updateStmt->execute([
                ':score'         => $result['score'],
                ':reports_count' => $effectiveReports,
                ':breakdown'     => json_encode($result['breakdown']),
                ':id'            => $incidentId
            ]);
        } catch (Throwable) {
            // Fallback if priority_breakdown column is unavailable
            $updateStmt = $pdo->prepare(
                'UPDATE incidents 
                 SET priority_score = :score,
                     reports_count = :reports_count,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $updateStmt->execute([
                ':score'         => $result['score'],
                ':reports_count' => $effectiveReports,
                ':id'            => $incidentId
            ]);
        }

        return $result;
    }

    /**
     * Recalculate priority scores across all active incidents.
     *
     * @param PDO|null $pdo
     * @return int Count of incidents updated
     */
    public static function recalculateAll(?PDO $pdo = null): int
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $stmt = $pdo->query("SELECT id FROM incidents WHERE status NOT IN ('closed', 'rejected')");
        $incidentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $updated = 0;
        foreach ($incidentIds as $id) {
            self::updateIncidentPriority((int)$id, $pdo);
            $updated++;
        }

        return $updated;
    }
}
