# Ricktorious Markets Intelligence Vision

Ricktorious Markets repurposes the semantic tooling stack into an autonomous
capital markets analyst. Instead of managing storefront blocks, the new module
hunts for price-moving news, feeds the shared knowledge graph, and condenses the
signals into an investor-ready briefing.

## Operating principles

1. **Autonomous discovery** – The news crawler continuously polls trusted
   sources, deduplicates headlines, and stores rich metadata so the semantic
   engine can reason about provenance, tone, and persona relevance.
2. **Investor segmentation** – Every article is classified against investor
   personas (retail, institutional, analyst, and insider) to contextualise why a
   headline matters and who is driving the mood swing.
3. **Explainable scoring** – Sentiment scores and highlights link back to the
   original coverage, allowing researchers and trading desks to validate signals
   instantly.
4. **Timeline-first storytelling** – A per-day sentiment series powers charts,
   dashboards, and alerting workflows that track the velocity of opinion shifts
   across investor classes.

## High-level components

- **Kernel** – Bootstraps the markets runtime, configures the news crawler, and
  exposes the report builder used by the web surface and future APIs.
- **News crawler** – Aggregates articles from live RSS feeds and curated static
  samples, normalising titles, summaries, and timestamps for downstream
  processing.
- **Sentiment analyser** – Scores positive and negative tones using lightweight
  lexical heuristics, then routes each article through the investor sentiment
  model.
- **Investor sentiment model** – Maps articles to persona segments using
  thematic keywords and generates per-segment sentiment snapshots.
- **Timeline builder** – Groups snapshots by date and persona so charting tools
  can visualise momentum over time.
- **Report builder** – Synthesises the crawler output into a shareable report
  containing highlights, per-segment metrics, article-level insights, and the
  timeline dataset.

## Initial deliverable

- `/markets.php` renders the autonomous briefing experience with:
  - Company query field and hero explainer.
  - Overview narrative and shareable highlights.
  - Persona cards showing current and average sentiment.
  - Timeline table detailing how tone shifts each day.
  - Article cards linking to the supporting coverage.
- `resources/markets/sample-news.json` offers offline sample coverage so demos
  work without external connectivity.
- `src/Ricktorious/Markets/*` implements the full runtime described above with a
  reusable kernel for future CLI or API surfaces.

Future iterations can expand coverage sources, integrate broker research, or
stream the timeline into alerting pipelines that notify teams whenever a
persona's sentiment crosses custom thresholds.
