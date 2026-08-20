# P0 — Shared Media Library

## Scope

This change introduces one authenticated, global media library for the image
entry points currently present in the application: product galleries,
product variants, customer/supplier avatars, employee avatars, and the
Settings media-library page. A media asset is stored once and linked to any
number of objects.

## Storage and data-safety contract

- New uploads accept real JPG, PNG, and WebP image content, with a 5 MB file
  limit and a 40 million pixel limit.
- New uploads are converted to WebP quality 82 and store four variants:
  `thumb` (160px), `small` (480px), `medium` (1280px), and `original`.
- The source JPG/PNG upload is never retained by the new upload path.
- SHA-256 deduplication prevents a repeated upload from creating another
  media asset.
- Existing `product_images`, `customers.avatar`, `employees.avatar`, and
  `product_variants.image` values remain valid legacy projections/fallbacks.
- The migration adds nullable links only. It does not move, delete, or
  backfill legacy files.
- Removing a link does not remove a library asset. Permanent deletion is
  restricted to `settings.manage`, is blocked with `MEDIA_IN_USE` while any
  link remains, and uses restrictive foreign keys.
- External URLs are not downloaded automatically.

## API contract

```text
GET    /api/media
POST   /api/media
GET    /api/media/{media}
GET    /api/media/{media}/usages
DELETE /api/media/{media}
```

All media endpoints require an authenticated user with an applicable view,
create, edit, or settings permission. Permanent delete additionally requires
`settings.manage`. Delete while linked returns HTTP 409:

```json
{
  "code": "MEDIA_IN_USE",
  "message": "Ảnh đang được sử dụng và chưa thể xóa.",
  "usages": []
}
```

Existing product image upload payloads remain accepted. New consumers may use
`media_ids[]`, `primary_media_id`, `avatar_media_id`, and
`variants[].image_media_id`; old URL/image fields remain in responses.

## Backfill and rollout

The command below is intentionally separate from migrations and supports an
operator-reviewed dry run:

```text
php artisan media:library-backfill --dry-run --json
php artisan media:library-backfill --backfill --chunk=100 --json
```

The command registers internal legacy files and creates missing variants but
does not move or delete legacy files. It is idempotent through the media
checksum. Production backfill is `NO` until an operator reviews the dry-run
report and approves it. No production command was run as part of this change.

## Implementation files

Backend additions include the media variant model, asset/usage services,
authenticated API controller, Settings page controller, migration, and
backfill command. Existing product image persistence, product/customer/
employee controllers, PC Website serialization, and relevant models now read
the central media relation first and retain legacy fallback behavior.

Frontend additions include `MediaPicker` and the Settings library page.
Product Create/Edit/Quick Create, product variants, customer forms, and
employee forms use the shared picker without browser alerts.

## Verification evidence

The following evidence was collected in the disposable MySQL QA database
`sales_test` on MySQL 8.0. No production connection or command was used:

```text
MIGRATION_MYSQL=PASS
MIGRATION_ROLLBACK_MYSQL=PASS
MIGRATION_MARIADB=PASS
MIGRATION_ROLLBACK_MARIADB=PASS
MEDIA_UPLOAD_WEBP_VARIANTS=PASS
MEDIA_CHECKSUM_DEDUPLICATION=PASS
MEDIA_SHARED_LINKS=PASS
MEDIA_IN_USE_DELETE_BLOCK=PASS
MEDIA_UNUSED_DELETE=PASS
BACKFILL_DRY_RUN=PASS
BACKFILL_IDEMPOTENCY=PASS
LEGACY_FILE_DELETION=NO
FRONTEND_BUILD=PASS
PINT=PASS
PHP_LINT=PASS
DIFF_CHECK=PASS
MEDIA_CONTRACT_TESTS=5 tests / 36 assertions PASS
PRODUCT_IMAGE_REGRESSION=8 tests / 70 assertions PASS
BROWSER_LOGIN_UI=PASS
AUTHENTICATED_BROWSER_QA=PASS
BROWSER_QA=PASS
BROWSER_ENGINE=CODEX_IN_APP_BROWSER
BROWSER_QA_ORIGIN=http://127.0.0.1:8894
BROWSER_QA_DATABASE=sales_test
BROWSER_NO_JAVASCRIPT_ALERT=PASS
BROWSER_CONSOLE_ERRORS=NONE
USER_CHROME_ACCESSED=NO
```

Manual browser evidence was collected in the disposable MySQL database using
the isolated in-app browser at `http://127.0.0.1:8894`:

```text
LOGIN_UI=PASS — authenticated home page rendered without HTTP 419.
SETTINGS_MEDIA_LIBRARY=PASS — library, search, source filter, upload control,
  preview, usage count and inline state rendered.
MEDIA_UPLOAD_UI=PASS — a local PNG uploaded; the library displayed the asset
  with dimensions/size and no browser alert.
MEDIA_USAGE_FILTERS=PASS — used/unused filters returned the expected result.
PRODUCT_CREATE_MEDIA_PICKER=PASS — selected asset displayed and count updated
  from 0/12 to 1/12.
PRODUCT_EDIT_MEDIA_PICKER=PASS — seeded QA product edit page exposed the shared
  picker and the existing image manager.
PRODUCT_QUICK_CREATE_MEDIA_PICKER=PASS — POS quick-create form exposed the
  shared product image picker.
PRODUCT_VARIANT_MEDIA_PICKER=PASS — disposable Màu/Đen variant opened the
  shared picker and retained the selected asset.
CUSTOMER_SUPPLIER_DUAL_ROLE_AVATAR_PICKER=PASS — customer form exposed the
  single-avatar picker and dual-role option in the same form.
EMPLOYEE_AVATAR_PICKER=PASS — employee form exposed the same library picker.
NO_BROWSER_ALERT=PASS — no JavaScript alert/dialog appeared during the run.
CONSOLE_ERRORS=NONE — no browser error log was observed on the final page.
```

Automated coverage is in
`tests/Feature/Media/SharedMediaLibraryContractTest.php`. The automated suite
also confirms the old image URL payloads remain available and that unlinking
one object leaves other links intact.

The dry-run on the empty fixture reported zero pending legacy rows and zero
external/unresolved values. The idempotency test registers one internal legacy
file, links it once, reruns the command without creating a second media asset,
and confirms the legacy file remains in place. Migration up/down was also
verified on MariaDB 10.11 with a compatible disposable collation. The
authenticated browser gate above was completed without accessing the user's
Chrome profile or any production endpoint.

## Rollback plan

1. Disable navigation to the Settings media-library page if a UI rollback is
   needed.
2. Revert the application commit; dual-read paths continue to use legacy
   fields when media links are null.
3. Only after application rollback, run the migration rollback in the
   operator-approved maintenance window. The migration rollback removes only
   the nullable media links/variant metadata added by this change.
4. Do not delete legacy image files or run a production backfill as part of a
   rollback.

## Current status

```text
LIBRARY_VISIBILITY=GLOBAL
V1_SCOPE=ALL_CURRENT_IMAGE_POINTS
NEW_UPLOAD_STORAGE=WEBP_ONLY
DERIVED_VARIANTS=YES
GLOBAL_DEDUPLICATION=SHA256
DELETE_WHEN_IN_USE=BLOCK
UNLINK_DOES_NOT_DELETE_FILE=YES
LEGACY_FILE_DELETION_IN_V1=NO
EXTERNAL_URL_AUTO_DOWNLOAD=NO
PRODUCTION_BACKFILL=OPERATOR_APPROVAL_ONLY
PRODUCTION_ACCESSED=NO
PRODUCTION_MUTATED=NO
```
