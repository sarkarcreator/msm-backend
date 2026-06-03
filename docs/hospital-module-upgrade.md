# Hospital Module Upgrade

## Scope

This upgrade is isolated to tenants whose `business_type` is `Hospital`. Other shop modules remain unchanged.

## New Hospital Roles

- Hospital Owner
- Receptionist
- Doctor
- Assistant / Compounder
- Nurse
- Pharmacy Staff
- Lab Technician
- X-Ray Technician
- Billing Officer

## Workflow

1. Reception registers a patient with token/MR number, visit type, department, doctor, fee, and status `Waiting`.
2. Doctor opens the queue and records symptoms, diagnosis, clinical notes, vitals, BP, sugar, temperature, and weight.
3. Doctor adds structured prescriptions with medicine, morning, afternoon, evening, night, and days.
4. Doctor selects orders such as injection, IV drip, lab tests, X-Ray, ultrasound, MRI, or admission.
5. Saving the patient automatically creates:
   - pharmacy prescription rows
   - injection/procedure tasks
   - lab reports
   - radiology reports
   - hospital bill and bill items

## Tables

- `hospital_prescriptions`
- `hospital_orders`
- `hospital_tasks`
- `lab_reports`
- `radiology_reports`
- `hospital_bills`
- `hospital_bill_items`

Patient registration fields are added to `patients` without dropping existing data.

## Multi-Tenant Safety

Hospital workflow records include `license_uuid`. Generic resource APIs filter hospital data by authenticated user's `license_uuid` unless the user is Super Admin.

## Offline Support

The React app adds IndexedDB stores for all hospital workflow entities. Patient registration and doctor workflow saves are queued for sync.

## Deployment

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```
