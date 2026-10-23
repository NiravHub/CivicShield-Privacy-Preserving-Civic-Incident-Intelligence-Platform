<?php
declare(strict_types=1);

$pageTitle = 'Privacy-Preserving Civic Incident Intelligence Platform';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
$loadLeaflet = true;
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'header.php';

$dbHealth = Database::checkHealth();

$publicIncidents = [];
if ($dbHealth['connected']) {
    try {
        $mapRows = AnalyticsManager::getMapIncidents([], 50);
        foreach ($mapRows as $row) {
            $publicIncidents[] = [
                'reference'    => $row['incident_reference'],
                'title'        => $row['title'],
                'category'     => strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$row['category_name'])),
                'neighborhood' => $row['neighborhood'],
                'severity'     => $row['severity'],
                'status'       => $row['status'],
                'lat'          => (float)$row['obfuscated_lat'],
                'lng'          => (float)$row['obfuscated_lng'],
                'radius'       => (int)($row['fuzz_radius_meters'] ?? 150)
            ];
        }
    } catch (Throwable $e) {
        @error_log('[Index Map Live Incidents Error] ' . $e->getMessage());
    }
}
?>

<!-- ==============================================================================
 * Hero Section
 * ============================================================================== -->
<section class="cs-hero py-5 position-relative text-start" aria-labelledby="heroHeading">
    <div class="container py-4">
        <div class="row align-items-center gy-5">
            <div class="col-lg-7">
                <div class="d-inline-flex align-items-center gap-2 px-3 py-1 mb-3 rounded-pill bg-info bg-opacity-10 border border-info border-opacity-25 text-info small fw-semibold">
                    <i class="bi bi-shield-lock-fill"></i>
                    <span>Web Technology Open Elective Project &bull; Academic Prototype</span>
                </div>
                <h1 id="heroHeading" class="display-4 fw-bold text-white mb-3" style="line-height: 1.2;">
                    Civic<span class="text-info">Shield</span>: Privacy-Preserving Civic Incident Intelligence
                </h1>
                <p class="lead text-light text-opacity-75 mb-4" style="font-size: 1.15rem;">
                    Empowering citizens to report municipal hazards, broken infrastructure, and civic failures without fear of retaliation or surveillance. Built with geo-obfuscated public maps, single-use token tracking, and structured municipal triage.
                </p>
                <div class="hero-cta d-flex flex-wrap gap-3">
                    <a href="<?= htmlspecialchars(BASE_URL) ?>/citizen/report.php" class="btn btn-cs-primary btn-lg shadow-sm">
                        <i class="bi bi-megaphone-fill me-2"></i> Report an Issue
                    </a>
                    <a href="#trackerSection" class="btn btn-outline-light btn-lg">
                        <i class="bi bi-search me-2"></i> Track a Report
                    </a>
                    <a href="#mapSection" class="btn btn-link text-info text-decoration-none d-inline-flex align-items-center gap-1">
                        <span>Explore Public Map</span> <i class="bi bi-arrow-down"></i>
                    </a>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card cs-card diagnostic-card bg-dark bg-opacity-50 border-secondary border-opacity-50 text-light p-4 shadow-lg rounded-4 mb-5">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-info mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-cpu"></i> System Diagnostic State
                        </h6>
                        <span class="cs-demo-tag">Surat Testbed</span>
                    </div>
                    <ul class="list-unstyled mb-0 small">
                        <li class="d-flex justify-content-between py-2 border-bottom border-secondary border-opacity-25">
                            <span class="text-light text-opacity-75">Platform Architecture:</span>
                            <span class="font-monospace text-white">PHP 8.2 + PDO (Vanilla)</span>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom border-secondary border-opacity-25">
                            <span class="text-light text-opacity-75">Privacy Mechanism:</span>
                            <span class="text-info">Geo-fuzz radius ≈ 150m</span>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom border-secondary border-opacity-25">
                            <span class="text-light text-opacity-75">Database State:</span>
                            <span class="<?= $dbHealth['connected'] ? 'text-success fw-semibold' : 'text-warning' ?>">
                                <i class="bi <?= $dbHealth['connected'] ? 'bi-check-circle' : 'bi-exclamation-circle' ?> me-1"></i>
                                <?= $dbHealth['connected'] ? 'Connected (MySQL 8)' : 'Awaiting XAMPP Service' ?>
                            </span>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom border-secondary border-opacity-25">
                            <span class="text-light text-opacity-75">Demonstration Jurisdiction:</span>
                            <span class="text-white">Surat Municipal Corp. (SMC)</span>
                        </li>
                        <li class="d-flex justify-content-between pt-2">
                            <span class="text-light text-opacity-75">Environment Mode:</span>
                            <span class="badge bg-primary text-uppercase"><?= htmlspecialchars(APP_ENV) ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==============================================================================
 * Civic Problem & Solution Overview
 * ============================================================================== -->
<section class="py-5 bg-white border-bottom border-light-subtle">
    <div class="container py-2">
        <div class="row text-center mb-5 justify-content-center">
            <div class="col-lg-8">
                <span class="cs-demo-tag mb-2">The Civic Problem</span>
                <h2 class="fw-bold text-dark mt-2">Why Privacy-Preserving Civic Reporting Matters</h2>
                <p class="text-muted">
                    Traditional municipal apps demand phone numbers, national IDs, and exact GPS pins. When citizens report sensitive issues like illegal dumping or contractor negligence, their identity is vulnerable to doxxing and local retaliation.
                </p>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-4 mb-4">
                <div class="card cs-card h-100 p-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-danger bg-opacity-10 text-danger rounded-3 p-3 mb-3" style="width: 52px; height: 52px;">
                        <i class="bi bi-shield-x fs-4"></i>
                    </div>
                    <h5 class="fw-bold text-dark">The Vulnerability</h5>
                    <p class="text-secondary small mb-0">
                        Public dashboards that reveal exact coordinates and submitter identities can lead to intimidation, location triangulation, and decreased civic engagement.
                    </p>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card cs-card h-100 p-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-info bg-opacity-10 text-info rounded-3 p-3 mb-3" style="width: 52px; height: 52px;">
                        <i class="bi bi-shield-lock fs-4"></i>
                    </div>
                    <h5 class="fw-bold text-dark">The CivicShield Solution</h5>
                    <p class="text-secondary small mb-0">
                        Public map centroids are offset by ~150m, photos have camera metadata stripped, and submitters receive anonymous single-use tokens instead of accounts.
                    </p>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card cs-card h-100 p-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success rounded-3 p-3 mb-3" style="width: 52px; height: 52px;">
                        <i class="bi bi-check2-circle fs-4"></i>
                    </div>
                    <h5 class="fw-bold text-dark">Accountable Resolution</h5>
                    <p class="text-secondary small mb-0">
                        Field officers receive targeted work orders with defined SLAs. Completed resolutions require photo proof and administrative verification before public closure.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==============================================================================
 * Civic Issue Categories
 * ============================================================================== -->
<section id="categoriesSection" class="py-5 bg-light border-bottom border-light-subtle" aria-labelledby="catHeading">
    <div class="container py-2">
        <div class="row align-items-end mb-4">
            <div class="col-md-8">
                <span class="cs-demo-tag mb-1">Standardized Taxonomy</span>
                <h2 id="catHeading" class="fw-bold text-dark mt-2 mb-1">Supported Civic Incident Categories</h2>
                <p class="text-muted mb-0">Classified and routed according to municipal service jurisdictions</p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <a href="<?= htmlspecialchars(BASE_URL) ?>/citizen/report.php" class="btn btn-outline-primary btn-sm fw-semibold">
                    <i class="bi bi-plus-circle me-1"></i> File Report Under Category
                </a>
            </div>
        </div>

        <div class="row g-4">
            <!-- 1. Potholes & Roads -->
            <div class="col-md-6 col-lg-4">
                <div class="cs-category-card">
                    <div class="cs-category-icon cs-cat-roads">
                        <i class="bi bi-cone-striped"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Roads &amp; Potholes</h5>
                    <span class="text-primary small fw-semibold mb-2">Roads &amp; Bridges Department (SMC-ROADS)</span>
                    <p class="text-secondary small flex-grow-1">
                        Asphalt craters, damaged pavers, road subsidence, missing manhole covers, and hazardous monsoon road fissures.
                    </p>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top border-light-subtle small text-muted">
                        <span><i class="bi bi-clock me-1"></i> Target SLA: <strong>48 Hours</strong></span>
                        <span class="cs-badge cs-badge-medium">Medium</span>
                    </div>
                </div>
            </div>

            <!-- 2. Solid Waste & Garbage -->
            <div class="col-md-6 col-lg-4">
                <div class="cs-category-card">
                    <div class="cs-category-icon cs-cat-sanitation">
                        <i class="bi bi-trash3-fill"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Solid Waste &amp; Garbage</h5>
                    <span class="text-danger small fw-semibold mb-2">Solid Waste Management (SMC-SWM)</span>
                    <p class="text-secondary small flex-grow-1">
                        Overflowing neighborhood dumpsters, illegal construction debris, road shoulder waste, and unattended market garbage.
                    </p>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top border-light-subtle small text-muted">
                        <span><i class="bi bi-clock me-1"></i> Target SLA: <strong>24 Hours</strong></span>
                        <span class="cs-badge cs-badge-low">Low</span>
                    </div>
                </div>
            </div>

            <!-- 3. Drainage & Water Logging -->
            <div class="col-md-6 col-lg-4">
                <div class="cs-category-card">
                    <div class="cs-category-icon cs-cat-water">
                        <i class="bi bi-droplet-half"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Drainage &amp; Water Ruptures</h5>
                    <span class="text-info small fw-semibold mb-2">Drainage &amp; Sewerage (SMC-DRAINAGE)</span>
                    <p class="text-secondary small flex-grow-1">
                        Potable water supply line ruptures, open storm drains, sewage backflow, and contaminated municipal pipe breaches.
                    </p>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top border-light-subtle small text-muted">
                        <span><i class="bi bi-clock me-1"></i> Target SLA: <strong>24 Hours</strong></span>
                        <span class="cs-badge cs-badge-critical">Critical</span>
                    </div>
                </div>
            </div>

            <!-- 4. Street Lighting & Electrical -->
            <div class="col-md-6 col-lg-4">
                <div class="cs-category-card">
                    <div class="cs-category-icon cs-cat-electrical">
                        <i class="bi bi-lightning-charge-fill"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Street Lighting &amp; Electrical</h5>
                    <span class="text-warning text-dark-emphasis small fw-semibold mb-2">Electrical Department (SMC-ELECTRICAL)</span>
                    <p class="text-secondary small flex-grow-1">
                        Dark street poles on arterial highways, snapped live wires, sparking transformer boxes, and fallen junction posts.
                    </p>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top border-light-subtle small text-muted">
                        <span><i class="bi bi-clock me-1"></i> Target SLA: <strong>12 Hours</strong></span>
                        <span class="cs-badge cs-badge-high">High</span>
                    </div>
                </div>
            </div>

            <!-- 5. Public Health & Vector Control -->
            <div class="col-md-6 col-lg-4">
                <div class="cs-category-card">
                    <div class="cs-category-icon cs-cat-health">
                        <i class="bi bi-shield-shaded"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Public Health &amp; Vectors</h5>
                    <span class="text-success small fw-semibold mb-2">Health Department (SMC-HEALTH)</span>
                    <p class="text-secondary small flex-grow-1">
                        Stagnant water pools breeding mosquitoes, biological contamination, unsterilized dumping, and chemical spills.
                    </p>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top border-light-subtle small text-muted">
                        <span><i class="bi bi-clock me-1"></i> Target SLA: <strong>24 Hours</strong></span>
                        <span class="cs-badge cs-badge-critical">Critical</span>
                    </div>
                </div>
            </div>

            <!-- 6. Stray Animal Hazards -->
            <div class="col-md-6 col-lg-4">
                <div class="cs-category-card">
                    <div class="cs-category-icon cs-cat-safety">
                        <i class="bi bi-exclamation-octagon-fill"></i>
                    </div>
                    <h5 class="fw-bold text-dark mb-1">Stray Animal Hazards</h5>
                    <span class="text-primary small fw-semibold mb-2">Veterinary &amp; Animal Control (SMC-VET)</span>
                    <p class="text-secondary small flex-grow-1">
                        Aggressive dog packs obstructing public pathways, cattle blocking expressways, and injured wildlife hazards.
                    </p>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top border-light-subtle small text-muted">
                        <span><i class="bi bi-clock me-1"></i> Target SLA: <strong>36 Hours</strong></span>
                        <span class="cs-badge cs-badge-medium">Medium</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==============================================================================
 * How It Works (4-Stage Lifecycle)
 * ============================================================================== -->
<section id="howItWorksSection" class="py-5 bg-white border-bottom border-light-subtle" aria-labelledby="hiwHeading">
    <div class="container py-2">
        <div class="text-center mb-5">
            <span class="cs-demo-tag mb-1">Platform Workflow</span>
            <h2 id="hiwHeading" class="fw-bold text-dark mt-2">How CivicShield Works</h2>
            <p class="text-muted">A privacy-by-design lifecycle from citizen intake to verified municipal resolution</p>
        </div>

        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="cs-step-card shadow-sm">
                    <div class="cs-step-badge">1</div>
                    <h6 class="fw-bold text-dark mb-2">Zero-Identity Intake</h6>
                    <p class="text-secondary small mb-0">
                        Citizen submits report details and photo. The system issues a 32-character single-use tracking token and scrubs all IP addresses.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="cs-step-card shadow-sm">
                    <div class="cs-step-badge">2</div>
                    <h6 class="fw-bold text-dark mb-2">Privacy &amp; Triage</h6>
                    <p class="text-secondary small mb-0">
                        EXIF camera metadata is stripped from attachments. Bounded $\approx 150m$ Gaussian noise is applied to map coordinates, and duplicates are flagged.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="cs-step-card shadow-sm">
                    <div class="cs-step-badge">3</div>
                    <h6 class="fw-bold text-dark mb-2">Officer Dispatch</h6>
                    <p class="text-secondary small mb-0">
                        SMC central triage assigns work order to designated field officers (e.g. Roads, Drainage) with priority scores and target SLA deadlines.
                    </p>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="cs-step-card shadow-sm">
                    <div class="cs-step-badge">4</div>
                    <h6 class="fw-bold text-dark mb-2">Verified Resolution</h6>
                    <p class="text-secondary small mb-0">
                        Officer uploads on-site completion proof photo. Administrator verifies resolution notes before marking incident publicly closed.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ==============================================================================
 * Interactive Leaflet Map Section (Surat Demonstration Data)
 * ============================================================================== -->
<section id="mapSection" class="py-5 bg-light border-bottom border-light-subtle" aria-labelledby="mapHeading">
    <div class="container py-2">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
            <div>
                <span class="cs-demo-tag mb-1">Geospatial Intelligence</span>
                <h2 id="mapHeading" class="fw-bold text-dark mt-2 mb-1">Surat Municipal Incident Explorer</h2>
                <p class="text-muted small mb-0">
                    Live geospatial visualization powered by <strong>Leaflet.js</strong> and <strong>OpenStreetMap</strong>. Demonstrates privacy fuzz rings (~150m) around active civic incidents.
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if (!empty($publicIncidents)): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 small">
                        <i class="bi bi-broadcast me-1"></i> Live Geo-Obfuscated Feed (<?= count($publicIncidents) ?> Incidents)
                    </span>
                <?php else: ?>
                    <span class="badge bg-secondary-subtle text-dark border px-3 py-2 small">
                        <i class="bi bi-info-circle me-1"></i> Demonstration Data
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="cs-map-wrapper">
            <div id="suratIncidentMap" class="cs-map-container" role="region" aria-label="Surat Civic Incident Map"></div>
        </div>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-3 p-3 bg-white border border-light-subtle rounded-3 small">
            <div class="d-flex align-items-center flex-wrap gap-3">
                <strong class="text-dark">Map Legend:</strong>
                <span><span class="cs-legend-dot" style="background:#dc2626;"></span> Critical / Electrical</span>
                <span><span class="cs-legend-dot" style="background:#ea580c;"></span> High Severity / Pothole</span>
                <span><span class="cs-legend-dot" style="background:#d97706;"></span> Pending / In-Progress</span>
                <span><span class="cs-legend-dot" style="background:#16a34a;"></span> Verified Resolved</span>
                <span><span class="cs-legend-dot" style="background:#0284c7; border: 1px dashed #0284c7;"></span> Configured Displacement Radius</span>
            </div>
            <div class="text-muted">
                <i class="bi bi-shield-check text-info me-1"></i> Public maps use obfuscated incident coordinates rather than the original submitted coordinates.
            </div>
        </div>
    </div>
</section>

<!-- ==============================================================================
 * Track a Report Section
 * ============================================================================== -->
<section id="trackerSection" class="py-5 bg-white" aria-labelledby="trackerHeading">
    <div class="container py-2">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card cs-card p-4 p-md-5 shadow-sm border-light-subtle">
                    <div class="text-center mb-4">
                        <span class="cs-demo-tag mb-2">Cryptographic Verification</span>
                        <h3 id="trackerHeading" class="fw-bold text-dark mt-2">Track Your Incident Report</h3>
                        <p class="text-muted small">
                            Enter the single-use tracking token received during report submission to view real-time triage, officer dispatch status, and verification notes.
                        </p>
                    </div>

                    <form id="reportTrackerForm" class="mb-4">
                        <div class="input-group input-group-lg shadow-sm">
                            <span class="input-group-text bg-light text-muted border-end-0">
                                <i class="bi bi-key-fill"></i>
                            </span>
                            <input type="text" id="trackingTokenInput" class="form-control border-start-0" placeholder="e.g. cstk_vesu_waterleak_7a9f2b1c4e6d" aria-label="Enter your 32-character tracking token" required>
                            <button type="submit" class="btn btn-cs-primary px-4">
                                <i class="bi bi-search me-1"></i> Lookup Status
                            </button>
                        </div>
                    </form>

                    <!-- Interactive Demonstration Pills -->
                    <div class="p-3 bg-light rounded-3 border border-light-subtle small mb-3">
                        <strong class="d-block text-dark mb-2"><i class="bi bi-lightbulb me-1 text-warning"></i> Try Demonstration Tracking Tokens:</strong>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm font-monospace" onclick="document.getElementById('trackingTokenInput').value='cstk_vesu_waterleak_7a9f2b1c4e6d'; document.getElementById('reportTrackerForm').dispatchEvent(new Event('submit'));">
                                Vesu Water Burst (Resolved)
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm font-monospace" onclick="document.getElementById('trackingTokenInput').value='cstk_adajan_pothole_dup_3e2d1c0b'; document.getElementById('reportTrackerForm').dispatchEvent(new Event('submit'));">
                                Adajan Pothole (In Progress)
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm font-monospace" onclick="document.getElementById('trackingTokenInput').value='cstk_rander_garbage_4f5e6d7c8b9a'; document.getElementById('reportTrackerForm').dispatchEvent(new Event('submit'));">
                                Rander Waste (Assigned)
                            </button>
                        </div>
                    </div>

                    <!-- Dynamic Lookup Result Container -->
                    <div id="trackerResultBox" class="d-none"></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Leaflet Map Initialization Script -->
<script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/map.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Leaflet map centered on Surat, Gujarat
    const map = CivicMap.init('suratIncidentMap', 21.1850, 72.8050, 13);
    if (!map) return;

    // Demonstration incidents from database/seed.sql
    const demonstrationIncidents = [
        {
            reference: 'CS-SRT-2026-0101',
            title: 'Deep Road Excavation & Pothole Hazard',
            category: 'roads',
            neighborhood: 'Adajan Main Road',
            severity: 'high',
            status: 'in_progress',
            lat: 21.19630000,
            lng: 72.79580000,
            radius: 150
        },
        {
            reference: 'CS-SRT-2026-0102',
            title: 'High-Pressure Potable Water Main Rupture',
            category: 'water',
            neighborhood: 'VIP Road, Vesu',
            severity: 'critical',
            status: 'resolved',
            lat: 21.14480000,
            lng: 72.77450000,
            radius: 150
        },
        {
            reference: 'CS-SRT-2026-0103',
            title: 'Commercial Garbage Dump Overflowing',
            category: 'sanitation',
            neighborhood: 'Rander Causeway',
            severity: 'medium',
            status: 'assigned',
            lat: 21.21850000,
            lng: 72.79320000,
            radius: 150
        },
        {
            reference: 'CS-SRT-2026-0104',
            title: 'Sparking Overhead Transformer Cable',
            category: 'electrical',
            neighborhood: 'Athwalines Chowpatty',
            severity: 'critical',
            status: 'reported',
            lat: 21.17380000,
            lng: 72.80910000,
            radius: 150
        }
    ];

    const liveIncidents = <?= !empty($publicIncidents) ? json_encode($publicIncidents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null' ?>;
    CivicMap.renderDemoIncidents(map, (Array.isArray(liveIncidents) && liveIncidents.length > 0) ? liveIncidents : demonstrationIncidents);
});
</script>

<?php require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'footer.php'; ?>
