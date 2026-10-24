# CivicShield Incident Priority Engine

## Purpose

The priority engine provides an explainable triage indicator so administrators can decide which incident to review first. It is a deterministic heuristic, not machine learning and not a definitive measure of real-world danger.

## Documented inputs

| Signal | Weight | Meaning |
|---|---:|---|
| Submitted severity | 35% | Urgency reported with the incident |
| Distinct report count | 25% | Corroboration from separate reports |
| Elapsed time | 20% | How long the issue has remained open |
| Category/SLA urgency | 20% | Response expectation associated with the category |

The documented result is bounded to 0–100. Exact bins, clamps and category mapping live in `core/PriorityScorer.php`; that source file is authoritative.

## Operational guidance

- Treat the score as decision support, not an automatic dispatch or closure rule.
- Review safety-critical reports even if the score is low.
- Recalculate priority when report consolidation or lifecycle changes require it.
- Record operational reasoning when overriding prioritization.

See [architecture.md](architecture.md) for the wider workflow and documented formula.
