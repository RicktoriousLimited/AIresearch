# Deployment Guide

The Ricktorious Limited storefront ships as a PHP application with no external
service dependencies. This guide walks through three supported deployment
options so you can get the experience online today.

## 1. Run locally with PHP's built-in server

```bash
php -S 0.0.0.0:8000 -t web
```

Visit `http://localhost:8000/storefront.php` for the headless storefront or
`http://localhost:8000/ricktorious.php` for the API surface.

## 2. Containerised deployment

1. Build the production image:
   ```bash
   docker build -t ricktorious-storefront .
   ```
2. Launch the containerised stack with persistent storage for orders and
   telemetry:
   ```bash
   docker compose up -d
   ```
3. Browse to `http://localhost:8080/storefront.php`.

The container uses the official `php:8.2-apache` image with the document root
configured to `web/`. Storage directories are mounted as a volume so that order,
shipping, POS, and user data survive restarts.

## 3. Shared hosting / Apache

1. Upload the `web/`, `src/`, and `storage/` directories along with `index.php`
   to your hosting environment.
2. Point the virtual host's document root at the `web/` directory.
3. Ensure the `storage/` directory is writable by the web server user.

### Health check endpoint

The `/health.php` endpoint returns a JSON payload and can be wired into your
load balancer or uptime monitoring service:

```json
{"status":"ok","timestamp":"2024-01-01T12:00:00+00:00"}
```

### Environment configuration

Ricktorious stores catalogue and operational data under `storage/`. To start
fresh in a new environment you can safely delete the nested directories:

- `storage/orders/`
- `storage/shipping/`
- `storage/pos/`
- `storage/users/`

The catalogue lives at `storage/catalog/products.json` and can be updated with
your own merchandise before deployment.
