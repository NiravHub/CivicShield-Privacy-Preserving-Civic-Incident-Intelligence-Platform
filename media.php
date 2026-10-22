<?php
declare(strict_types=1);

// Bootstrap application (constants, autoloader, env, DB, session)
require_once __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
require_once INCLUDES_PATH . DIRECTORY_SEPARATOR . 'auth_guard.php';

// Require any authenticated user (admin or officer)
// The auth_guard helper can enforce a role if needed; here we allow any loggedâ€‘in user.
requireAuth(); // no specific role restriction; will verify below

$pdo = Database::getConnection();
$userId = Session::getUserId();
$role = Session::getRole();

// Expected query parameters: id (numeric) and optional type (default "resolution")
$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$type = $_GET['type'] ?? 'resolution';

if ($id <= 0) {
    http_response_code(400);
    exit;
}

if ($type === 'resolution') {
    // Fetch the evidence record â€“ only columns we need for auth and file serving
    $stmt = $pdo->prepare('SELECT id, incident_id, officer_id, photo_path, photo_mime FROM resolution_evidence WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        exit;
    }
    // Authorization logic
    if ($role === 'admin') {
        // Admins may view any evidence
    } elseif ($role === 'officer') {
        // Officer may view only evidence belonging to incidents assigned to them
        $incidentId = (int)$row['incident_id'];
        // Reuse existing manager helper to verify assignment
        if (!IncidentManager::isIncidentAssignedToOfficer($incidentId, $userId, false, $pdo)) {
            http_response_code(403);
            exit;
        }
    } else {
        // Any other role is not permitted
        http_response_code(403);
        exit;
    }
    $relativePath = $row['photo_path'];
    $mime = $row['photo_mime'] ?? null;
} else {
    // Future extensions for other upload types could be added here.
    http_response_code(400);
    exit;
}

// Resolve the absolute filesystem path safely
// Ensure the stored path is a non-empty string
if (!is_string($relativePath) || $relativePath === '') {
    http_response_code(404);
    exit;
}
// Ensure the stored path is a relative path without traversal components
if (strpos($relativePath, '..') !== false || preg_match('/^[a-zA-Z]:/', $relativePath) === 1 || $relativePath[0] === '/' || $relativePath[0] === '\\') {
    // Reject absolute paths or traversal attempts
    http_response_code(404);
    exit;
}
$absPath = realpath(ROOT_PATH . DIRECTORY_SEPARATOR . $relativePath);
if ($absPath === false) {
    // Path could not be resolved (does not exist)
    http_response_code(404);
    exit;
}
$uploadsReal = realpath(UPLOADS_PATH);
if ($uploadsReal === false || !is_dir($uploadsReal)) {
    http_response_code(404);
    exit;
}
// Ensure the resolved file resides within the uploads directory (separatorâ€‘aware check)
if (strpos($absPath, $uploadsReal . DIRECTORY_SEPARATOR) !== 0 && $absPath !== $uploadsReal) {
    http_response_code(404);
    exit;
}
if (!is_file($absPath) || !is_readable($absPath)) {
    http_response_code(404);
    exit;
}

// Determine MIME type using finfo and whitelist allowed image types
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeDetected = $finfo->file($absPath);
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeDetected, $allowedMimes, true)) {
    // Unsupported or invalid file type
    http_response_code(415);
    exit;
}
// Use the validated MIME for response header
$mime = $mimeDetected;

// Send the file with securityâ€‘focused headers
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Length: ' . filesize($absPath));
readfile($absPath);
exit;
?>