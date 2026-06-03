# Pakistan Medicine Master Database

## Purpose

The Medicine Master Database stores centralized medicine definitions for pharmacy and hospital workflows. It is designed for 100,000+ medicines and future DRAP dataset imports without schema redesign.

## API

All routes require Laravel Sanctum authentication.

- `GET /api/medicines`
- `GET /api/medicines/search?q=panadol`
- `GET /api/medicines/{uuid}`
- `POST /api/medicines`
- `PUT /api/medicines/{uuid}`
- `DELETE /api/medicines/{uuid}`
- `POST /api/medicines/import`
- `GET /api/medicine-categories`
- `GET /api/medicine-manufacturers`

## Import Columns

```text
brand_name,generic_name,composition,strength,dosage_form,therapeutic_class,manufacturer,distributor,registration_no,barcode,pack_size,category,purchase_price,sale_price,mrp,tax_percentage,reorder_level,batch_tracking,expiry_tracking,status
```

Common aliases are accepted: `brand`, `medicine_name`, `generic`, `formula`, `form`, `drap_no`, and `pack`.

## Duplicate Detection

The importer updates existing records when any match is found:

- barcode
- registration_no
- brand_name + generic_name + strength

## Performance

The medicines table includes indexes for barcode, registration number, brand, generic, manufacturer, category, dosage form, status, and full-text search over brand/generic/composition/manufacturer.

## Offline Frontend

The React app caches medicines in IndexedDB store `medicines`, supports offline lookup, CSV import, and queued sync. XLSX import is processed by the Laravel backend when online.

## Deployment

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```
