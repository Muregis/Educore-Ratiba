-- Demo Academy: solvable primary school (class-teacher model)
-- Safe to re-run for school named 'Demo Academy' only after base rows exist.
-- Prefer PHP rebalanceSchoolForSolvability() on production.

-- Soft constraints
UPDATE subjects SET duration_slots = 1, min_days_between = 0
WHERE school_id = (SELECT id FROM schools WHERE name = 'Demo Academy' LIMIT 1);

UPDATE schools SET generation_time_limit = 300, prefer_spread = FALSE, school_type = 'primary'
WHERE name = 'Demo Academy';

-- Summary (tools_seed_demo skips SELECT)
SELECT 'rebalance markers applied' AS status;
