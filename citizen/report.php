<?php
declare(strict_types=1);

$pageTitle = 'Report Civic Incident';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'auth_guard.php';

// Enforce authenticated citizen access
requireAuth('citizen');

$errors = [];
$formData = [
    'category_id'          => '',
    'title'                => '',
    'description'          => '',
    'submitted_severity'   => 'medium',
    'raw_lat'              => '',
    'raw_lng'              => '',
    'location_description' => '',
    'consent_followup'     => false,
    'contact_name'         => '',
    'contact_phone'        => '',
    'contact_email'        => '',
    'preferred_method'     => 'none'
];

$pdo = Database::getConnection();

// Load active categories from database
$categories = [];
try {
    $catStmt = $pdo->query(
        'SELECT id, name, slug, description, default_severity, icon, resolution_sla_hours 
         FROM incident_categories 
         WHERE is_active = 1 
         ORDER BY name ASC'
    );
    $categories = $catStmt->fetchAll();
} catch (Throwable $e) {
    @error_log('[Report Page - Category Fetch Error] ' . $e->getMessage());
    $errors[] = 'Unable to load incident categories from database.';
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validate CSRF Token
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!Session::validateCsrf($csrfToken)) {
        $errors[] = 'Security token mismatch or expired session. Please refresh and try again.';
    }

    // 2. Extract and Sanitize Inputs
    $formData['category_id']          = (int)($_POST['category_id'] ?? 0);
    $formData['title']                = trim((string)($_POST['title'] ?? ''));
    $formData['description']          = trim((string)($_POST['description'] ?? ''));
    $formData['submitted_severity']   = trim((string)($_POST['submitted_severity'] ?? 'medium'));
    $formData['raw_lat']              = trim((string)($_POST['raw_lat'] ?? $_POST['latitude'] ?? ''));
    $formData['raw_lng']              = trim((string)($_POST['raw_lng'] ?? $_POST['longitude'] ?? ''));
    $formData['location_description'] = trim((string)($_POST['location_description'] ?? ''));
    
    $formData['consent_followup']     = !empty($_POST['consent_followup']);
    $formData['contact_name']         = trim((string)($_POST['contact_name'] ?? ''));
    $formData['contact_phone']        = trim((string)($_POST['contact_phone'] ?? ''));
    $formData['contact_email']        = trim((string)($_POST['contact_email'] ?? ''));
    $formData['preferred_method']     = trim((string)($_POST['preferred_method'] ?? 'none'));

    // 3. Validate Category
    $validCategoryIds = array_column($categories, 'id');
    if (!in_array($formData['category_id'], $validCategoryIds, true)) {
        $errors[] = 'Please select a valid civic incident category.';
    }

    // 4. Validate Title & Description
    if (strlen($formData['title']) < 5 || strlen($formData['title']) > 200) {
        $errors[] = 'Incident title must be between 5 and 200 characters.';
    }

    if (strlen($formData['description']) < 10 || strlen($formData['description']) > 3000) {
        $errors[] = 'Incident description must be between 10 and 3000 characters.';
    }

    // 5. Validate Severity
    $allowedSeverities = ['low', 'medium', 'high', 'critical'];
    if (!in_array($formData['submitted_severity'], $allowedSeverities, true)) {
        $formData['submitted_severity'] = 'medium';
    }

    // 6. Validate Coordinates & Location
    $latFloat = null;
    $lngFloat = null;
    if ($formData['raw_lat'] !== '' || $formData['raw_lng'] !== '') {
        if (!is_numeric($formData['raw_lat']) || !is_numeric($formData['raw_lng'])) {
            $errors[] = 'Incident coordinates must be valid numbers.';
        } else {
            $latFloat = (float)$formData['raw_lat'];
            $lngFloat = (float)$formData['raw_lng'];
            if ($latFloat < -90.0 || $latFloat > 90.0 || $lngFloat < -180.0 || $lngFloat > 180.0) {
                $errors[] = 'Latitude must be between -90 and 90, and longitude between -180 and 180.';
            }
        }
    }

    if ($latFloat === null && $lngFloat === null && $formData['location_description'] === '') {
        $errors[] = 'Please pin the incident location on the map or provide a landmark description.';
    }

    // 7. Validate Optional Contact Consent Details
    if ($formData['consent_followup']) {
        if ($formData['contact_email'] !== '' && !filter_var($formData['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid emergency follow-up email address or leave it blank.';
        }
        if ($formData['contact_phone'] !== '' && !preg_match('/^[0-9+\s\-()]{7,25}$/', $formData['contact_phone'])) {
            $errors[] = 'Please enter a valid phone number format.';
        }
    }

    // 8. Process Optional Photo Upload
    $uploadedData = null;
    $hasFile = isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hasFile && empty($errors)) {
        $uploadResult = Uploader::processImage($_FILES['attachment'], 'incidents');
        if (!$uploadResult['success']) {
            $errors[] = $uploadResult['error'];
        } else {
            $uploadedData = $uploadResult['data'];
        }
    }

    // 9. Database Transactional Insert
    if (empty($errors)) {
        $citizenId = Session::getUserId();
        // Generate human-readable reference, e.g. REP-SRT-2026-A1B2
        $reportRef = sprintf('REP-SRT-%s-%s', date('Y'), strtoupper(bin2hex(random_bytes(3))));

        // Cryptographically secure 37-character private tracking token
        $rawToken = 'cstk_' . bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $rawToken);

        // Salted IP hash (prevents storing raw IPs while preserving rate-limit / abuse tracking)
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ipHash = hash('sha256', $clientIp . (string)Env::get('CSRF_SECRET_KEY', 'civicshield_salt'));
        $uaHash = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? 'none'));

        try {
            $pdo->beginTransaction();

            // 1. Insert into incident_reports
            $stmt = $pdo->prepare(
                'INSERT INTO incident_reports 
                 (report_reference, tracking_token_hash, incident_id, citizen_id, category_id,
                  title, description, submitted_severity, submission_mode, raw_lat, raw_lng,
                  location_description, ip_hash, user_agent_hash, status, created_at)
                 VALUES
                 (:report_ref, :token_hash, NULL, :citizen_id, :category_id,
                  :title, :description, :severity, :mode, :lat, :lng,
                  :loc_desc, :ip_hash, :ua_hash, :status, NOW())'
            );

            $stmt->execute([
                ':report_ref'  => $reportRef,
                ':token_hash'  => $tokenHash,
                ':citizen_id'  => $citizenId,
                ':category_id' => $formData['category_id'],
                ':title'       => $formData['title'],
                ':description' => $formData['description'],
                ':severity'    => $formData['submitted_severity'],
                ':mode'        => 'pseudonymous',
                ':lat'         => $latFloat,
                ':lng'         => $lngFloat,
                ':loc_desc'    => $formData['location_description'] !== '' ? $formData['location_description'] : null,
                ':ip_hash'     => $ipHash,
                ':ua_hash'     => $uaHash,
                ':status'      => 'pending_review'
            ]);

            $newReportId = (int)$pdo->lastInsertId();

            // 2. Insert Attachment if uploaded
            if ($uploadedData !== null) {
                $attStmt = $pdo->prepare(
                    'INSERT INTO incident_attachments 
                     (report_id, file_path, file_name_original, file_name_stored, file_mime, file_size_bytes, sha256_hash, exif_scrubbed, is_flagged_nsfw, created_at)
                     VALUES
                     (:report_id, :file_path, :orig_name, :stored_name, :mime, :size, :sha256, :scrubbed, 0, NOW())'
                );

                $attStmt->execute([
                    ':report_id'    => $newReportId,
                    ':file_path'    => $uploadedData['file_path'],
                    ':orig_name'    => $uploadedData['file_name_original'],
                    ':stored_name'  => $uploadedData['file_name_stored'],
                    ':mime'         => $uploadedData['file_mime'],
                    ':size'         => $uploadedData['file_size_bytes'],
                    ':sha256'       => $uploadedData['sha256_hash'],
                    ':scrubbed'     => $uploadedData['exif_scrubbed']
                ]);
            }

            // 3. Insert Contact Details if consent granted
            if ($formData['consent_followup']) {
                $contactStmt = $pdo->prepare(
                    'INSERT INTO citizen_contacts 
                     (report_id, contact_name, contact_phone, contact_email, preferred_contact_method, consent_emergency_followup, is_verified, created_at)
                     VALUES
                     (:report_id, :name, :phone, :email, :method, 1, 0, NOW())'
                );

                $contactStmt->execute([
                    ':report_id' => $newReportId,
                    ':name'      => $formData['contact_name'] !== '' ? $formData['contact_name'] : null,
                    ':phone'     => $formData['contact_phone'] !== '' ? $formData['contact_phone'] : null,
                    ':email'     => $formData['contact_email'] !== '' ? $formData['contact_email'] : null,
                    ':method'    => in_array($formData['preferred_method'], ['phone', 'email', 'sms'], true) ? $formData['preferred_method'] : 'none'
                ]);
            }

            $pdo->commit();

            // Run duplicate incident candidate detection in post-submission
            $candCount = 0;
            try {
                $candCount = DuplicateDetector::processReport($newReportId, null, $pdo);
            } catch (Throwable $e) {
                @error_log('[Duplicate Detection Error] ' . $e->getMessage());
            }

            // If no nearby candidate duplicates exist, promote to canonical municipal incident
            if ($candCount === 0) {
                try {
                    IncidentManager::createIncidentFromReport($newReportId, $pdo);
                } catch (Throwable $e) {
                    @error_log('[Standalone Incident Auto-Promotion Error] ' . $e->getMessage());
                }
            }

            // Store single-use tracking token in session for display ONCE on success page
            $_SESSION['submitted_report'] = [
                'reference'     => $reportRef,
                'raw_token'     => $rawToken,
                'title'         => $formData['title'],
                'created_at'    => date('d M Y, h:i A'),
                'exif_scrubbed' => $uploadedData ? ($uploadedData['exif_scrubbed'] === 1) : null
            ];

            if (Response::isJsonRequest()) {
                Response::created('Incident reported successfully.', [
                    'reference' => $reportRef,
                    'token'     => $rawToken,
                    'redirect'  => BASE_URL . '/citizen/report_success.php'
                ]);
            }

            header('Location: ' . BASE_URL . '/citizen/report_success.php');
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Cleanup uploaded file on database failure
            if ($uploadedData !== null) {
                Uploader::cleanup($uploadedData['absolute_path']);
            }
            @error_log('[Report Submission Error] ' . $e->getMessage());
            $errors[] = 'A database error occurred while filing your report. Please try again.';
        }
    }

    if (Response::isJsonRequest() && !empty($errors)) {
        Response::unprocessable('Validation failed.', $errors);
    }
}

require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';
?>

<div class="container py-4">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars(BASE_URL) ?>/citizen/" class="text-decoration-none">Citizen Workspace</a></li>
            <li class="breadcrumb-item active" aria-current="page">Report Incident</li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card cs-card shadow-sm p-4 p-md-5 border-light-subtle mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4 border-bottom pb-3">
                    <div>
                        <h2 class="fw-bold text-dark mb-1">
                            <i class="bi bi-megaphone-fill text-info me-2"></i>File Civic Incident Report
                        </h2>
                        <p class="text-muted small mb-0">
                            Logged in as citizen: <strong><?= htmlspecialchars($_SESSION['username'] ?? 'Citizen') ?></strong> &bull; Protected by Differential Geo-Noise
                        </p>
                    </div>
                    <span class="cs-demo-tag">Pseudonymous Submission</span>
                </div>

                <!-- Validation Errors Alert -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start gap-2 mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
                        <div class="flex-grow-1">
                            <strong class="d-block mb-1">Please correct the following issues:</strong>
                            <ul class="mb-0 ps-3 small">
                                <?php foreach ($errors as $err): ?>
                                    <li><?= htmlspecialchars($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= htmlspecialchars(BASE_URL) ?>/citizen/report.php" enctype="multipart/form-data" id="incidentReportForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Session::getCsrfToken()) ?>">

                    <!-- 1. Category Selection -->
                    <div class="mb-4">
                        <label for="category_id" class="form-label fw-semibold text-dark">
                            Civic Issue Category <span class="text-danger">*</span>
                        </label>
                        <select name="category_id" id="category_id" class="form-select" required>
                            <option value="">-- Select Municipal Category --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>" 
                                    <?= ($formData['category_id'] === (int)$cat['id']) ? 'selected' : '' ?>
                                    data-severity="<?= htmlspecialchars($cat['default_severity']) ?>"
                                    data-sla="<?= (int)$cat['resolution_sla_hours'] ?>">
                                    <?= htmlspecialchars($cat['name']) ?> (SLA: <?= (int)$cat['resolution_sla_hours'] ?>h)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small text-secondary">
                            Categories map directly to responsible municipal departments (Roads, Drainage, Waste, Electrical).
                        </div>
                    </div>

                    <!-- 2. Incident Title -->
                    <div class="mb-4">
                        <label for="title" class="form-label fw-semibold text-dark">
                            Incident Title <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="title" id="title" class="form-control" 
                               value="<?= htmlspecialchars($formData['title']) ?>" 
                               placeholder="e.g. Hazardous deep pothole in front of shopping complex" 
                               minlength="5" maxlength="200" required>
                        <div class="form-text small text-secondary">Concise summary between 5 and 200 characters.</div>
                    </div>

                    <!-- 3. Incident Description -->
                    <div class="mb-4">
                        <label for="description" class="form-label fw-semibold text-dark">
                            Detailed Description <span class="text-danger">*</span>
                        </label>
                        <textarea name="description" id="description" rows="4" class="form-control" 
                                  placeholder="Provide clear observations: size of hazard, obstruction to pedestrians, water contamination risk, etc." 
                                  minlength="10" maxlength="3000" required><?= htmlspecialchars($formData['description']) ?></textarea>
                        <div class="form-text small text-secondary">Provide sufficient detail for field inspection crews (10–3000 characters).</div>
                    </div>

                    <!-- 4. Severity Assessment -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold text-dark d-block">
                            Severity Level <span class="text-danger">*</span>
                        </label>
                        <div class="row g-2">
                            <div class="col-sm-6 col-md-3">
                                <label class="card h-100 p-3 border cs-severity-option cursor-pointer" for="sev_low">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="submitted_severity" id="sev_low" value="low" <?= ($formData['submitted_severity'] === 'low') ? 'checked' : '' ?>>
                                        <span class="cs-badge cs-badge-low fw-bold">Low</span>
                                    </div>
                                    <small class="text-muted mt-2 d-block">Minor aesthetic defect; no active traffic obstruction.</small>
                                </label>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="card h-100 p-3 border cs-severity-option cursor-pointer" for="sev_medium">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="submitted_severity" id="sev_medium" value="medium" <?= ($formData['submitted_severity'] === 'medium') ? 'checked' : '' ?>>
                                        <span class="cs-badge cs-badge-medium fw-bold">Medium</span>
                                    </div>
                                    <small class="text-muted mt-2 d-block">Moderate hazard; potential slowdown or pedestrian inconvenience.</small>
                                </label>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="card h-100 p-3 border cs-severity-option cursor-pointer" for="sev_high">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="submitted_severity" id="sev_high" value="high" <?= ($formData['submitted_severity'] === 'high') ? 'checked' : '' ?>>
                                        <span class="cs-badge cs-badge-high fw-bold">High</span>
                                    </div>
                                    <small class="text-muted mt-2 d-block">Serious safety risk; vehicle damage or water supply breach.</small>
                                </label>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <label class="card h-100 p-3 border cs-severity-option cursor-pointer" for="sev_critical">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="submitted_severity" id="sev_critical" value="critical" <?= ($formData['submitted_severity'] === 'critical') ? 'checked' : '' ?>>
                                        <span class="cs-badge cs-badge-critical fw-bold">Critical</span>
                                    </div>
                                    <small class="text-muted mt-2 d-block">Imminent bodily danger; live electrical wires or road collapse.</small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 5. Location Selection via Leaflet Map -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                            <label class="form-label fw-semibold text-dark mb-0">
                                Incident Location Coordinates &amp; Map Pin <span class="text-danger">*</span>
                            </label>
                            <button type="button" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1" id="btnGeolocate">
                                <i class="bi bi-geo-alt"></i> <span>Use Current Location</span>
                            </button>
                        </div>

                        <div id="locationNotice" class="alert alert-info py-2 px-3 small mb-2 d-flex align-items-center gap-2">
                            <i class="bi bi-shield-lock-fill text-info flex-shrink-0"></i>
                            <div>
                                <strong>Privacy Guarantee:</strong> Click or drag the map pin to mark where the incident is. Public intelligence maps apply a ~150m differential noise offset.
                            </div>
                        </div>

                        <!-- Leaflet Interactive Pin Map -->
                        <div class="cs-map-wrapper mb-3" style="height: 320px;">
                            <div id="reportPickerMap" style="height: 100%; width: 100%;"></div>
                        </div>

                        <div class="row g-2">
                            <div class="col-sm-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light text-muted">Latitude</span>
                                    <input type="text" name="raw_lat" id="raw_lat" class="form-control font-monospace" 
                                           value="<?= htmlspecialchars((string)$formData['raw_lat']) ?>" 
                                           placeholder="e.g. 21.19630000">
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-light text-muted">Longitude</span>
                                    <input type="text" name="raw_lng" id="raw_lng" class="form-control font-monospace" 
                                           value="<?= htmlspecialchars((string)$formData['raw_lng']) ?>" 
                                           placeholder="e.g. 72.79580000">
                                </div>
                            </div>
                        </div>

                        <div class="mt-2">
                            <label for="location_description" class="form-label small text-secondary mb-1">
                                Landmark / Neighborhood Description (Helpful for field crews)
                            </label>
                            <input type="text" name="location_description" id="location_description" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($formData['location_description']) ?>" 
                                   placeholder="e.g. Near Prime Arcade Gate 2, Adajan Main Road, Surat" maxlength="255">
                        </div>
                    </div>

                    <!-- 6. Optional Photo Evidence Attachment -->
                    <div class="mb-4">
                        <label for="attachment" class="form-label fw-semibold text-dark">
                            Photo Evidence Attachment <span class="text-muted fw-normal">(Optional)</span>
                        </label>
                        <input type="file" name="attachment" id="attachment" class="form-control" accept="image/jpeg,image/png,image/webp">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-1 small text-secondary">
                            <span><i class="bi bi-file-earmark-image me-1"></i>Formats: JPEG, PNG, WebP (Max 5MB)</span>
                            <span class="text-success"><i class="bi bi-check-circle me-1"></i>EXIF / GPS metadata is scrubbed automatically</span>
                        </div>
                        <div id="imagePreviewContainer" class="mt-2 d-none">
                            <img id="imagePreview" src="#" alt="Attachment Preview" class="img-thumbnail" style="max-height: 180px;">
                        </div>
                    </div>

                    <!-- 7. Emergency Callback Consent (Strictly Isolated Enclave) -->
                    <div class="card p-3 bg-light-subtle border-light-subtle mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="consent_followup" id="consent_followup" value="1" <?= $formData['consent_followup'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold text-dark" for="consent_followup">
                                I consent to be contacted by municipal field engineers for emergency verification (Optional)
                            </label>
                        </div>
                        <small class="text-muted d-block mt-1">
                            Your contact details are stored in an encrypted enclave (<code>citizen_contacts</code>) strictly isolated from public views and never shared on public heatmaps.
                        </small>

                        <div id="contactFieldsCollapse" class="mt-3 <?= $formData['consent_followup'] ? '' : 'd-none' ?>">
                            <div class="row g-2">
                                <div class="col-sm-4">
                                    <label for="contact_name" class="form-label small text-secondary mb-1">Contact Name</label>
                                    <input type="text" name="contact_name" id="contact_name" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($formData['contact_name']) ?>" placeholder="e.g. Ramesh V.">
                                </div>
                                <div class="col-sm-4">
                                    <label for="contact_phone" class="form-label small text-secondary mb-1">Phone Number</label>
                                    <input type="tel" name="contact_phone" id="contact_phone" class="form-control form-control-sm" 
                                           value="<?= htmlspecialchars($formData['contact_phone']) ?>" placeholder="e.g. +91 98251 01234">
                                </div>
                                <div class="col-sm-4">
                                    <label for="preferred_method" class="form-label small text-secondary mb-1">Preferred Method</label>
                                    <select name="preferred_method" id="preferred_method" class="form-select form-select-sm">
                                        <option value="none" <?= $formData['preferred_method'] === 'none' ? 'selected' : '' ?>>No Preference</option>
                                        <option value="phone" <?= $formData['preferred_method'] === 'phone' ? 'selected' : '' ?>>Phone Call</option>
                                        <option value="sms" <?= $formData['preferred_method'] === 'sms' ? 'selected' : '' ?>>SMS</option>
                                        <option value="email" <?= $formData['preferred_method'] === 'email' ? 'selected' : '' ?>>Email</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-2 border-top">
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/citizen/" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i> Cancel
                        </a>
                        <button type="submit" class="btn btn-cs-primary px-4 py-2 fw-semibold" id="btnSubmitReport">
                            <i class="bi bi-send-fill me-1"></i> Submit Incident Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Interactive Map Script for Location Picker -->
<script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/map.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Initialize Picker Map (Default center: Surat, Gujarat)
    const initialLat = <?= !empty($formData['raw_lat']) ? (float)$formData['raw_lat'] : 21.1850 ?>;
    const initialLng = <?= !empty($formData['raw_lng']) ? (float)$formData['raw_lng'] : 72.8050 ?>;
    const hasInitialCoords = <?= (!empty($formData['raw_lat']) && !empty($formData['raw_lng'])) ? 'true' : 'false' ?>;

    const map = L.map('reportPickerMap', {
        scrollWheelZoom: false
    }).setView([initialLat, initialLng], 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors &bull; CivicShield'
    }).addTo(map);

    let marker = null;

    function setPin(lat, lng) {
        document.getElementById('raw_lat').value = lat.toFixed(8);
        document.getElementById('raw_lng').value = lng.toFixed(8);

        if (marker) {
            marker.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng], { draggable: true }).addTo(map);
            marker.on('dragend', function(e) {
                const pos = e.target.getLatLng();
                document.getElementById('raw_lat').value = pos.lat.toFixed(8);
                document.getElementById('raw_lng').value = pos.lng.toFixed(8);
            });
        }
    }

    if (hasInitialCoords) {
        setPin(initialLat, initialLng);
    }

    // Map click sets pin
    map.on('click', function(e) {
        setPin(e.latlng.lat, e.latlng.lng);
    });

    // 2. Geolocation Button
    document.getElementById('btnGeolocate').addEventListener('click', function() {
        if (!navigator.geolocation) {
            alert('Browser geolocation is not supported by your device.');
            return;
        }

        const btn = this;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Locating...';
        btn.disabled = true;

        navigator.geolocation.getCurrentPosition(
            function(position) {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                map.setView([lat, lng], 15);
                setPin(lat, lng);
            },
            function(err) {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
                let msg = 'Unable to retrieve location.';
                if (err.code === 1) msg = 'Location access permission was denied by user.';
                else if (err.code === 2) msg = 'Location position is currently unavailable.';
                else if (err.code === 3) msg = 'Location request timed out.';
                alert(msg + ' Please click directly on the map to place the incident pin.');
            },
            { timeout: 10000, enableHighAccuracy: true }
        );
    });

    // 3. Image Preview Handler
    const attachmentInput = document.getElementById('attachment');
    const previewContainer = document.getElementById('imagePreviewContainer');
    const previewImg = document.getElementById('imagePreview');

    if (attachmentInput) {
        attachmentInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                if (file.size > 5242880) {
                    alert('File exceeds maximum size of 5MB.');
                    this.value = '';
                    previewContainer.classList.add('d-none');
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewContainer.classList.remove('d-none');
                };
                reader.readAsDataURL(file);
            } else {
                previewContainer.classList.add('d-none');
            }
        });
    }

    // 4. Toggle Contact Enclave Visibility
    const consentCheckbox = document.getElementById('consent_followup');
    const contactCollapse = document.getElementById('contactFieldsCollapse');
    if (consentCheckbox && contactCollapse) {
        consentCheckbox.addEventListener('change', function() {
            contactCollapse.classList.toggle('d-none', !this.checked);
        });
    }

    // 5. Category auto-selects default severity
    const catSelect = document.getElementById('category_id');
    if (catSelect) {
        catSelect.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const defSev = selected.getAttribute('data-severity');
            if (defSev) {
                const radio = document.getElementById('sev_' + defSev);
                if (radio) radio.checked = true;
            }
        });
    }

    // 6. Submit loading state to prevent double submissions
    const form = document.getElementById('incidentReportForm');
    const submitBtn = document.getElementById('btnSubmitReport');
    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Submitting Report...';
        });
    }
});
</script>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
