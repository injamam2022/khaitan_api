-- Run once if `contact_enquiries` exists but lacks `form_source` (instead of php spark migrate).

ALTER TABLE `contact_enquiries`

  ADD COLUMN `form_source` VARCHAR(120) NULL DEFAULT NULL AFTER `pin`;

