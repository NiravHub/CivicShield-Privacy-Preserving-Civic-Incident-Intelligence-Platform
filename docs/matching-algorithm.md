# CivicShield Duplicate-Matching Algorithm

## Purpose

The duplicate detector identifies reports that may describe the same real-world issue and presents candidates for administrator review. It supports triage; it does not perform an automatic destructive merge based only on proximity.

## Candidate generation and scoring

The documented model combines four signals:

| Signal | Weight | Intuition |
|---|---:|---|
| Geographic proximity | 40% | Nearby reports are more likely to refer to the same issue |
| Category agreement | 30% | Matching categories add evidence |
| Text similarity | 20% | Similar normalized titles/descriptions add evidence |
| Temporal proximity | 10% | Reports submitted close in time add evidence |

```
score = 0.40 × spatial + 0.30 × category + 0.20 × text + 0.10 × time
```

The candidate radius is configurable via `DUPLICATE_CANDIDATE_RADIUS_METERS` (sample configuration: 50 m). Confirm exact scoring functions and thresholds in `core/DuplicateDetector.php` and `.env.example`; update this document when the implementation changes.

## Human decision step

Administrators inspect candidate scores and component signals, then explicitly confirm a duplicate or mark a candidate distinct. Decisions should remain auditable. Confirmation can associate a report with an existing canonical incident. A distinct report must follow the current standalone/promotion workflow.

## Limitations

- GPS inaccuracies affect candidate selection.
- Linguistic differences and short descriptions can reduce text similarity.
- A high score is evidence for review, not proof.
- A low score may miss genuine duplicates.

See [architecture.md](architecture.md) and `core/DuplicateDetector.php`.
