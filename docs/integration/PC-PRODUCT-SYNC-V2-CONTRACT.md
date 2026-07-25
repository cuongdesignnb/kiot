# KIOT Product Provider V2 contract

## Scope and compatibility

KIOT is the source of truth for product, category, selected selling price, inventory availability, repair state and product images. Stable provider keys are the numeric KIOT `product.id`, `category.id` and `price_book.id`. SKU is case-sensitive conflict metadata, not the cross-system primary key.

The existing `/api/integrations/v1/pc/products` endpoint is extended additively. Existing v1 flat pricing and inventory fields remain present. Services remain outside the PC product contract. No endpoint hard-deletes consumer data.

## Authentication

All catalog endpoints use the existing feature flag and HMAC middleware. The signature covers the exact raw body with canonical input:

```text
METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256_BODY
```

Timestamp tolerance, atomic nonce replay protection, exact client ID, current/rotating secret, enabled client state and per-client rate limiting remain mandatory. Secrets, signatures and raw authorization headers must never be logged.

## Endpoints

- `GET /api/integrations/v1/pc/products`
- `GET /api/integrations/v1/pc/products/{sku}`
- `GET /api/integrations/v1/pc/categories`
- `GET /api/integrations/v1/pc/price-books`

List endpoints accept `limit` (1–100), opaque `cursor`, `updated_since` and `include_inactive`. Products additionally accept exact-case `sku` and numeric `id`. Invalid cursors return `422 INVALID_CURSOR`.

Ordering is stable by `(updated_at, id)`. `updated_since` is inclusive. When it is supplied, inactive and soft-deleted tombstones are included. Consumers must upsert by stable numeric ID and treat `sync_status=deleted` as a soft tombstone.

## Category publishing

KIOT product groups are Website PC categories and use the stable numeric `category.id` as `remote_category_id`; names are display data only. Each group has an explicit `show_on_pc_website` flag. The current schema has no equivalent legacy flag, so the additive migration uses the fail-closed default `false` for existing and new rows. An operator must opt a group in through Settings; no repair/service group name is hard-coded.

Category visibility, active state, rename and parent changes update the category cursor timestamp and touch related product timestamps. Products remain representable when blocked and expose `publishing.show_on_pc_website=false` with a machine-readable reason such as `CATEGORY_NOT_PUBLISHED`. Category parent validation rejects self/descendant cycles without replacing the stable category ID.

## Product pricing

The PC integration client stores one nullable `pc_product_price_book_id`. Only active, non-deleted, in-date price books may be selected. A non-negative `price_book_products.price`, including zero, becomes `selected_price`. Missing or unusable selected pricing falls back to the non-negative product retail price with `fallback_used=true`.

`base_price` currently equals the public retail price because KIOT has no separate public base selling-price column. Cost, last purchase price, inventory total cost and supplier data are never returned.

## Inventory and repair aggregation

For non-serial products, available quantity is `max(0, stock_quantity - active_external_reservations)`.

For serial products, `stock_quantity` remains the physical in-stock count maintained by KIOT. Website-ready quantity counts `status=in_stock` serials whose `repair_status` is neither `not_started` nor `repairing`, then subtracts active external reservations. If all physical stock is under repair, status is `repairing`, `available_quantity=0` and `is_available=false`. Mixed ready/repair stock can remain available while `is_under_repair=true`.

Normalized status values are `available`, `repairing`, `reserved`, `sold`, `inactive` and `deleted`.

## Product images

Uploads accept only actual JPEG, PNG or WebP image content, enforce configured file/count/pixel limits, decode the image and generate an optimized WebP. Storage paths are generated server-side using Laravel storage; client filenames never determine a path. API URLs are absolute HTTPS URLs and filesystem paths/disks are hidden.

Each image includes SHA-256 checksum, dimensions, stable sort order and primary state. A database uniqueness guard plus transactional service guarantees at most one primary image per product. Deleting a primary selects the next image or leaves the primary null. File operations include compensation on database failure.

## Capabilities

Handshake capabilities after Phase 3A:

```json
{
  "orders": true,
  "products": true,
  "categories": true,
  "product_images": true,
  "price_books": true,
  "repair_status": true,
  "google_sheets": false
}
```

## Migration and rollback

Three additive migrations create `product_images`, add category synchronization/publishing fields and soft deletes, and add the nullable price-book foreign key to `integration_clients`. They do not rewrite product, price or production credential data. Existing category rows use stable API fallback code `CAT-{id}` until edited or explicitly backfilled. The publishing column is populated by its database default `false`; no mandatory data backfill job is required.

Rollback order is integration-client price-book FK, category sync columns, then product images. Before rolling back product images in an environment containing uploads, export the table and preserve `storage/app/public/products`; schema rollback cannot reconstruct deleted image metadata. Expected lock risk is low-to-moderate metadata locking while adding indexed nullable/default columns; schedule production migration normally and do not run destructive reset/refresh commands.

The canonical consumer fixture is `tests/Fixtures/PcIntegration/product-provider-v2.json`.
