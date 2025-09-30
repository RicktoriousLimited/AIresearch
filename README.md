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
