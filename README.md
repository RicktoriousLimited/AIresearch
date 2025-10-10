# AIresearch Research Intelligence Workspace

AIresearch centralises research-grade ingestion, enrichment, and delivery inside a single
knowledge graph so analysts can move from signal capture to shareable insight without juggling
tools. The project now focuses on a set of tightly-integrated research experiences:

1. **Research home (`index.php`)** – Explore curated queries, track live ingestion metrics, and jump
   straight into the graph-powered search workflow from a redesigned landing experience.
2. **Graph search (`search.php`)** – Query the collective research graph, inspect entities and
   relationships, and follow citations back to their source passages.
3. **Knowledge graph explorer (`knowledge-graph.php`)** – Browse the underlying triples, relation
   histograms, and source list that power every briefing.
4. **Research API (`/api`)** – Submit documents for extraction, scrape URLs into the shared graph, or
   orchestrate updates from automation.
5. **Command line tooling (`cli.php`, `research.php`)** – Automate ingestion, run quality checks, and
   export structured facts for downstream systems.

Legacy market and commerce experiments have been archived; see [`archive/README.md`](archive/README.md)
for the status of retired surfaces.

## Quick start

### Web workspace

```bash
php -S 0.0.0.0:8000
```

Open `http://localhost:8000/index.php` to launch the new research home. From here you can:

- Run graph search directly from the hero form and reuse trending analyst prompts with one click.
- Monitor live coverage metrics pulled from the shared ingestion pipeline.
- Explore curated evidence libraries grouped by strategic themes, ready to seed your next briefing.
- Review the end-to-end workflow for framing a question, interrogating the graph, and exporting
  deliverables with citations.

### Discovery search

The discovery interface exposes the shared knowledge graph as a public search engine.

```bash
php -S 0.0.0.0:8000
```

Open `http://localhost:8000/search.php` to:

- Query people, organisations, technologies, or relations across every extracted triple.
- Browse entity matches with confidence scores, synonym clusters, and fact counts.
- Inspect relation histograms, synonym groups, and knowledge triples that mention your query in real
  time.
- Drill into an entity to see supporting facts, relation highlights, and linked sources.

### Scrape public URLs into the shared knowledge graph

- From the workspace, paste a URL into **Scrape from URL** and click **Fetch & analyse** to ingest the
  page into the shared graph stored in `storage/graphs/`.
- Visit `http://localhost:8000/knowledge-graph.php` to browse the combined triples, relations, and
  source list.
- Automate ingestion by POSTing `{ "url": "https://example.com/article" }` to `/api/scrape.php`; the
  endpoint merges new entities into the existing graph snapshot and returns the updated state.

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
      "documents_received": 1,
      "documents_processed": 1,
      "triples": 4,
      "synonym_groups": 1,
      "unique_entities": 3,
      "generated_at": "2024-05-30T12:34:56+00:00"
    },
    "state": {
      "graph": { "isa": { "alice smith": { "senior data scientist": true } } },
      "synonyms": { "alice smith": { "ally smith": true } }
      // ...
    }
  },
  "meta": {
    "documents": 1,
    "documents_processed": 1,
    "processing_time_ms": 4
  }
}
```

Supply an array via `documents` to analyse multiple snippets at once. Use the optional `include`
field with any of `triples`, `synonyms`, `relations`, `entities`, `summary`, or `state` to limit the
response payload. Provide the `state` field from a previous response to incrementally extend the same
knowledge graph with new documents over time.

Every response also contains a `dataset` object with:

- `rows` – prompt/response pairs covering cleaning, summarisation, keyword extraction, and entity graph
  reconstruction workflows.
- `schema` – machine-readable field descriptions enriched with canonical relation metadata (type,
  confidence, status, and provenance hints) to plug directly into downstream tooling.
- `statistics` – record counts, average length, task distribution, and per-relation breakdowns to help
  size annotation or fine-tuning jobs while spotting extraction skew early.

Download the rows directly from the workspace UI (JSON or CSV) or consume them via the API for
automated dataset generation.

### CLI

```bash
php cli.php sample.txt -f json -e triples -e synonyms
```

The CLI supports JSON, CSV, and text exports, snapshot persistence, and STDIN piping. Run
`php cli.php --help` for the full option list.

### Research CLI

```bash
php research.php --list
php research.php --entity "Alice Smith"
php research.php --refresh --max-age=24
```

The dedicated research helper reads the shared graph snapshot from
`storage/graphs/scraped-graph.json` (or a custom path supplied via `--graph`). Use `--list` to surface
the highest-ranked entities and `--entity` to inspect detailed facts, synonyms, relation histograms,
and supporting signals for a specific person, organisation, or concept. Combine flags to list the graph
and immediately drill into an entity, and adjust `--facts` or `--limit` to control the breadth of the
output. Run with `--refresh` to re-verify every stored source, rebuild the knowledge base, and
automatically prune pages that have disappeared or no longer resolve. Supply `--crawl` alongside comma
or newline separated seed URLs to auto-scrape new pages, follow discovered links, and stream them
straight into the shared graph. Combine with `--crawl-limit`, `--crawl-depth`, and
`--crawl-cross-domain` to fine-tune how aggressively the crawler expands across each site.

## Archived experiences

Legacy dashboards such as the markets intelligence pulse and the Ricktorious commerce demo have been
retired from the default navigation. Refer to [`archive/README.md`](archive/README.md) for guidance on
accessing historical assets or replaying those experiments locally.
