# CivicShield Privacy Model

## Design goals

CivicShield aims to enable civic reporting while limiting exposure of reporter identity, contact details and precise location. These application safeguards do not guarantee absolute anonymity against every threat.

## Data separation

- `incident_reports` stores individual intake records.
- Optional contact details are stored separately in `citizen_contacts`.
- Consolidated `incidents` are used for operational tracking and map-oriented summaries.
- Tracking tokens are stored as SHA-256 hashes in the database; the raw token is shown to the reporter for later use.
- IP/user-agent-related audit values are hashed where implemented.

## Location privacy

Map-oriented payloads use obfuscated coordinates/centroids and a configured fuzz radius (sample configuration: 150 metres). Raw intake coordinates may exist in restricted database records for operational purposes; they must not be included in public responses.

Spatial fuzzing is **not** a formal mathematical differential-privacy guarantee. Do not describe the application as providing epsilon-differential privacy.

## Public tracking

Public tracking is designed to return lifecycle information, not personal/contact records, raw coordinates, staff-only operational notes or token hashes. The versioned endpoint `api/v1/track.php` accepts POST and does not allow token lookup in the URL query string.

## Upload handling

Uploads are validated against configured content types and size limits. Stored files use generated names and the uploads directory contains an `.htaccess` rule intended to prevent script execution. Metadata scrubbing depends on the file type, available image-processing extensions and active upload path.

## Residual risks

- Server logs, database backups, infrastructure configuration or privileged misuse can expose sensitive information.
- Location fuzzing limits precision but cannot prevent all re-identification through combined attributes.
- Demo fixtures are public and unsuitable for real personal information.
- Controls require regression testing after security-relevant changes.

See [security.md](security.md) and [architecture.md](architecture.md).
