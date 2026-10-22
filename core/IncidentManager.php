<?php
declare(strict_types=1);

/**
 * CivicShield - Incident Operations & Resolution Manager
 *
 * Handles administrative incident triage, search, filtering, pagination,
 * departmental work order assignment, and validated status lifecycle transitions.
 */
class IncidentManager
{
    /**
     * Allowed State Transitions Matrix
     * Defines strictly valid municipal incident lifecycle paths.
     */
    public const ALLOWED_TRANSITIONS = [
        'reported' => ['triaged', 'assigned', 'rejected'],
        'triaged' => ['assigned', 'in_progress', 'rejected'],
        'assigned' => ['in_progress', 'triaged', 'rejected'],
        'in_progress' => ['resolution_submitted', 'assigned', 'rejected'],
        'resolution_submitted' => ['resolved', 'rejected'],
        'resolved' => ['closed', 'in_progress'],
        'closed' => ['in_progress'],
        'rejected' => ['reported', 'triaged']
    ];

    /**
     * Allowed State Transitions Matrix for Field Officers
     * Officers may acknowledge/commence field action ('assigned' -> 'in_progress')
     * and submit verified field remediation evidence ('in_progress' -> 'resolution_submitted').
     * Direct marking of 'resolved' by officers is prohibited; resolution requires admin verification.
     */
    public const OFFICER_ALLOWED_TRANSITIONS = [
        'assigned' => ['in_progress'],
        'in_progress' => ['resolution_submitted']
    ];

    /**
     * List incidents with validated filters, searching, and pagination.
     *
     * @param array    $filters Filter criteria (status, category_id, min_priority, date_from, date_to, search)
     * @param int      $page    Page number (1-indexed)
     * @param int      $perPage Records per page (clamped between 5 and 50)
     * @param PDO|null $pdo     Active PDO instance
     * @return array [incidents => array, pagination => array]
     */
    public static function getIncidents(array $filters = [], int $page = 1, int $perPage = 10, ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        // Validate and clamp pagination inputs
        $page = max(1, $page);
        $perPage = max(5, min(50, $perPage));

        $whereClauses = [];
        $params = [];

        // 1. Status Filter
        $validStatuses = ['reported', 'triaged', 'assigned', 'in_progress', 'resolution_submitted', 'resolved', 'closed', 'rejected'];
        if (!empty($filters['status']) && in_array($filters['status'], $validStatuses, true)) {
            $whereClauses[] = 'i.status = :status';
            $params[':status'] = $filters['status'];
        }

        // 2. Category Filter
        if (!empty($filters['category_id']) && is_numeric($filters['category_id']) && (int) $filters['category_id'] > 0) {
            $whereClauses[] = 'i.category_id = :category_id';
            $params[':category_id'] = (int) $filters['category_id'];
        }

        // 3. Minimum Priority Filter
        if (isset($filters['min_priority']) && is_numeric($filters['min_priority']) && (int) $filters['min_priority'] > 0) {
            $minPri = max(0, min(100, (int) $filters['min_priority']));
            $whereClauses[] = 'i.priority_score >= :min_priority';
            $params[':min_priority'] = $minPri;
        }

        // 4. Date Range Filter (created_at)
        if (!empty($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['date_from'])) {
            $whereClauses[] = 'i.created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['date_to'])) {
            $whereClauses[] = 'i.created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        // 5. Search Filter (incident reference, title, or linked report reference)
        if (!empty($filters['search'])) {
            $searchTerm = trim((string) $filters['search']);
            if ($searchTerm !== '') {
                $whereClauses[] = '(i.incident_reference LIKE :search_ref OR i.title LIKE :search_title OR EXISTS (
                    SELECT 1 FROM incident_reports r 
                    WHERE r.incident_id = i.id AND r.report_reference LIKE :search_rep
                ))';
                $params[':search_ref'] = '%' . $searchTerm . '%';
                $params[':search_title'] = '%' . $searchTerm . '%';
                $params[':search_rep'] = '%' . $searchTerm . '%';
            }
        }

        $whereSql = !empty($whereClauses) ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        // Count total matching records
        $countSql = "SELECT COUNT(*) FROM incidents i {$whereSql}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        if ($page > $totalPages && $totalRecords > 0) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        // Query paginated incident records with category details and active assignment
        $sql = "SELECT i.id, i.incident_reference, i.category_id, i.title, i.description,
                       i.severity, i.priority_score, i.priority_breakdown, i.status,
                       i.neighborhood, i.city, i.reports_count, i.created_at, i.updated_at,
                       i.resolved_at, i.closed_at,
                       c.name AS category_name, c.icon AS category_icon, c.resolution_sla_hours,
                       a.id AS active_assignment_id, a.assignment_status, a.instructions AS assignment_instructions,
                       u.id AS officer_id, u.username AS officer_name, u.department AS officer_department
                FROM incidents i
                JOIN incident_categories c ON c.id = i.category_id
                LEFT JOIN incident_assignments a ON a.id = (
                    SELECT ia.id FROM incident_assignments ia 
                    WHERE ia.incident_id = i.id AND ia.assignment_status NOT IN ('completed', 'cancelled', 'reassigned')
                    ORDER BY ia.id DESC LIMIT 1
                )
                LEFT JOIN users u ON u.id = a.officer_id
                {$whereSql}
                ORDER BY i.priority_score DESC, i.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);

        // Bind filter params
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $incidents = $stmt->fetchAll();

        // Decode JSON priority breakdown safely
        foreach ($incidents as &$inc) {
            if (!empty($inc['priority_breakdown'])) {
                $inc['priority_breakdown'] = json_decode((string) $inc['priority_breakdown'], true);
            }
        }
        unset($inc);

        return [
            'incidents' => $incidents,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages,
                'has_previous' => $page > 1,
                'has_next' => $page < $totalPages
            ]
        ];
    }

    /**
     * Retrieve complete incident record with category and active assignment.
     *
     * @param int      $incidentId
     * @param PDO|null $pdo
     * @return array|null
     */
    public static function getIncidentDetails(int $incidentId, ?PDO $pdo = null): ?array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $stmt = $pdo->prepare(
            "SELECT i.*, 
                    c.name AS category_name, c.icon AS category_icon, c.department_code, c.resolution_sla_hours,
                    a.id AS active_assignment_id, a.assignment_status, a.instructions AS assignment_instructions,
                    a.assigned_at AS active_assigned_at,
                    u.id AS officer_id, u.username AS officer_name, u.department AS officer_department
             FROM incidents i
             JOIN incident_categories c ON c.id = i.category_id
             LEFT JOIN incident_assignments a ON a.id = (
                 SELECT ia.id FROM incident_assignments ia 
                 WHERE ia.incident_id = i.id AND ia.assignment_status NOT IN ('completed', 'cancelled', 'reassigned')
                 ORDER BY ia.id DESC LIMIT 1
             )
             LEFT JOIN users u ON u.id = a.officer_id
             WHERE i.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $incidentId]);
        $incident = $stmt->fetch();

        if (!$incident) {
            return null;
        }

        if (!empty($incident['priority_breakdown'])) {
            $incident['priority_breakdown'] = json_decode((string) $incident['priority_breakdown'], true);
        }

        return $incident;
    }

    /**
     * Retrieve linked reports for an incident without exposing private contact enclave or raw tracking tokens.
     *
     * @param int      $incidentId
     * @param PDO|null $pdo
     * @param bool     $includeRawCoords When false (officer views), omit restricted intake GPS fields.
     * @return array
     */
    public static function getLinkedReports(int $incidentId, ?PDO $pdo = null, bool $includeRawCoords = true): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $coordColumns = $includeRawCoords
            ? 'r.raw_lat, r.raw_lng, r.location_description,'
            : 'r.location_description,';

        $stmt = $pdo->prepare(
            "SELECT r.id, r.report_reference, r.title, r.description, r.submitted_severity,
                    r.submission_mode, {$coordColumns}
                    r.status, r.created_at,
                    u.username AS citizen_pseudonym,
                    (SELECT COUNT(*) FROM incident_attachments att WHERE att.report_id = r.id) AS attachments_count
             FROM incident_reports r
             LEFT JOIN users u ON u.id = r.citizen_id
             WHERE r.incident_id = :id
             ORDER BY r.created_at ASC"
        );
        $stmt->execute([':id' => $incidentId]);
        return $stmt->fetchAll();
    }

    /**
     * Retrieve status transition audit history for an incident.
     *
     * @param int      $incidentId
     * @param PDO|null $pdo
     * @return array
     */
    public static function getStatusHistory(int $incidentId, ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $stmt = $pdo->prepare(
            'SELECT h.*, u.username AS actor_username, u.role AS actor_role
             FROM incident_status_history h
             LEFT JOIN users u ON u.id = h.changed_by_user_id
             WHERE h.incident_id = :id
             ORDER BY h.created_at DESC, h.id DESC'
        );
        $stmt->execute([':id' => $incidentId]);
        return $stmt->fetchAll();
    }

    /**
     * Retrieve assignment history for an incident.
     *
     * @param int      $incidentId
     * @param PDO|null $pdo
     * @return array
     */
    public static function getAssignmentHistory(int $incidentId, ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $stmt = $pdo->prepare(
            'SELECT a.*, 
                    o.username AS officer_username, o.department AS officer_department,
                    adm.username AS assigned_by_username
             FROM incident_assignments a
             JOIN users o ON o.id = a.officer_id
             JOIN users adm ON adm.id = a.assigned_by_user_id
             WHERE a.incident_id = :id
             ORDER BY a.assigned_at DESC, a.id DESC'
        );
        $stmt->execute([':id' => $incidentId]);
        return $stmt->fetchAll();
    }

    /**
     * Retrieve list of active field officers eligible for incident dispatch.
     *
     * @param PDO|null $pdo
     * @return array
     */
    public static function getActiveOfficers(?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $stmt = $pdo->query(
            "SELECT id, username, email, department 
             FROM users 
             WHERE role = 'officer' AND status = 'active'
             ORDER BY department ASC, username ASC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Assign or reassign an incident to an eligible field officer.
     *
     * @param int      $incidentId   Target incident ID
     * @param int      $officerId    Target officer user ID
     * @param int      $adminUserId  Assigning administrator user ID
     * @param string   $instructions Operational dispatch instructions
     * @param PDO|null $pdo          Active PDO instance
     * @return array [success => bool, message => string]
     */
    public static function assignIncident(
        int $incidentId,
        int $officerId,
        int $adminUserId,
        string $instructions = '',
        ?PDO $pdo = null
    ): array {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        // 1. Validate Incident
        $incStmt = $pdo->prepare('SELECT id, incident_reference, status FROM incidents WHERE id = :id LIMIT 1');
        $incStmt->execute([':id' => $incidentId]);
        $incident = $incStmt->fetch();

        if (!$incident) {
            return ['success' => false, 'message' => 'Target incident does not exist.'];
        }

        if (in_array($incident['status'], ['closed', 'rejected'], true)) {
            return ['success' => false, 'message' => 'Cannot assign an incident that is currently closed or rejected.'];
        }

        // 2. Validate Target Officer Account
        $offStmt = $pdo->prepare('SELECT id, username, role, department, status FROM users WHERE id = :id LIMIT 1');
        $offStmt->execute([':id' => $officerId]);
        $officer = $offStmt->fetch();

        if (!$officer) {
            return ['success' => false, 'message' => 'Selected officer account does not exist.'];
        }

        if ($officer['role'] !== 'officer') {
            return ['success' => false, 'message' => 'Selected user is not a field officer. Only officer accounts can receive dispatch assignments.'];
        }

        if ($officer['status'] !== 'active') {
            return ['success' => false, 'message' => 'Selected officer account is inactive or suspended.'];
        }

        // 3. Begin Transaction
        $manageTx = !$pdo->inTransaction();
        if ($manageTx) {
            $pdo->beginTransaction();
        }
        try {
            // Find existing active assignment if any
            $actStmt = $pdo->prepare(
                "SELECT id, officer_id, assignment_status 
                 FROM incident_assignments 
                 WHERE incident_id = :inc_id AND assignment_status NOT IN ('completed', 'cancelled', 'reassigned')
                 ORDER BY id DESC LIMIT 1"
            );
            $actStmt->execute([':inc_id' => $incidentId]);
            $currentAssignment = $actStmt->fetch();

            $isReassignment = false;
            if ($currentAssignment) {
                $isReassignment = true;
                // Mark previous active assignment as reassigned
                $upAct = $pdo->prepare(
                    "UPDATE incident_assignments 
                     SET assignment_status = 'reassigned', updated_at = NOW() 
                     WHERE id = :id"
                );
                $upAct->execute([':id' => $currentAssignment['id']]);
            }

            // Create new assignment work order
            $cleanInstructions = trim($instructions);
            $insStmt = $pdo->prepare(
                'INSERT INTO incident_assignments 
                 (incident_id, officer_id, assigned_by_user_id, assignment_status, instructions, assigned_at)
                 VALUES (:inc_id, :off_id, :admin_id, \'assigned\', :instructions, NOW())'
            );
            $insStmt->execute([
                ':inc_id' => $incidentId,
                ':off_id' => $officerId,
                ':admin_id' => $adminUserId,
                ':instructions' => $cleanInstructions !== '' ? $cleanInstructions : null
            ]);
            $assignmentId = (int) $pdo->lastInsertId();

            // If incident was reported or triaged, transition status to 'assigned'
            $currentStatus = $incident['status'];
            if (in_array($currentStatus, ['reported', 'triaged'], true)) {
                $pdo->prepare("UPDATE incidents SET status = 'assigned', updated_at = NOW() WHERE id = :id")
                    ->execute([':id' => $incidentId]);

                $reason = $isReassignment
                    ? "Reassigned to {$officer['username']} ({$officer['department']})."
                    : "Assigned to {$officer['username']} ({$officer['department']}).";

                $pdo->prepare(
                    'INSERT INTO incident_status_history 
                     (incident_id, changed_by_user_id, status_from, status_to, reason, is_public, created_at)
                     VALUES (:inc_id, :actor_id, :s_from, \'assigned\', :reason, 1, NOW())'
                )->execute([
                            ':inc_id' => $incidentId,
                            ':actor_id' => $adminUserId,
                            ':s_from' => $currentStatus,
                            ':reason' => $reason
                        ]);
            }

            // Record immutable audit log
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ipHash = hash('sha256', $clientIp . (string) Env::get('CSRF_SECRET_KEY', 'civicshield_salt'));
            $action = $isReassignment ? 'INCIDENT_REASSIGNED' : 'INCIDENT_ASSIGNED';

            $pdo->prepare(
                'INSERT INTO audit_logs 
                 (actor_user_id, actor_role, action, target_type, target_id, ip_hash, metadata_json, created_at)
                 VALUES (:actor_id, \'admin\', :action, \'incident\', :target_id, :ip_hash, :meta, NOW())'
            )->execute([
                        ':actor_id' => $adminUserId,
                        ':action' => $action,
                        ':target_id' => $incident['incident_reference'],
                        ':ip_hash' => $ipHash,
                        ':meta' => json_encode([
                            'assignment_id' => $assignmentId,
                            'incident_id' => $incidentId,
                            'officer_id' => $officerId,
                            'officer_name' => $officer['username'],
                            'department' => $officer['department'],
                            'is_reassignment' => $isReassignment,
                            'instructions' => $cleanInstructions
                        ])
                    ]);

            if ($manageTx) {
                $pdo->commit();
            }

            $msg = $isReassignment
                ? "Incident successfully reassigned to {$officer['username']}."
                : "Incident successfully assigned to {$officer['username']}.";

            return ['success' => true, 'message' => $msg];
        } catch (Throwable $e) {
            if ($manageTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @error_log('[Assignment Error] ' . $e->getMessage());
            return ['success' => false, 'message' => 'A database error occurred during assignment.'];
        }
    }

    /**
     * Transition an incident to a new validated municipal status.
     *
     * @param int      $incidentId  Target incident ID
     * @param string   $newStatus   Requested status
     * @param int      $actorUserId Performing user ID (admin or officer)
     * @param string   $reason      Transition or resolution notes
     * @param PDO|null $pdo         Active PDO instance
     * @param string   $actorRole   Actor role ('admin' or 'officer')
     * @return array [success => bool, message => string]
     */
    // Helper: Check if an incident has at least one verified resolution evidence record
    private static function hasApprovedEvidence(int $incidentId, ?PDO $pdo = null): bool
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM resolution_evidence WHERE incident_id = :inc_id AND verification_status = :status"
            );
            $stmt->execute([':inc_id' => $incidentId, ':status' => 'verified']);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            @error_log('[hasApprovedEvidence error] ' . $e->getMessage());
            // On error treat as no approved evidence to avoid unsafe transition
            return false;
        }
    }

    public static function updateStatus(
        int $incidentId,
        string $newStatus,
        int $actorUserId,
        string $reason = '',
        ?PDO $pdo = null,
        string $actorRole = 'admin'
    ): array {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $cleanReason = trim($reason);

        // 1. Fetch current incident record
        $stmt = $pdo->prepare('SELECT id, incident_reference, status, severity, category_id FROM incidents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $incidentId]);
        $incident = $stmt->fetch();

        if (!$incident) {
            return ['success' => false, 'message' => 'Incident record not found.'];
        }

        $currentStatus = $incident['status'];

        if ($currentStatus === $newStatus) {
            return ['success' => false, 'message' => "Incident is already in '{$newStatus}' status."];
        }

        // 2. Role-specific validation
        $activeAssignment = null;
        if ($actorRole === 'officer') {
            // Verify incident is actively assigned to this officer
            $actStmt = $pdo->prepare(
                "SELECT id, assignment_status 
                 FROM incident_assignments 
                 WHERE incident_id = :inc_id AND officer_id = :off_id 
                   AND assignment_status NOT IN ('completed', 'cancelled', 'reassigned')
                 ORDER BY id DESC LIMIT 1"
            );
            $actStmt->execute([':inc_id' => $incidentId, ':off_id' => $actorUserId]);
            $activeAssignment = $actStmt->fetch();

            if (!$activeAssignment) {
                return [
                    'success' => false,
                    'message' => 'This incident is not actively assigned to your officer account.'
                ];
            }

            // Verify officer state transition path
            $allowed = self::OFFICER_ALLOWED_TRANSITIONS[$currentStatus] ?? [];
            if (!in_array($newStatus, $allowed, true)) {
                return [
                    'success' => false,
                    'message' => "Officers are not authorized to transition incident from '{$currentStatus}' to '{$newStatus}'."
                ];
            }

            // Officers must submit photo evidence to transition to resolution_submitted
            if ($newStatus === 'resolution_submitted') {
                return [
                    'success' => false,
                    'message' => "Field officers must submit resolution evidence with photos via the dedicated resolution submission workflow."
                ];
            }

            // Officers must provide at least 5 chars for all transitions
            if (strlen($cleanReason) < 5) {
                return [
                    'success' => false,
                    'message' => "A descriptive note of at least 5 characters is required when setting status to '{$newStatus}'."
                ];
            }
        } else {
            // Admin transition validation
            $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
            if (!in_array($newStatus, $allowed, true)) {
                return [
                    'success' => false,
                    'message' => "Invalid transition: Cannot move incident from '{$currentStatus}' to '{$newStatus}'."
                ];
            }

            // Require resolution/closure/rejection/rework justifications
            if (in_array($newStatus, ['resolved', 'closed', 'rejected', 'in_progress'], true)) {
                if (strlen($cleanReason) < 5) {
                    return [
                        'success' => false,
                        'message' => "A descriptive note of at least 5 characters is required when setting status to '{$newStatus}'."
                    ];
                }
            }
        }

        // 3. Begin Transaction
        $manageTx = !$pdo->inTransaction();
        if ($manageTx) {
            $pdo->beginTransaction();
        }
        try {
            if ($newStatus === 'resolved') {
                $resolvedAtSql = 'NOW()';
            } elseif (in_array($newStatus, ['in_progress', 'assigned', 'reported', 'rejected'], true)) {
                $resolvedAtSql = 'NULL';
            } else {
                $resolvedAtSql = 'resolved_at';
            }

            if ($newStatus === 'closed') {
                $closedAtSql = 'NOW()';
            } elseif ($currentStatus === 'closed' && $newStatus !== 'closed') {
                $closedAtSql = 'NULL';
            } else {
                $closedAtSql = 'closed_at';
            }

            $upSql = "UPDATE incidents 
                      SET status = :status,
                          resolved_at = {$resolvedAtSql},
                          closed_at = {$closedAtSql},
                          updated_at = NOW()
                      WHERE id = :id";
            $upStmt = $pdo->prepare($upSql);
            $upStmt->execute([
                ':status' => $newStatus,
                ':id' => $incidentId
            ]);

            // Update assignment status
            if ($actorRole === 'officer' && $activeAssignment) {
                if ($newStatus === 'in_progress') {
                    $pdo->prepare(
                        "UPDATE incident_assignments 
                         SET assignment_status = 'in_progress', acknowledged_at = COALESCE(acknowledged_at, NOW()), updated_at = NOW()
                         WHERE id = :id"
                    )->execute([':id' => $activeAssignment['id']]);
                } elseif ($newStatus === 'resolution_submitted') {
                    $pdo->prepare(
                        "UPDATE incident_assignments 
                         SET assignment_status = 'resolution_submitted', updated_at = NOW()
                         WHERE id = :id"
                    )->execute([':id' => $activeAssignment['id']]);
                } elseif ($newStatus === 'resolved') {
                    $pdo->prepare(
                        "UPDATE incident_assignments 
                         SET assignment_status = 'completed', completed_at = NOW(), updated_at = NOW()
                         WHERE id = :id"
                    )->execute([':id' => $activeAssignment['id']]);
                }
            } elseif (in_array($newStatus, ['resolved', 'closed'], true)) {
                // Require verified evidence before resolving an incident.
                if ($newStatus === 'resolved' && !self::hasApprovedEvidence($incidentId, $pdo)) {
                    if ($manageTx && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    return [
                        'success' => false,
                        'message' => "Incident can only be marked 'resolved' after evidence has been submitted and approved."
                    ];
                }

                // If admin marked resolved or closed, complete any open officer assignment
                $pdo->prepare(
                    "UPDATE incident_assignments 
                     SET assignment_status = 'completed', completed_at = NOW(), updated_at = NOW()
                     WHERE incident_id = :id AND assignment_status NOT IN ('completed', 'cancelled', 'reassigned')"
                )->execute([':id' => $incidentId]);
            } elseif ($newStatus === 'in_progress') {
                // If admin moved back to in_progress (rework requested), return active assignment to in_progress
                $pdo->prepare(
                    "UPDATE incident_assignments 
                     SET assignment_status = 'in_progress', updated_at = NOW()
                     WHERE incident_id = :id AND assignment_status NOT IN ('completed', 'cancelled', 'reassigned')"
                )->execute([':id' => $incidentId]);
            }

            // Insert into status history
            $histStmt = $pdo->prepare(
                'INSERT INTO incident_status_history 
                 (incident_id, changed_by_user_id, status_from, status_to, reason, is_public, created_at)
                 VALUES (:inc_id, :actor_id, :s_from, :s_to, :reason, 1, NOW())'
            );
            $histStmt->execute([
                ':inc_id' => $incidentId,
                ':actor_id' => $actorUserId,
                ':s_from' => $currentStatus,
                ':s_to' => $newStatus,
                ':reason' => $cleanReason !== '' ? $cleanReason : "Status updated to {$newStatus}."
            ]);

            // Insert into audit logs
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ipHash = hash('sha256', $clientIp . (string) Env::get('CSRF_SECRET_KEY', 'civicshield_salt'));
            $action = ($actorRole === 'officer') ? 'OFFICER_STATUS_UPDATE' : 'INCIDENT_STATUS_TRANSITION';

            $pdo->prepare(
                'INSERT INTO audit_logs 
                 (actor_user_id, actor_role, action, target_type, target_id, ip_hash, metadata_json, created_at)
                 VALUES (:actor_id, :actor_role, :action, \'incident\', :target_id, :ip_hash, :meta, NOW())'
            )->execute([
                        ':actor_id' => $actorUserId,
                        ':actor_role' => $actorRole,
                        ':action' => $action,
                        ':target_id' => $incident['incident_reference'],
                        ':ip_hash' => $ipHash,
                        ':meta' => json_encode([
                            'incident_id' => $incidentId,
                            'status_from' => $currentStatus,
                            'status_to' => $newStatus,
                            'reason' => $cleanReason,
                            'role' => $actorRole
                        ])
                    ]);

            // Recalculate priority score
            PriorityScorer::updateIncidentPriority($incidentId, $pdo);

            if ($manageTx) {
                $pdo->commit();
            }

            return [
                'success' => true,
                'message' => "Incident status successfully updated from '{$currentStatus}' to '{$newStatus}'."
            ];
        } catch (Throwable $e) {
            if ($manageTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @error_log('[Status Transition Error] ' . $e->getMessage());
            return ['success' => false, 'message' => 'A database error occurred during status transition.'];
        }
    }

    /**
     * Dedicated officer status transition method.
     *
     * @param int      $incidentId Target incident ID
     * @param string   $newStatus  Requested status ('in_progress' or 'resolved')
     * @param int      $officerId  Authenticated officer user ID
     * @param string   $reason     Descriptive transition notes (>= 5 chars)
     * @param PDO|null $pdo        Active PDO instance
     * @return array [success => bool, message => string]
     */
    public static function updateOfficerStatus(
        int $incidentId,
        string $newStatus,
        int $officerId,
        string $reason,
        ?PDO $pdo = null
    ): array {
        return self::updateStatus($incidentId, $newStatus, $officerId, $reason, $pdo, 'officer');
    }

    /**
     * Submit officer resolution evidence with photo attachment, field notes, and optional inspection GPS.
     * Transitions incident and assignment from 'in_progress' to 'resolution_submitted'.
     *
     * @param int        $incidentId    Target incident ID
     * @param int        $officerId     Authenticated officer user ID
     * @param array      $file          Uploaded file entry from $_FILES
     * @param string     $notes         Descriptive field resolution notes (min 10 chars)
     * @param float|null $inspectionLat Optional field GPS latitude
     * @param float|null $inspectionLng Optional field GPS longitude
     * @param PDO|null   $pdo           Active PDO instance
     * @return array [success => bool, message => string, ?evidence_id => int]
     */
    public static function submitResolutionEvidence(
        int $incidentId,
        int $officerId,
        array $file,
        string $notes,
        ?float $inspectionLat = null,
        ?float $inspectionLng = null,
        ?PDO $pdo = null
    ): array {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $cleanNotes = trim($notes);
        if (strlen($cleanNotes) < 10) {
            return [
                'success' => false,
                'message' => 'Descriptive field resolution notes of at least 10 characters are required.'
            ];
        }

        if ($inspectionLat !== null && ($inspectionLat < -90.0 || $inspectionLat > 90.0)) {
            return ['success' => false, 'message' => 'Invalid inspection latitude.'];
        }
        if ($inspectionLng !== null && ($inspectionLng < -180.0 || $inspectionLng > 180.0)) {
            return ['success' => false, 'message' => 'Invalid inspection longitude.'];
        }

        $manageTx = !$pdo->inTransaction();
        if ($manageTx) {
            $pdo->beginTransaction();
        }

        $uploadedAbsPath = null;
        try {
            // 1. Fetch incident with row lock
            $incStmt = $pdo->prepare('SELECT id, incident_reference, status, category_id FROM incidents WHERE id = :id FOR UPDATE');
            $incStmt->execute([':id' => $incidentId]);
            $incident = $incStmt->fetch();

            if (!$incident) {
                if ($manageTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['success' => false, 'message' => 'Incident record not found.'];
            }

            if ($incident['status'] !== 'in_progress') {
                if ($manageTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'message' => "Incident must be 'in_progress' to submit resolution evidence (currently '{$incident['status']}')."
                ];
            }

            // 2. Verify active assignment for this officer
            $assignStmt = $pdo->prepare(
                "SELECT id, assignment_status 
                 FROM incident_assignments 
                 WHERE incident_id = :inc_id AND officer_id = :off_id 
                   AND assignment_status NOT IN ('completed', 'cancelled', 'reassigned')
                 ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $assignStmt->execute([':inc_id' => $incidentId, ':off_id' => $officerId]);
            $assignment = $assignStmt->fetch();

            if (!$assignment) {
                if ($manageTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'message' => 'This incident is not actively assigned to your officer account.'
                ];
            }

            // 3. Process image upload via Uploader
            $uploadResult = Uploader::processImage($file);
            if (!$uploadResult['success']) {
                // upload failed – rollback and return error
                if ($manageTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'message' => $uploadResult['error'] ?? 'Image upload failed.'
                ];
            }
            // Extract uploader response (data nested under ['data'])
            $img = $uploadResult['data'];
            // Absolute path needed for possible cleanup on later DB failure
            $uploadedAbsPath = $img['absolute_path'] ?? null;

            // 4. Record resolution evidence
            $evStmt = $pdo->prepare(
                'INSERT INTO resolution_evidence
                 (incident_id, assignment_id, officer_id, photo_path, photo_mime,
                  photo_size_bytes, photo_sha256, evidence_notes,
                  inspection_lat, inspection_lng, verification_status, created_at)
                 VALUES
                 (:inc_id, :assign_id, :off_id, :path, :mime, :size, :sha,
                  :notes, :lat, :lng, \'pending_review\', NOW())'
            );

            $evStmt->execute([
                ':inc_id'    => $incidentId,
                ':assign_id' => $assignment['id'],
                ':off_id'    => $officerId,
                ':path'      => $img['file_path'],
                ':mime'      => $img['file_mime'],
                ':size'      => $img['file_size_bytes'],
                ':sha'       => $img['sha256_hash'],
                ':notes'     => $cleanNotes,
                ':lat'       => $inspectionLat,
                ':lng'       => $inspectionLng,
            ]);

            $evidenceId = (int) $pdo->lastInsertId();


            // 5. Update incident status to resolution_submitted
            $pdo->prepare(
                "UPDATE incidents 
                 SET status = 'resolution_submitted', updated_at = NOW() 
                 WHERE id = :id"
            )->execute([':id' => $incidentId]);

            // 6. Update assignment status to resolution_submitted
            $pdo->prepare(
                "UPDATE incident_assignments 
                 SET assignment_status = 'resolution_submitted', updated_at = NOW() 
                 WHERE id = :id"
            )->execute([':id' => $assignment['id']]);

            // 7. Status history entry
            $pdo->prepare(
                'INSERT INTO incident_status_history 
                 (incident_id, changed_by_user_id, status_from, status_to, reason, is_public, created_at)
                 VALUES (:inc_id, :actor_id, \'in_progress\', \'resolution_submitted\', :reason, 1, NOW())'
            )->execute([
                        ':inc_id' => $incidentId,
                        ':actor_id' => $officerId,
                        ':reason' => 'Field remediation completed; resolution evidence submitted for administrative verification. ' . $cleanNotes
                    ]);

            // 8. Audit log entry
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ipHash = hash('sha256', $clientIp . (string) Env::get('CSRF_SECRET_KEY', 'civicshield_salt'));
            $pdo->prepare(
                'INSERT INTO audit_logs 
                 (actor_user_id, actor_role, action, target_type, target_id, ip_hash, metadata_json, created_at)
                 VALUES (:actor_id, \'officer\', \'RESOLUTION_EVIDENCE_SUBMITTED\', \'incident\', :target_id, :ip_hash, :meta, NOW())'
            )->execute([
                        ':actor_id' => $officerId,
                        ':target_id' => $incident['incident_reference'],
                        ':ip_hash' => $ipHash,
                        ':meta' => json_encode([
                            'incident_id' => $incidentId,
                            'evidence_id' => $evidenceId,
                            'photo_sha256' => $img['sha256_hash'],
                            'notes' => $cleanNotes,
                            'has_inspection_gps' => ($inspectionLat !== null && $inspectionLng !== null)
                        ])
                    ]);

            // 9. Recalculate priority
            PriorityScorer::updateIncidentPriority($incidentId, $pdo);

            if ($manageTx) {
                $pdo->commit();
            }

            return [
                'success' => true,
                'evidence_id' => $evidenceId,
                'message' => 'Resolution evidence submitted successfully. Awaiting administrative verification.'
            ];
        } catch (Throwable $e) {
            // Roll back DB transaction if we started one
            if ($manageTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Clean up the uploaded file if it exists and the upload succeeded earlier
            if (!empty($uploadedAbsPath)) {
                Uploader::cleanup($uploadedAbsPath);
            }
            @error_log('[Resolution Evidence Error] ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while submitting resolution evidence: ' . $e->getMessage()];
        }
    }

    /**
     * Administratively review and verify or reject/rework resolution evidence.
     * Approving moves incident to 'resolved' and completes the assignment.
     * Requesting rework moves incident and assignment back to 'in_progress'.
     *
     * @param int      $incidentId  Target incident ID
     * @param int      $adminUserId Authenticated admin user ID
     * @param string   $decision    'approve' or 'request_rework'
     * @param string   $reviewNotes Review feedback / verification notes (min 5 chars)
     * @param PDO|null $pdo         Active PDO instance
     * @return array [success => bool, message => string]
     */
    public static function reviewResolutionEvidence(
        int $incidentId,
        int $adminUserId,
        string $decision,
        string $reviewNotes,
        ?PDO $pdo = null
    ): array {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $cleanDecision = trim(strtolower($decision));
        if (!in_array($cleanDecision, ['approve', 'request_rework'], true)) {
            return ['success' => false, 'message' => "Invalid review decision. Must be 'approve' or 'request_rework'."];
        }

        $cleanNotes = trim($reviewNotes);
        if (strlen($cleanNotes) < 5) {
            return ['success' => false, 'message' => 'A descriptive verification note of at least 5 characters is required.'];
        }

        $manageTx = !$pdo->inTransaction();
        if ($manageTx) {
            $pdo->beginTransaction();
        }

        try {
            // 1. Fetch incident with row lock
            $incStmt = $pdo->prepare('SELECT id, incident_reference, status, category_id FROM incidents WHERE id = :id FOR UPDATE');
            $incStmt->execute([':id' => $incidentId]);
            $incident = $incStmt->fetch();

            if (!$incident) {
                if ($manageTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['success' => false, 'message' => 'Incident record not found.'];
            }

            if ($incident['status'] !== 'resolution_submitted') {
                if ($manageTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'message' => "Incident status must be 'resolution_submitted' to verify evidence (currently '{$incident['status']}')."
                ];
            }

            // 2. Fetch pending resolution evidence
            $evStmt = $pdo->prepare(
                "SELECT id, photo_path, officer_id 
                 FROM resolution_evidence 
                 WHERE incident_id = :id AND verification_status = 'pending_review' 
                 ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $evStmt->execute([':id' => $incidentId]);
            $evidence = $evStmt->fetch();

            if (!$evidence) {
                if ($manageTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['success' => false, 'message' => 'No pending resolution evidence found to verify.'];
            }

            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ipHash = hash('sha256', $clientIp . (string) Env::get('CSRF_SECRET_KEY', 'civicshield_salt'));

            if ($cleanDecision === 'approve') {
                // Update evidence record
                $pdo->prepare(
                    "UPDATE resolution_evidence 
                     SET verification_status = 'verified', 
                         verified_by_user_id = :admin_id, 
                         verification_notes = :notes, 
                         verified_at = NOW() 
                     WHERE id = :eid"
                )->execute([
                            ':admin_id' => $adminUserId,
                            ':notes' => $cleanNotes,
                            ':eid' => $evidence['id']
                        ]);

                // Update incident to resolved
                $pdo->prepare(
                    "UPDATE incidents 
                     SET status = 'resolved', resolved_at = NOW(), updated_at = NOW() 
                     WHERE id = :id"
                )->execute([':id' => $incidentId]);

                // Complete active assignment
                $pdo->prepare(
                    "UPDATE incident_assignments 
                     SET assignment_status = 'completed', completed_at = NOW(), updated_at = NOW() 
                     WHERE incident_id = :id AND assignment_status NOT IN ('completed', 'cancelled', 'reassigned')"
                )->execute([':id' => $incidentId]);

                // Status history
                $pdo->prepare(
                    'INSERT INTO incident_status_history 
                     (incident_id, changed_by_user_id, status_from, status_to, reason, is_public, created_at)
                     VALUES (:inc_id, :actor_id, \'resolution_submitted\', \'resolved\', :reason, 1, NOW())'
                )->execute([
                            ':inc_id' => $incidentId,
                            ':actor_id' => $adminUserId,
                            ':reason' => 'Resolution evidence verified and approved by municipal administration. ' . $cleanNotes
                        ]);

                // Audit log
                $pdo->prepare(
                    'INSERT INTO audit_logs 
                     (actor_user_id, actor_role, action, target_type, target_id, ip_hash, metadata_json, created_at)
                     VALUES (:actor_id, \'admin\', \'RESOLUTION_EVIDENCE_APPROVED\', \'incident\', :target_id, :ip_hash, :meta, NOW())'
                )->execute([
                            ':actor_id' => $adminUserId,
                            ':target_id' => $incident['incident_reference'],
                            ':ip_hash' => $ipHash,
                            ':meta' => json_encode([
                                'incident_id' => $incidentId,
                                'evidence_id' => $evidence['id'],
                                'decision' => 'approved',
                                'notes' => $cleanNotes
                            ])
                        ]);

                $successMsg = 'Resolution evidence verified and approved. Incident is now marked as Resolved.';
            } else {
                // request_rework
                $pdo->prepare(
                    "UPDATE resolution_evidence 
                     SET verification_status = 'rework_requested', 
                         verified_by_user_id = :admin_id, 
                         verification_notes = :notes, 
                         verified_at = NOW() 
                     WHERE id = :eid"
                )->execute([
                            ':admin_id' => $adminUserId,
                            ':notes' => $cleanNotes,
                            ':eid' => $evidence['id']
                        ]);

                // Update incident back to in_progress
                $pdo->prepare(
                    "UPDATE incidents 
                     SET status = 'in_progress', updated_at = NOW() 
                     WHERE id = :id"
                )->execute([':id' => $incidentId]);

                // Reopen assignment to in_progress
                $pdo->prepare(
                    "UPDATE incident_assignments 
                     SET assignment_status = 'in_progress', updated_at = NOW() 
                     WHERE incident_id = :id AND assignment_status NOT IN ('completed', 'cancelled', 'reassigned')"
                )->execute([':id' => $incidentId]);

                // Status history
                $pdo->prepare(
                    'INSERT INTO incident_status_history 
                     (incident_id, changed_by_user_id, status_from, status_to, reason, is_public, created_at)
                     VALUES (:inc_id, :actor_id, \'resolution_submitted\', \'in_progress\', :reason, 1, NOW())'
                )->execute([
                            ':inc_id' => $incidentId,
                            ':actor_id' => $adminUserId,
                            ':reason' => 'Rework requested on resolution evidence: ' . $cleanNotes
                        ]);

                // Audit log
                $pdo->prepare(
                    'INSERT INTO audit_logs 
                     (actor_user_id, actor_role, action, target_type, target_id, ip_hash, metadata_json, created_at)
                     VALUES (:actor_id, \'admin\', \'RESOLUTION_EVIDENCE_REWORK_REQUESTED\', \'incident\', :target_id, :ip_hash, :meta, NOW())'
                )->execute([
                            ':actor_id' => $adminUserId,
                            ':target_id' => $incident['incident_reference'],
                            ':ip_hash' => $ipHash,
                            ':meta' => json_encode([
                                'incident_id' => $incidentId,
                                'evidence_id' => $evidence['id'],
                                'decision' => 'rework_requested',
                                'notes' => $cleanNotes
                            ])
                        ]);

                $successMsg = 'Rework requested. Incident returned to in-progress queue for field officer remediation.';
            }

            // Recalculate priority
            PriorityScorer::updateIncidentPriority($incidentId, $pdo);

            if ($manageTx) {
                $pdo->commit();
            }

            return ['success' => true, 'message' => $successMsg];
        } catch (Throwable $e) {
            if ($manageTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @error_log('[Resolution Review Error] ' . $e->getMessage());
            return ['success' => false, 'message' => 'An error occurred while reviewing resolution evidence: ' . $e->getMessage()];
        }
    }

    /**
     * Retrieve all resolution evidence records for an incident.
     *
     * @param int      $incidentId
     * @param PDO|null $pdo
     * @return array
     */
    public static function getResolutionEvidence(int $incidentId, ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }


        $stmt = $pdo->prepare(
            "SELECT re.*,
            u_off.username AS officer_username,
            u_off.username AS officer_full_name,
            u_adm.username AS verifier_username,
            u_adm.username AS verifier_full_name
     FROM resolution_evidence re
     JOIN users u_off ON u_off.id = re.officer_id
     LEFT JOIN users u_adm ON u_adm.id = re.verified_by_user_id
     WHERE re.incident_id = :inc_id
     ORDER BY re.id DESC"
        );

        $stmt->execute([':inc_id' => $incidentId]);
        return $stmt->fetchAll();
    }

    /**
     * Retrieve aggregate KPI metrics for an officer's personal dispatch queue.
     *
     * @param int      $officerId Authenticated officer user ID
     * @param PDO|null $pdo       Active PDO instance
     * @return array
     */
    public static function getOfficerDashboardStats(int $officerId, ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        // 1. Total currently assigned incidents (active work orders)
        $stmt1 = $pdo->prepare(
            "SELECT COUNT(DISTINCT i.id) 
             FROM incidents i 
             JOIN incident_assignments a ON a.incident_id = i.id 
             WHERE a.officer_id = :off_id 
               AND a.assignment_status NOT IN ('completed', 'cancelled', 'reassigned')"
        );
        $stmt1->execute([':off_id' => $officerId]);
        $totalAssigned = (int) $stmt1->fetchColumn();

        // 2. Requiring immediate action (active assignment, incident status = 'assigned')
        $stmt2 = $pdo->prepare(
            "SELECT COUNT(DISTINCT i.id) 
             FROM incidents i 
             JOIN incident_assignments a ON a.incident_id = i.id 
             WHERE a.officer_id = :off_id 
               AND a.assignment_status NOT IN ('completed', 'cancelled', 'reassigned')
               AND i.status = 'assigned'"
        );
        $stmt2->execute([':off_id' => $officerId]);
        $actionRequired = (int) $stmt2->fetchColumn();

        // 3. Currently in progress
        $stmt3 = $pdo->prepare(
            "SELECT COUNT(DISTINCT i.id) 
             FROM incidents i 
             JOIN incident_assignments a ON a.incident_id = i.id 
             WHERE a.officer_id = :off_id 
               AND a.assignment_status NOT IN ('completed', 'cancelled', 'reassigned')
               AND i.status = 'in_progress'"
        );
        $stmt3->execute([':off_id' => $officerId]);
        $inProgress = (int) $stmt3->fetchColumn();

        // 4. Recently resolved
        $stmt4 = $pdo->prepare(
            "SELECT COUNT(DISTINCT i.id) 
             FROM incidents i 
             JOIN incident_assignments a ON a.incident_id = i.id 
             WHERE a.officer_id = :off_id 
               AND (i.status = 'resolved' OR a.assignment_status = 'completed')"
        );
        $stmt4->execute([':off_id' => $officerId]);
        $recentlyResolved = (int) $stmt4->fetchColumn();

        return [
            'total_assigned' => $totalAssigned,
            'action_required' => $actionRequired,
            'in_progress' => $inProgress,
            'recently_resolved' => $recentlyResolved
        ];
    }

    /**
     * Retrieve paginated incidents assigned to a specific officer.
     *
     * @param int      $officerId Authenticated officer user ID
     * @param array    $filters   Filter criteria (status, category_id, min_priority, search, view)
     * @param int      $page      Page number (1-indexed)
     * @param int      $perPage   Records per page (clamped between 5 and 50)
     * @param PDO|null $pdo       Active PDO instance
     * @return array [incidents => array, pagination => array]
     */
    public static function getOfficerIncidents(
        int $officerId,
        array $filters = [],
        int $page = 1,
        int $perPage = 10,
        ?PDO $pdo = null
    ): array {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $page = max(1, $page);
        $perPage = max(5, min(50, $perPage));

        $whereClauses = ['a.officer_id = :officer_id'];
        $params = [':officer_id' => $officerId];

        // View filter: active (default), history, or all
        $view = $filters['view'] ?? 'active';
        if ($view === 'history') {
            $whereClauses[] = "a.assignment_status IN ('completed', 'cancelled', 'reassigned')";
        } elseif ($view !== 'all') {
            $whereClauses[] = "a.assignment_status NOT IN ('completed', 'cancelled', 'reassigned')";
        }

        // Status filter
        $validStatuses = ['reported', 'triaged', 'assigned', 'in_progress', 'resolution_submitted', 'resolved', 'closed', 'rejected'];
        if (!empty($filters['status']) && in_array($filters['status'], $validStatuses, true)) {
            $whereClauses[] = 'i.status = :status';
            $params[':status'] = $filters['status'];
        }

        // Category filter
        if (!empty($filters['category_id']) && is_numeric($filters['category_id']) && (int) $filters['category_id'] > 0) {
            $whereClauses[] = 'i.category_id = :category_id';
            $params[':category_id'] = (int) $filters['category_id'];
        }

        // Priority filter
        if (isset($filters['min_priority']) && is_numeric($filters['min_priority']) && (int) $filters['min_priority'] > 0) {
            $minPri = max(0, min(100, (int) $filters['min_priority']));
            $whereClauses[] = 'i.priority_score >= :min_priority';
            $params[':min_priority'] = $minPri;
        }

        // Search filter (incident reference or title)
        if (!empty($filters['search'])) {
            $searchTerm = trim((string) $filters['search']);
            if ($searchTerm !== '') {
                $whereClauses[] = '(i.incident_reference LIKE :search_ref OR i.title LIKE :search_title)';
                $params[':search_ref'] = '%' . $searchTerm . '%';
                $params[':search_title'] = '%' . $searchTerm . '%';
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $whereClauses);

        // Count total matching records
        $countSql = "SELECT COUNT(*) FROM incidents i JOIN incident_assignments a ON a.incident_id = i.id {$whereSql}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        if ($page > $totalPages && $totalRecords > 0) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT i.id, i.incident_reference, i.category_id, i.title, i.description,
                       i.severity, i.priority_score, i.priority_breakdown, i.status,
                       i.neighborhood, i.city, i.reports_count, i.created_at, i.updated_at,
                       i.resolved_at, i.closed_at,
                       c.name AS category_name, c.icon AS category_icon, c.resolution_sla_hours,
                       a.id AS assignment_id, a.assignment_status, a.instructions AS assignment_instructions,
                       a.assigned_at, a.acknowledged_at, a.completed_at
                FROM incidents i
                JOIN incident_assignments a ON a.incident_id = i.id
                JOIN incident_categories c ON c.id = i.category_id
                {$whereSql}
                ORDER BY i.priority_score DESC, a.assigned_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $incidents = $stmt->fetchAll();
        foreach ($incidents as &$inc) {
            if (!empty($inc['priority_breakdown'])) {
                $inc['priority_breakdown'] = json_decode((string) $inc['priority_breakdown'], true);
            }
        }
        unset($inc);

        return [
            'incidents' => $incidents,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages,
                'has_previous' => $page > 1,
                'has_next' => $page < $totalPages
            ]
        ];
    }

    /**
     * Retrieve incident details for an officer, strictly verifying assignment.
     *
     * @param int      $incidentId Target incident ID
     * @param int      $officerId  Authenticated officer user ID
     * @param PDO|null $pdo        Active PDO instance
     * @return array|null Null if incident is not assigned to officer or not found
     */
    public static function getOfficerIncidentDetail(int $incidentId, int $officerId, ?PDO $pdo = null): ?array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        // 1. Verify officer has an assignment for this incident
        $assignStmt = $pdo->prepare(
            "SELECT a.*, adm.username AS assigned_by_username 
             FROM incident_assignments a
             JOIN users adm ON adm.id = a.assigned_by_user_id
             WHERE a.incident_id = :inc_id AND a.officer_id = :off_id
             ORDER BY a.id DESC LIMIT 1"
        );
        $assignStmt->execute([':inc_id' => $incidentId, ':off_id' => $officerId]);
        $assignment = $assignStmt->fetch();

        if (!$assignment) {
            return null;
        }

        // 2. Fetch incident details
        $incStmt = $pdo->prepare(
            "SELECT i.*, 
                    c.name AS category_name, c.icon AS category_icon, 
                    c.description AS category_description, c.resolution_sla_hours
             FROM incidents i
             JOIN incident_categories c ON c.id = i.category_id
             WHERE i.id = :id
             LIMIT 1"
        );
        $incStmt->execute([':id' => $incidentId]);
        $incident = $incStmt->fetch();

        if (!$incident) {
            return null;
        }

        if (!empty($incident['priority_breakdown'])) {
            $incident['priority_breakdown'] = json_decode((string) $incident['priority_breakdown'], true);
        }

        $incident['current_assignment'] = $assignment;
        $incident['is_active_assignment'] = !in_array($assignment['assignment_status'], ['completed', 'cancelled', 'reassigned'], true);

        return $incident;
    }

    /**
     * Quick check whether an incident is assigned to an officer.
     *
     * @param int      $incidentId
     * @param int      $officerId
     * @param bool     $activeOnly
     * @param PDO|null $pdo
     * @return bool
     */
    public static function isIncidentAssignedToOfficer(
        int $incidentId,
        int $officerId,
        bool $activeOnly = false,
        ?PDO $pdo = null
    ): bool {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $activeClause = $activeOnly ? "AND assignment_status NOT IN ('completed', 'cancelled', 'reassigned')" : "";
        $stmt = $pdo->prepare(
            "SELECT 1 FROM incident_assignments 
             WHERE incident_id = :inc_id AND officer_id = :off_id {$activeClause} 
             LIMIT 1"
        );
        $stmt->execute([':inc_id' => $incidentId, ':off_id' => $officerId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Retrieve recent activity stream for an officer.
     *
     * @param int      $officerId Authenticated officer user ID
     * @param int      $limit     Maximum records
     * @param PDO|null $pdo       Active PDO instance
     * @return array
     */
    public static function getOfficerActivity(int $officerId, int $limit = 20, ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $limit = max(1, min(100, $limit));

        $sql = "SELECT h.id, 'status_transition' AS activity_type, h.incident_id,
                       i.incident_reference, i.title AS incident_title,
                       h.status_from, h.status_to, h.reason AS notes,
                       h.created_at AS activity_time
                FROM incident_status_history h
                JOIN incidents i ON i.id = h.incident_id
                WHERE h.changed_by_user_id = :off_id
                ORDER BY h.created_at DESC
                LIMIT :limit";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':off_id', $officerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Promote an unlinked or standalone citizen intake report into a canonical consolidated incident.
     * Applies Differential Geo-Obfuscation (approx. 150m noise boundary), initial priority scoring,
     * status history recording, and audit logging.
     *
     * @param int      $reportId    ID of the incident_report record
     * @param PDO|null $pdo         Active PDO instance
     * @param int|null $actorUserId ID of user performing triage (null for automatic system intake)
     * @return array [success => bool, message => string, incident_id => ?int, incident_reference => ?string]
     */
    public static function createIncidentFromReport(int $reportId, ?PDO $pdo = null, ?int $actorUserId = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $ownTransaction = false;

        try {
            // 1. Fetch the report record
            $stmt = $pdo->prepare(
                'SELECT r.*, c.name AS category_name, c.resolution_sla_hours, c.default_severity
                 FROM incident_reports r
                 JOIN incident_categories c ON c.id = r.category_id
                 WHERE r.id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => $reportId]);
            $report = $stmt->fetch();

            if (!$report) {
                return ['success' => false, 'message' => 'Incident report not found.'];
            }

            if (!empty($report['incident_id'])) {
                return [
                    'success' => false,
                    'message' => 'Report is already consolidated into an incident.',
                    'incident_id' => (int) $report['incident_id']
                ];
            }

            $ownTransaction = !$pdo->inTransaction();
            if ($ownTransaction) {
                $pdo->beginTransaction();
            }

            // 2. Generate canonical incident reference: CS-SRT-YYYY-XXXX
            $incidentRef = sprintf('CS-SRT-%s-%s', date('Y'), strtoupper(bin2hex(random_bytes(3))));

            // 3. Compute Differential Geo-Obfuscation (~150m bounded fuzzing radius)
            $fuzzRadius = (int) Env::get('GEO_FUZZ_RADIUS_METERS', 150);
            if ($report['raw_lat'] !== null && $report['raw_lng'] !== null) {
                $rawLat = (float) $report['raw_lat'];
                $rawLng = (float) $report['raw_lng'];

                // Bounded pseudorandom displacement
                $angle = (mt_rand(0, 36000) / 100.0) * (M_PI / 180.0);
                $distanceMeters = mt_rand((int) ($fuzzRadius * 0.35), $fuzzRadius);
                $deltaLat = ($distanceMeters * cos($angle)) / 111320.0;
                $deltaLng = ($distanceMeters * sin($angle)) / (111320.0 * max(0.1, cos(deg2rad($rawLat))));

                $obfLat = round($rawLat + $deltaLat, 8);
                $obfLng = round($rawLng + $deltaLng, 8);
            } else {
                // Surat Municipal Center default
                $obfLat = 21.17020000;
                $obfLng = 72.83110000;
            }

            // 4. Determine neighborhood locality
            $neighborhood = 'Surat Municipal Jurisdiction';
            if (!empty($report['location_description'])) {
                $loc = trim((string) $report['location_description']);
                if (str_contains($loc, ',')) {
                    $parts = array_map('trim', explode(',', $loc));
                    $neighborhood = end($parts) ?: $loc;
                } else {
                    $neighborhood = $loc;
                }
                if (mb_strlen($neighborhood) > 140) {
                    $neighborhood = mb_substr($neighborhood, 0, 140) . '...';
                }
            }

            // 5. Compute initial priority score
            $severity = $report['submitted_severity'] ?: ($report['default_severity'] ?? 'medium');
            $slaHours = (int) ($report['resolution_sla_hours'] ?? 24);
            $priResult = PriorityScorer::computeScore($severity, 1, 0.0, $slaHours);
            $priorityScore = $priResult['score'];
            $priorityBreakdown = json_encode($priResult['breakdown']);

            // 6. Insert new canonical incident
            $incStmt = $pdo->prepare(
                'INSERT INTO incidents 
                 (incident_reference, category_id, title, description, severity, priority_score, priority_breakdown,
                  status, obfuscated_lat, obfuscated_lng, fuzz_radius_meters, neighborhood, city, reports_count, created_at, updated_at)
                 VALUES
                 (:ref, :cat_id, :title, :description, :sev, :pri_score, :pri_breakdown,
                  \'reported\', :lat, :lng, :fuzz_radius, :neighborhood, \'Surat\', 1, NOW(), NOW())'
            );
            $incStmt->execute([
                ':ref' => $incidentRef,
                ':cat_id' => $report['category_id'],
                ':title' => $report['title'],
                ':description' => $report['description'],
                ':sev' => $severity,
                ':pri_score' => $priorityScore,
                ':pri_breakdown' => $priorityBreakdown,
                ':lat' => $obfLat,
                ':lng' => $obfLng,
                ':fuzz_radius' => $fuzzRadius,
                ':neighborhood' => $neighborhood
            ]);
            $incidentId = (int) $pdo->lastInsertId();

            // 7. Link report to this consolidated incident
            $upRepStmt = $pdo->prepare(
                'UPDATE incident_reports 
                 SET incident_id = :inc_id, status = \'standalone\'
                 WHERE id = :rep_id'
            );
            $upRepStmt->execute([
                ':inc_id' => $incidentId,
                ':rep_id' => $reportId
            ]);

            // 8. Record initial status history
            $histStmt = $pdo->prepare(
                'INSERT INTO incident_status_history 
                 (incident_id, changed_by_user_id, status_from, status_to, reason, is_public, created_at)
                 VALUES (:inc_id, :actor_id, \'intake\', \'reported\', :reason, 1, NOW())'
            );
            $histStmt->execute([
                ':inc_id' => $incidentId,
                ':actor_id' => $actorUserId,
                ':reason' => sprintf('Created standalone municipal incident from citizen intake report %s.', $report['report_reference'])
            ]);

            // 9. Record audit log
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ipHash = hash('sha256', $clientIp . (string) Env::get('CSRF_SECRET_KEY', 'civicshield_salt'));
            $actorRole = $actorUserId ? 'admin' : 'system';

            $auditStmt = $pdo->prepare(
                'INSERT INTO audit_logs 
                 (actor_user_id, actor_role, action, target_type, target_id, ip_hash, metadata_json, created_at)
                 VALUES (:actor_id, :actor_role, \'INCIDENT_CREATED_FROM_REPORT\', \'incident\', :target_id, :ip_hash, :meta, NOW())'
            );
            $auditStmt->execute([
                ':actor_id' => $actorUserId,
                ':actor_role' => $actorRole,
                ':target_id' => (string) $incidentId,
                ':ip_hash' => $ipHash,
                ':meta' => json_encode([
                    'report_id' => $reportId,
                    'report_reference' => $report['report_reference'],
                    'incident_reference' => $incidentRef,
                    'priority_score' => $priorityScore,
                    'fuzz_radius_meters' => $fuzzRadius
                ])
            ]);

            if ($ownTransaction) {
                $pdo->commit();
            }

            return [
                'success' => true,
                'message' => sprintf('Incident %s created successfully from report %s.', $incidentRef, $report['report_reference']),
                'incident_id' => $incidentId,
                'incident_reference' => $incidentRef
            ];

        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @error_log('[Incident Creation From Report Error] ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to promote report into canonical incident: ' . $e->getMessage()
            ];
        }
    }
}
