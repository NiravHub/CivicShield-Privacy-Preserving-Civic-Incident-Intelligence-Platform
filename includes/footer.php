<?php
declare(strict_types=1);
?>
    </main>
    <footer class="bg-dark text-light py-5 mt-auto border-top border-secondary-subtle" role="contentinfo">
        <div class="container">
            <div class="row gy-4">
                <div class="col-lg-5">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="cs-brand-icon"><i class="bi bi-shield-lock-fill"></i></span>
                        <span class="fw-bold fs-5 text-white">Civic<span class="text-info">Shield</span></span>
                    </div>
                    <p class="text-secondary small mb-3">
                        Privacy-Preserving Civic Incident Intelligence Platform &bull; A Web Technology Open Elective Project. Reconciling municipal accountability and citizen whistleblowing safety through cryptographic anonymity and differential spatial noise.
                    </p>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-secondary-subtle text-light border border-secondary small">Academic Prototype</span>
                        <span class="text-secondary small">&bull; Test Jurisdiction: Surat, Gujarat</span>
                    </div>
                </div>

                <div class="col-sm-6 col-lg-3">
                    <h6 class="text-white fw-bold mb-3">Platform Navigation</h6>
                    <ul class="list-unstyled small text-secondary mb-0 d-flex flex-column gap-2">
                        <li><a href="<?= htmlspecialchars(BASE_URL) ?>/" class="text-decoration-none text-secondary hover-text-white"><i class="bi bi-chevron-right me-1 text-info"></i> Home Overview</a></li>
                        <li><a href="<?= htmlspecialchars(BASE_URL) ?>/#mapSection" class="text-decoration-none text-secondary hover-text-white"><i class="bi bi-chevron-right me-1 text-info"></i> Geospatial Map</a></li>
                        <li><a href="<?= htmlspecialchars(BASE_URL) ?>/#categoriesSection" class="text-decoration-none text-secondary hover-text-white"><i class="bi bi-chevron-right me-1 text-info"></i> Incident Categories</a></li>
                        <li><a href="<?= htmlspecialchars(BASE_URL) ?>/#howItWorksSection" class="text-decoration-none text-secondary hover-text-white"><i class="bi bi-chevron-right me-1 text-info"></i> How It Works</a></li>
                        <li><a href="<?= htmlspecialchars(BASE_URL) ?>/#trackerSection" class="text-decoration-none text-secondary hover-text-white"><i class="bi bi-chevron-right me-1 text-info"></i> Track Report by Token</a></li>
                        <li><a href="<?= htmlspecialchars(BASE_URL) ?>/api/v1/health.php" target="_blank" class="text-decoration-none text-secondary hover-text-white"><i class="bi bi-chevron-right me-1 text-info"></i> System Health Diagnostics</a></li>
                    </ul>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <h6 class="text-white fw-bold mb-3">Privacy &amp; Data Safeguards</h6>
                    <ul class="list-unstyled small text-secondary mb-0 d-flex flex-column gap-2">
                        <li class="d-flex align-items-start gap-2">
                            <i class="bi bi-shield-check text-success flex-shrink-0 mt-1"></i>
                            <span><strong>Zero-Identity Intake:</strong> No mandatory personal identity or phone number required to submit reports.</span>
                        </li>
                        <li class="d-flex align-items-start gap-2">
                            <i class="bi bi-geo-alt-fill text-info flex-shrink-0 mt-1"></i>
                            <span><strong>Geo-Obfuscation:</strong> Public coordinates shown as ~150m fuzzed centroids to reduce home-address triangulation risk.</span>
                        </li>
                        <li class="d-flex align-items-start gap-2">
                            <i class="bi bi-camera-fill text-warning flex-shrink-0 mt-1"></i>
                            <span><strong>EXIF Sanitization:</strong> Camera serial numbers, timestamps, and embedded GPS metadata stripped before disk storage.</span>
                        </li>
                    </ul>
                </div>
            </div>

            <hr class="border-secondary my-4">

            <div class="row align-items-center gy-2">
                <div class="col-md-6 text-secondary small">
                    &copy; <?= date('Y') ?> CivicShield. Map tiles &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" class="text-info text-decoration-none">OpenStreetMap</a> contributors.
                </div>
                <div class="col-md-6 text-md-end text-secondary small">
                    Built with HTML5, CSS3, Bootstrap 5, PHP 8 &amp; Leaflet.js
                </div>
            </div>
        </div>
    </footer>

    <!-- jQuery 3.7.1 -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    
    <!-- Bootstrap 5.3.3 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    
    <!-- Leaflet.js 1.9.4 -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    
    <!-- CivicShield Custom JavaScript -->
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/main.js"></script>
</body>
</html>
