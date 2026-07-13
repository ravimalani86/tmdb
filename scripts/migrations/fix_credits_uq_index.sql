-- Fix credits unique index: prefix lengths for job/character (MySQL key too long)
-- Run once on existing databases. Safe to skip if index already matches.

ALTER TABLE `credits` DROP INDEX `uq_credit`;

ALTER TABLE `credits`
  ADD UNIQUE KEY `uq_credit` (
    `media_type`,
    `media_id`,
    `person_id`,
    `credit_type`,
    `job`(255),
    `character`(255)
  );
