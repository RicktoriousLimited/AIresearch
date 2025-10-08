# Semantic Workbench Architecture

The Semantic Workbench delivers a cohesive product layered on top of the
existing `SemanticEngine` extraction core. The diagram below outlines the three
primary surfaces and how they interact.

```
┌────────────────────┐         ┌──────────────────────────┐
│  Web Workbench UI  │  ─┐     │  CLI (`php index.php`)   │
└─────────┬──────────┘    │     └────────────┬────────────┘
          │               │                  │
          ▼               ▼                  ▼
     Fetch API     App\Extraction\Extractor  │
          │               │                  │
          └───────► SemanticEngine ◄─────────┘
```

## Components

### `App\Extraction\Extractor`

A thin orchestration layer that accepts one or many documents, routes them
through `SemanticEngine::extractRelations`, and returns a structured
`ExtractionResult`. The result includes:

- Canonical triples ready for display or export.
- Synonym clusters derived from the engine's internal synonym store.
- Relation and entity frequency histograms for quick analytics.
- A summary payload with document counts, triple totals, and timestamps.

### Web experience

- `web/index.php` bootstraps a lightweight SPA shell.
- `web/assets/workbench.js` coordinates API calls, renders results, and exposes
  download/copy affordances.
- `web/assets/workbench.css` styles the interface using a glassmorphism-inspired
  dark theme suitable for long research sessions.

### API

- `web/api/analyse.php` accepts `POST` payloads containing either `text` (single
  document) or `documents` (array of strings).
- Responses are JSON by default and support optional filtering by passing an
  `include` field with any of `triples`, `synonyms`, `relations`, `entities`, or
  `summary`.

### CLI

The CLI continues to read files and STDIN, allowing analysts to integrate the
engine into cron jobs, ETL pipelines, or local scripts. Snapshotting via
`--snapshot` remains unchanged.

## Extending the engine

New relation patterns can be added to `SemanticEngine::extractRelations`. The
workbench and API automatically surface the new relations without additional
changes thanks to the shared `Extractor` pipeline.

For advanced use cases consider decorating the `ExtractionResult` with custom
post-processing or plugging the API into downstream analytics systems.
