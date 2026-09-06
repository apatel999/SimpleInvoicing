ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS internal_notes TEXT NULL AFTER notes;
