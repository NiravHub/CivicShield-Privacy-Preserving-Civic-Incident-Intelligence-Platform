<?php
declare(strict_types=1);

/**
 * CivicShield - Duplicate Incident Candidate Detection Engine
 *
 * Evaluates submitted citizen reports against existing consolidated civic incidents
 * using four multi-dimensional metrics:
 * 1. Geodesic Proximity: Haversine distance within a configurable radius (Default: 50 meters, 40% weight)
 * 2. Category Match: Exact category alignment (30% weight)
 * 3. Textual Similarity: Tokenized Jaccard overlap + lexical similarity (20% weight)
 * 4. Temporal Proximity: Submission timestamp proximity decay (10% weight)
 *
 * Human-in-the-Loop Principle:
 * High match scores NEVER automatically merge incidents. Candidates are queued for
 * explicit administrator review with an immutable audit log.
 */
class DuplicateDetector
{
    // Default Candidate Radius (in meters)
    public const DEFAULT_RADIUS_METERS = 50.0;

    // Component Weights (Sum = 1.00)
    public const WEIGHT_SPATIAL  = 0.40;
    public const WEIGHT_CATEGORY = 0.30;
    public const WEIGHT_TEXT     = 0.20;
    public const WEIGHT_TIME     = 0.10;

    /**
     * Compute Great-Circle Geodesic Distance between two coordinate pairs using Haversine formula.
     *
     * @param float $lat1 Latitude of point 1 in degrees
     * @param float $lng1 Longitude of point 1 in degrees
     * @param float $lat2 Latitude of point 2 in degrees
     * @param float $lng2 Longitude of point 2 in degrees
     * @return float Distance in meters
     */
    public static function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0; // Mean Earth radius in meters

        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = sin($deltaPhi / 2.0) ** 2 +
             cos($phi1) * cos($phi2) * (sin($deltaLambda / 2.0) ** 2);

        // Protect against floating-point inaccuracies yielding a > 1
        $a = min(1.0, max(0.0, $a));
        $c = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Normalize text for lexical and tokenized comparison.
     *
     * @param string $text
     * @return string
     */
    public static function normalizeText(string $text): string
    {
        $lower = strtolower($text);
        // Replace punctuation and non-alphanumeric characters with space
        $clean = preg_replace('/[^a-z0-9\s]/', ' ', $lower);
        // Collapse multiple whitespaces
        return trim((string)preg_replace('/\s+/', ' ', (string)$clean));
    }

    /**
     * Compute lexical & token similarity between two text strings (0.0 to 100.0%).
     *
     * @param string $text1
     * @param string $text2
     * @return float Score between 0.0 and 100.0
     */
    public static function calculateTextSimilarity(string $text1, string $text2): float
    {
        $norm1 = self::normalizeText($text1);
        $norm2 = self::normalizeText($text2);

        if ($norm1 === '' && $norm2 === '') {
            return 0.0;
        }
        if ($norm1 === '' || $norm2 === '') {
            return 0.0;
        }
        if ($norm1 === $norm2) {
            return 100.0;
        }

        // 1. Token Jaccard Similarity
        $words1 = array_unique(explode(' ', $norm1));
        $words2 = array_unique(explode(' ', $norm2));

        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        $jaccard = count($union) > 0 ? (count($intersection) / count($union)) * 100.0 : 0.0;

        // 2. Character-level Levenshtein / similar_text
        $simChars = 0.0;
        similar_text($norm1, $norm2, $simChars);

        // Blended metric: 60% Token Jaccard + 40% Character similarity
        $blended = (0.60 * $jaccard) + (0.40 * $simChars);

        return round(min(100.0, max(0.0, $blended)), 2);
    }

    /**
     * Compute time difference in minutes and corresponding time proximity score (0.0 to 100.0%).
     *
     * @param string|int $time1 Timestamp or ISO string
     * @param string|int $time2 Timestamp or ISO string
     * @return array [diff_minutes => int, score => float]
     */
    public static function calculateTimeProximity(string|int $time1, string|int $time2): array
    {
        $ts1 = is_int($time1) ? $time1 : (strtotime($time1) ?: time());
        $ts2 = is_int($time2) ? $time2 : (strtotime($time2) ?: time());

        $diffSeconds = abs($ts1 - $ts2);
        $diffMinutes = (int)round($diffSeconds / 60.0);

        // Time Decay Curve:
        // <= 2 hours (120 mins)  => 100%
        // <= 24 hours (1440 mins) => 70% - 100%
        // <= 7 days (10080 mins)  => 30% - 70%
        // <= 14 days (20160 mins) => 0% - 30%
        // > 14 days               => 0%
        if ($diffMinutes <= 120) {
            $score = 100.0;
        } elseif ($diffMinutes <= 1440) {
            $score = 100.0 - (30.0 * ($diffMinutes - 120) / (1440 - 120));
        } elseif ($diffMinutes <= 10080) {
            $score = 70.0 - (40.0 * ($diffMinutes - 1440) / (10080 - 1440));
        } elseif ($diffMinutes <= 20160) {
            $score = 30.0 - (30.0 * ($diffMinutes - 10080) / (20160 - 10080));
        } else {
            $score = 0.0;
        }

        return [
            'diff_minutes' => $diffMinutes,
            'score'        => round(min(100.0, max(0.0, $score)), 2)
        ];
    }

    /**
     * Calculate spatial proximity score (0.0 to 100.0%) within candidate radius.
     *
     * @param float $distanceMeters
     * @param float $radiusMeters
     * @return float
     */
    public static function calculateSpatialScore(float $distanceMeters, float $radiusMeters): float
    {
        if ($distanceMeters > $radiusMeters || $radiusMeters <= 0.0) {
            return 0.0;
        }
        $score = 100.0 * (1.0 - ($distanceMeters / $radiusMeters));
        return round(min(100.0, max(0.0, $score)), 2);
    }

    /**
     * Compute composite duplicate match score (0.00 to 100.00%).
     *
     * @param float $spatialScore
     * @param bool  $categoryMatched
     * @param float $textScore
     * @param float $timeScore
     * @return float
     */
    public static function computeCompositeScore(
        float $spatialScore,
        bool $categoryMatched,
        float $textScore,
        float $timeScore
    ): float {
        $catScore = $categoryMatched ? 100.0 : 0.0;

        $composite = (self::WEIGHT_SPATIAL * $spatialScore) +
                     (self::WEIGHT_CATEGORY * $catScore) +
                     (self::WEIGHT_TEXT * $textScore) +
                     (self::WEIGHT_TIME * $timeScore);

        return round(min(100.0, max(0.0, $composite)), 2);
    }

    /**
     * Evaluate a submitted report against all eligible existing incidents and return candidate matches.
     *
     * @param int        $reportId     The submitted report ID
     * @param float|null $customRadius Custom search radius in meters (defaults to ENV / 50m)
     * @param PDO|null   $pdo
     * @return array List of candidate match arrays
     */
    public static function findCandidatesForReport(int $reportId, ?float $customRadius = null, ?PDO $pdo = null): array
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $radius = $customRadius ?? (float)Env::get('DUPLICATE_CANDIDATE_RADIUS_METERS', self::DEFAULT_RADIUS_METERS);

        // 1. Fetch Report Data
        $stmt = $pdo->prepare(
            'SELECT id, report_reference, incident_id, category_id, title, description, raw_lat, raw_lng, created_at, status
             FROM incident_reports 
             WHERE id = :id 
             LIMIT 1'
        );
        $stmt->execute([':id' => $reportId]);
        $report = $stmt->fetch();

        if (!$report) {
            return [];
        }

        // 2. Safe Coordinates Validation
        // If report has missing or invalid coordinates, spatial candidates cannot be safely identified.
        if (
            $report['raw_lat'] === null ||
            $report['raw_lng'] === null ||
            !is_numeric($report['raw_lat']) ||
            !is_numeric($report['raw_lng'])
        ) {
            return [];
        }

        $repLat = (float)$report['raw_lat'];
        $repLng = (float)$report['raw_lng'];

        if ($repLat < -90.0 || $repLat > 90.0 || $repLng < -180.0 || $repLng > 180.0) {
            return [];
        }

        $reportText = $report['title'] . ' ' . $report['description'];

        // 3. Fetch Active / Non-Closed Existing Incidents
        $incStmt = $pdo->query(
            "SELECT id, incident_reference, category_id, title, description, severity, priority_score, 
                    status, obfuscated_lat, obfuscated_lng, created_at
             FROM incidents 
             WHERE status NOT IN ('closed', 'rejected')"
        );
        $incidents = $incStmt->fetchAll();

        $candidates = [];

        foreach ($incidents as $inc) {
            // If the report is already linked to this incident, do not treat it as a candidate duplicate of itself
            if (!empty($report['incident_id']) && (int)$report['incident_id'] === (int)$inc['id']) {
                continue;
            }

            // Retrieve ground coordinates for this incident:
            // Check linked reports for most accurate coordinates, with fallback to incident centroid
            $linkStmt = $pdo->prepare(
                'SELECT raw_lat, raw_lng FROM incident_reports 
                 WHERE incident_id = :inc_id AND id != :rep_id AND raw_lat IS NOT NULL AND raw_lng IS NOT NULL'
            );
            $linkStmt->execute([':inc_id' => $inc['id'], ':rep_id' => $reportId]);
            $linkedCoords = $linkStmt->fetchAll();

            $minDistance = null;

            // Test against all linked reports to find closest ground point
            foreach ($linkedCoords as $coord) {
                $cLat = (float)$coord['raw_lat'];
                $cLng = (float)$coord['raw_lng'];
                if ($cLat >= -90.0 && $cLat <= 90.0 && $cLng >= -180.0 && $cLng <= 180.0) {
                    $d = self::haversineDistance($repLat, $repLng, $cLat, $cLng);
                    if ($minDistance === null || $d < $minDistance) {
                        $minDistance = $d;
                    }
                }
            }

            // Also test against incident centroid coordinates
            if (isset($inc['obfuscated_lat'], $inc['obfuscated_lng'])) {
                $obfLat = (float)$inc['obfuscated_lat'];
                $obfLng = (float)$inc['obfuscated_lng'];
                if ($obfLat >= -90.0 && $obfLat <= 90.0 && $obfLng >= -180.0 && $obfLng <= 180.0) {
                    $d = self::haversineDistance($repLat, $repLng, $obfLat, $obfLng);
                    if ($minDistance === null || $d < $minDistance) {
                        $minDistance = $d;
                    }
                }
            }

            // If coordinates were invalid or missing for the incident, skip safely
            if ($minDistance === null) {
                continue;
            }

            // GATING CRITERION: Must be within configured candidate radius
            if ($minDistance > $radius) {
                continue;
            }

            // Calculate component metrics
            $spatialScore = self::calculateSpatialScore($minDistance, $radius);
            $categoryMatched = ((int)$report['category_id'] === (int)$inc['category_id']);

            $incText = $inc['title'] . ' ' . $inc['description'];
            $textScore = self::calculateTextSimilarity($reportText, $incText);

            $timeData = self::calculateTimeProximity($report['created_at'], $inc['created_at']);

            $matchScore = self::computeCompositeScore(
                $spatialScore,
                $categoryMatched,
                $textScore,
                $timeData['score']
            );

            $candidates[] = [
                'report_id'               => (int)$report['id'],
                'candidate_incident_id'   => (int)$inc['id'],
                'incident_reference'      => $inc['incident_reference'],
                'incident_title'          => $inc['title'],
                'match_score'             => $matchScore,
                'spatial_distance_meters' => (int)round($minDistance),
                'time_difference_minutes' => $timeData['diff_minutes'],
                'category_matched'        => $categoryMatched ? 1 : 0,
                'text_similarity_score'   => $textScore,
                'spatial_score'           => $spatialScore,
                'time_score'              => $timeData['score']
            ];
        }

        // Sort descending by match score
        usort($candidates, fn($a, $b) => $b['match_score'] <=> $a['match_score']);

        return $candidates;
    }

    /**
     * Process duplicate candidate detection for a newly filed report and persist candidate records.
     * Idempotent: Can be safely re-run without creating redundant candidate rows.
     *
     * @param int        $reportId
     * @param float|null $customRadius
     * @param PDO|null   $pdo
     * @return int Number of candidate matches persisted
     */
    public static function processReport(int $reportId, ?float $customRadius = null, ?PDO $pdo = null): int
    {
        if ($pdo === null) {
            $pdo = Database::getConnection();
        }

        $candidates = self::findCandidatesForReport($reportId, $customRadius, $pdo);

        if (empty($candidates)) {
            return 0;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO incident_duplicate_candidates 
             (report_id, candidate_incident_id, match_score, spatial_distance_meters, 
              time_difference_minutes, category_matched, text_similarity_score, review_status, created_at)
             VALUES 
             (:report_id, :candidate_id, :match_score, :distance, :time_diff, :category_matched, :text_score, \'pending_review\', NOW())
             ON DUPLICATE KEY UPDATE 
             match_score = VALUES(match_score),
             spatial_distance_meters = VALUES(spatial_distance_meters),
             time_difference_minutes = VALUES(time_difference_minutes),
             category_matched = VALUES(category_matched),
             text_similarity_score = VALUES(text_similarity_score),
             updated_at = NOW()'
        );

        $persisted = 0;
        foreach ($candidates as $cand) {
            $insertStmt->execute([
                ':report_id'         => $cand['report_id'],
                ':candidate_id'      => $cand['candidate_incident_id'],
                ':match_score'       => $cand['match_score'],
                ':distance'          => $cand['spatial_distance_meters'],
                ':time_diff'         => $cand['time_difference_minutes'],
                ':category_matched'  => $cand['category_matched'],
                ':text_score'        => $cand['text_similarity_score'],
            ]);
            $persisted++;
        }

        return $persisted;
    }
}
