# AIresearch Semantic Extraction CLI

This repository exposes the `SemanticEngine` through a small command line
utility (`index.php`). The command ingests free-form biographies or research
summaries and extracts lightweight knowledge graph triples and synonym
relationships.

## Usage

```
php index.php [options] [file ...]
```

When no files are supplied, the command reads from `STDIN`. You can also mix
files with `-` to explicitly read from `STDIN` alongside other inputs.

> **Note:** Entities and relation labels are normalised to lower-case tokens
> without punctuation. For example, the `lives_in` relation becomes `livesin` in
> the exported data.

### Options

| Option | Description |
| ------ | ----------- |
| `-h, --help` | Show inline help and exit. |
| `-f, --format FORMAT` | Output format: `text` (default), `json`, or `csv`. |
| `-e, --export TYPE` | Select data to export: `triples` or `synonyms`. Repeat the option to export both. |
| `-o, --output PATH` | Write the formatted output to the provided path instead of `STDOUT`. |

## Examples

### Reading from a file

```
cat > sample.txt <<'EOF'
Alice Smith is a Senior Data Scientist. Alice Smith aka Ally Smith. Alice Smith lives in Birmingham.
EOF

php index.php sample.txt
```

Output (default `text` format):

```
Triples:
- alice smith | isa | senior data scientist
- alice smith | synonym | ally smith
- ally smith | synonym | alice smith
- alice smith | livesin | birmingham
Synonyms:
- alice smith => ally smith
- ally smith => alice smith
```

### Reading from STDIN

```
cat <<'EOF' | php index.php -f json
Ricktorious Limited is a technology company. Ricktorious Limited aka Ricktorious Ltd.
EOF
```

JSON output:

```json
{
    "triples": [
        {
            "subject": "ricktorious limited",
            "relation": "isa",
            "object": "technology company"
        },
        {
            "subject": "ricktorious limited",
            "relation": "synonym",
            "object": "ricktorious ltd"
        },
        {
            "subject": "ricktorious ltd",
            "relation": "synonym",
            "object": "ricktorious limited"
        }
    ],
    "synonyms": [
        {
            "entity": "ricktorious limited",
            "synonyms": [
                "ricktorious ltd"
            ]
        },
        {
            "entity": "ricktorious ltd",
            "synonyms": [
                "ricktorious limited"
            ]
        }
    ]
}
```

### CSV export

CSV export is limited to a single data type per invocation. The example below
writes triples to `triples.csv`.

```
php index.php -f csv -e triples sample.txt -o triples.csv
cat triples.csv
```

Contents:

```
subject,relation,object
"alice smith",isa,"senior data scientist"
"alice smith",synonym,"ally smith"
"ally smith",synonym,"alice smith"
"alice smith",livesin,birmingham
```

To export synonyms instead:

```
php index.php -f csv -e synonyms sample.txt
```

## Error handling

The command validates option values and reports descriptive errors when inputs
cannot be read or when no text is provided. Use `php index.php --help` to view a
summary of all available options.

## Ricktorious Limited Ecommerce Experience

The prototype storefront has evolved into a fully interactive ecommerce
experience. Launch it locally with:

```
php -S 0.0.0.0:8000 -t web
```

Then browse to `http://localhost:8000/ricktorious.php` to explore:

- **Dynamic catalogue** – Products are sourced from `storage/catalog/products.json`
  and rendered via reusable blocks and catalog templates.
- **Session-aware cart and checkout** – Visitors can add items to a cart,
  update quantities, and complete a lightweight checkout flow that persists
  orders as JSON payloads under `storage/orders`.
- **API surface** – The ad-hoc router now exposes catalogue, cart, checkout,
  and personalisation endpoints for experimentation with headless clients.
- **Personalisation telemetry** – Behaviour is tracked across page views,
  cart updates, and orders to feed the bundled AI recommendation engine.
- **Operations hub** – A CRM and point-of-sale extension unifies customer
  profiles, interaction logs, and in-person sales captured via `/api/crm/*`
  and `/api/pos/sale` endpoints.
- **Order processing & shipping** – `/api/orders/*` and `/api/shipping/quotes`
  expose status transitions, shipment creation, and live rate quoting backed
  by a fulfilment workflow with shipment ledger persistence under
  `storage/shipping`.
- **User management** – `/api/users/*` endpoints support registration,
  authentication, role assignment, and profile updates powered by the new
  user directory stored in `storage/users`.
- **Content governance** – `/api/content/*` endpoints wrap the block registry
  with draft saving, publication workflows, and revision history accessible via
  the enhanced in-memory content manager.
- **Multi-portal experience** – `/ricktorious.php/client-hub`,
  `/ricktorious.php/operations`, and `/ricktorious.php/partners` introduce a
  client success centre, staff operations console, and partner innovation hub.
  Each page layers tailored analytics, workflows, and API guidance on top of
  the shared commerce services so every stakeholder can manage the platform
  without leaving the browser.

### Headless storefront

The `web/storefront.php` single-page experience consumes the commerce APIs to
deliver a fully interactive storefront for Ricktorious Limited. Start the same
development server and open `http://localhost:8000/storefront.php` to try:

- Dynamic catalogue rendering powered by `/api/catalog/products`.
- Cart and checkout orchestration via `/api/cart/*` and `/api/checkout`.
- Behavioural telemetry surfaced from `/api/insights` alongside the
  `/api/analytics/events` endpoint for recording frontend interactions.

Consult `docs/ricktorious-ecommerce.md` for an overview of the platform
architecture and extension system.

### Deploy in minutes

- **Docker** – Build and run the bundled `Dockerfile` (or use
  `docker compose up`) to start an Apache + PHP 8.2 container that serves the
  storefront from the `web/` document root with persistent storage mounts.
- **Shared hosting** – Upload the `web/`, `src/`, and `storage/` directories
  and point your virtual host at `web/`. Ensure the storage directory remains
  writable for order capture and telemetry.
- **Health checks** – `/health.php` exposes a lightweight JSON heartbeat that
  load balancers and uptime monitors can call.

See `docs/deployment.md` for the full deployment checklist and environment
notes.
