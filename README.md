# AIresearch Semantic Workbench

An end-to-end environment for transforming unstructured bios, research notes, and
company updates into lightweight knowledge graph triples. The project now ships
with three fully-supported experiences built on top of the same semantic core:

1. **Semantic Workbench UI** – A modern web experience for pasting text,
   exploring the extracted triples, reviewing synonym clusters, and exporting
   results.
2. **JSON API** – `/api/analyse.php` accepts POST payloads and returns structured
   extraction data for automation and integrations.
3. **Command Line Interface** – The original `php index.php` utility remains for
   batch processing, scripting, and CSV/JSON exports.

Under the hood every surface uses the same `SemanticEngine`, making it easy to
swap between manual exploration, automated pipelines, and command-line
experiments without sacrificing consistency.

## Quick start

### Web workbench

```bash
php -S 0.0.0.0:8000 -t web
```

Open `http://localhost:8000/index.php` and paste a few biographies or company
summaries into the textarea. Click **Run extraction** to see:

- A summary of processed documents, triple count, and unique entities.
- Relation and entity frequency breakdowns for rapid insight discovery.
- Detailed triples and synonym groups with export controls.

Use the **Download JSON** action to save the raw result, or **Copy summary** to
move quick stats into other tools.

### API

`POST /api/analyse.php`

```json
{
  "text": "Alice Smith is a Senior Data Scientist. Alice Smith aka Ally Smith."
}
```

Response (truncated):

```json
{
  "data": {
    "triples": [
      { "subject": "alice smith", "relation": "isa", "object": "senior data scientist" },
      { "subject": "alice smith", "relation": "synonym", "object": "ally smith" }
    ],
    "synonyms": [
      { "entity": "alice smith", "synonyms": ["ally smith"] }
    ],
    "relations": { "isa": 1, "synonym": 2 },
    "entities": { "alice smith": 3, "senior data scientist": 1, "ally smith": 1 },
    "summary": {
      "documents_processed": 1,
      "triples": 4,
      "synonym_groups": 1,
      "unique_entities": 3,
      "generated_at": "2024-05-30T12:34:56+00:00"
    }
  },
  "meta": {
    "documents": 1,
    "processing_time_ms": 4
  }
}
```

Supply an array via `documents` to analyse multiple snippets at once. Use the
optional `include` field with any of `triples`, `synonyms`, `relations`,
`entities`, or `summary` to limit the response payload.

### CLI

```bash
php index.php sample.txt -f json -e triples -e synonyms
```

The CLI continues to support JSON, CSV, and text exports, snapshot persistence,
and STDIN piping. Run `php index.php --help` for the full option list.

## Data model

The unified extraction result returned by the UI, API, and CLI consists of:

- `triples` – array of `{subject, relation, object}`.
- `synonyms` – array of `{entity, synonyms[]}`.
- `relations` – relation frequency histogram.
- `entities` – entity frequency histogram.
- `summary` – metadata about the run including timestamps and document count.

All entity names are normalised and lowercased to ensure consistent matching.

## Development

- The shared service layer lives under `src/App/Extraction` and wraps the core
  `SemanticEngine` for stable results across every interface.
- Frontend assets for the workbench are in `web/assets/workbench.*`.
- The ecommerce prototype that previously shipped with the project now lives at
  `web/ricktorious.php` for archival purposes but is no longer part of the
  primary product experience.

Contributions and feature ideas are welcome—open an issue or submit a pull
request if you build additional relation detectors, data exporters, or UI
modules.
