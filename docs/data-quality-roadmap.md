# Data quality roadmap highlights

The latest extraction refresh introduces a typed relation schema and more
aggressive noise filtering to keep downstream datasets trustworthy. This note
tracks the high-level areas of improvement and the next steps they unlock.

## Canonical relation schema

- Every triple now carries a canonical relation label, a relation type, and a
  confidence score so that analytics tooling can group facts deterministically
  without reverse engineering the engine's verb stems.
- Dataset exports also include a provenance stub (currently pointing back to the
  ingested documents) and a status flag. These fields make it easier to bolt on
  source offsets and review queues later.
- The statistics payload exposes relation-type and canonical-relation
  distributions to highlight skew in extractions at a glance.

## Cleaner document feeds

- Navigation-only scaffolding such as "scroll to next item", isolated timestamps
  (for example `0:34` blocks), and live counter fragments (`15,712 viewing`) are
  stripped before the engine sees the text.
- Boilerplate detectors now cover additional BBC-specific phrases including
  "live page" and "watch live" to prevent noisy triples like `live page isa
  page` from appearing in exports.
- Relative publish/update stamps (`Published at 09:32`, `Updated 7 minutes ago`)
  are filtered, allowing temporal reasoning layers to rely on explicit
  timestamps instead of UI hints.

## Upcoming enhancements

- Attach character offsets and source URLs to each structured entity once the
  ingestion pipeline provides per-sentence metadata.
- Normalise temporal references to absolute ISO 8601 timestamps so the timeline
  view can sequence events without manual curation.
- Expand the alias pipeline with automatic Wikidata linking and merge review UI
  to tame long-tail entity variants.

These notes evolve alongside the extraction engine. Keep the document close to
product specs and update it whenever the schema or cleaning heuristics shift so
analysts can rely on a single source of truth.
