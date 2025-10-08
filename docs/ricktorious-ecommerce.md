# Ricktorious Limited Ecommerce Platform Vision

This document captures the target architecture for transforming the existing
semantic tooling codebase into an extensible, AI-driven ecommerce experience
for **Ricktorious Limited**. The focus is on creating a block-based, content
managed storefront that can evolve through lightweight extensions.

## Guiding Principles

1. **Extension-first** – Every capability should be delivered through modular
   extensions. Core services provide lifecycle hooks and shared utilities while
   feature bundles live in self-contained packages.
2. **Block-based experience** – Storefront pages are composed of content blocks
   that can be re-ordered, re-configured, and swapped without editing PHP view
   templates. Blocks expose schema metadata so low-code editors can surface
   friendly controls.
3. **Rich content management** – Merchandisers require structured page
   definitions, draft/publish workflows, and the ability to seed dynamic product
   collections. The platform should remain storage-agnostic to allow swapping a
   database or headless CMS later in the project.
4. **AI-first personalisation** – Behavioural data collection feeds a
   personalisation engine that suggests content blocks and merchandising rules
   to improve engagement.
5. **Ad-hoc APIs** – A lightweight router exposes JSON endpoints for extensions
   to serve configuration UIs, analytics dashboards, or integration hooks.

## High-level Components

- **Application kernel** – Coordinates bootstrapping, extension registration,
  content rendering, and API dispatch.
- **Block registry** – Stores block definitions, their default settings, and
  render callbacks. Blocks are namespaced by extension and must describe their
  configurable schema.
- **Content manager** – Maintains structured page definitions that reference
  block instances. It abstracts the persistence layer so data can be stored in
  files, a relational database, or a remote CMS.
- **Extension manager** – Handles discovery, bootstrapping, and lifecycle hooks
  for extension packages. Extensions can register blocks, seed content, and
  attach API routes.
- **Ad-hoc API router** – Minimal HTTP router that supports extension-provided
  endpoints without committing to a heavy framework.
- **User behaviour tracker** – Normalises, stores, and exposes behavioural
  events (product views, cart additions, etc.).
- **AI personalisation engine** – Consumes behavioural events to compute
  real-time insights and recommend high-performing block combinations.

## Initial Deliverable

## Current Capabilities

The storefront now moves beyond scaffolding and demonstrates a cohesive
commerce experience built on the Ricktorious kernel:

- **Catalogue domain** – `Ricktorious\Ecommerce\Catalog` loads structured
  product data from JSON, powering featured merchandising blocks and a dynamic
  catalog page.
- **Cart and checkout services** – Session-based cart management and a
  lightweight checkout pipeline live in `Ricktorious\Ecommerce\Checkout`, with
  orders persisted to `storage/orders` for later analysis.
- **Commerce extension** – A dedicated `CommerceExtension` wires catalogue,
  cart, checkout, and API routes into the runtime without bloating the core
  extension.
- **Storefront application** – `web/ricktorious.php` now handles product detail
  views, cart interactions, checkout flows, and JSON API calls while continuing
  to showcase block-driven content.
- **Expanded API** – `/api/catalog/products`, `/api/cart/summary`, `/api/cart/add`,
  `/api/checkout`, and `/api/insights` provide headless integration points for
  experimentation.
- **Operations suite** – A CRM/POS extension exposes `/api/crm/*` and
  `/api/pos/sale` endpoints, persisting unified customer profiles, interaction
  history, and in-person sales ledgers that feed personalisation and analytics
  surfaces.

Future work can iterate on persistent storage, authentication, pricing rules,
inventory updates, and administrative tooling while reusing the extension-first
composition laid out here.
