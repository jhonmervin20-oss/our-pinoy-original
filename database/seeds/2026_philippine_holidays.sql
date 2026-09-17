-- ---------------------------------------------------------------------------
-- Philippine holidays, 2026
--
-- SOURCE OF TRUTH: Proclamation No. 1006, s. 2025 (regular holidays and
-- special non-working days for 2026), plus the two Islamic holidays which that
-- proclamation deliberately leaves undated -- their dates depend on the Hijri
-- calendar and are declared separately once the National Commission on Muslim
-- Filipinos has recommended them:
--
--   Proclamation No. 1189, s. 2026 -- Eid'l Fitr,  Friday 20 March 2026
--   Proclamation No. 1264, s. 2026 -- Eid'l Adha,  Wednesday 27 May 2026
--
-- Both are REGULAR holidays under RA 9849, not special days.
--
-- WHY THE TYPE MATTERS HERE and is not cosmetic: forecast_service/engine/
-- forecasting.py pools holidays by holiday_type before fitting Prophet, so
-- 'regular' and 'special' become two separate regressors. Mislabelling one
-- moves a day's demand effect into the wrong pool and quietly degrades every
-- forecast that follows it. Regular holidays are the ones where most
-- establishments close and premium pay applies; special (non-working) days are
-- the "no work, no pay" tier -- meaningfully different footfall for a
-- restaurant, which is exactly why the two are modelled apart.
--
-- ONE DECLARED DAY IS DELIBERATELY ABSENT: 25 February 2026 (EDSA People Power
-- Revolution Anniversary) is a special WORKING day. Offices and businesses
-- operate normally and no holiday premium applies, so it is not a holiday in
-- any sense this table models. holiday_type has no value for it, and filing it
-- under 'special' would tell the forecaster to expect holiday demand on an
-- ordinary Wednesday.
--
-- Re-runnable: holiday_date is UNIQUE, so ON DUPLICATE KEY UPDATE refreshes an
-- existing row instead of failing. Running this twice changes nothing.
-- ---------------------------------------------------------------------------

INSERT INTO `holidays` (`holiday_date`, `holiday_name`, `holiday_type`, `is_active`) VALUES
    -- ---- Regular holidays (12) ------------------------------------------
    ('2026-01-01', 'New Year''s Day',                            'regular', 1), -- Thursday
    ('2026-03-20', 'Eid''l Fitr (Feast of Ramadhan)',            'regular', 1), -- Friday    (Proc. 1189)
    ('2026-04-02', 'Maundy Thursday',                            'regular', 1), -- Thursday
    ('2026-04-03', 'Good Friday',                                'regular', 1), -- Friday
    ('2026-04-09', 'Araw ng Kagitingan',                         'regular', 1), -- Thursday
    ('2026-05-01', 'Labor Day',                                  'regular', 1), -- Friday
    ('2026-05-27', 'Eid''l Adha (Feast of Sacrifice)',           'regular', 1), -- Wednesday (Proc. 1264)
    ('2026-06-12', 'Independence Day',                           'regular', 1), -- Friday
    ('2026-08-31', 'National Heroes Day',                        'regular', 1), -- last Monday of August
    ('2026-11-30', 'Bonifacio Day',                              'regular', 1), -- Monday
    ('2026-12-25', 'Christmas Day',                              'regular', 1), -- Friday
    ('2026-12-30', 'Rizal Day',                                  'regular', 1), -- Wednesday

    -- ---- Special (non-working) days (8) ---------------------------------
    ('2026-02-17', 'Chinese New Year',                           'special', 1), -- Tuesday
    ('2026-04-04', 'Black Saturday',                             'special', 1), -- Saturday
    ('2026-08-21', 'Ninoy Aquino Day',                           'special', 1), -- Friday
    ('2026-11-01', 'All Saints'' Day',                           'special', 1), -- Sunday
    ('2026-11-02', 'All Souls'' Day',                            'special', 1), -- Monday
    ('2026-12-08', 'Feast of the Immaculate Conception of Mary', 'special', 1), -- Tuesday
    ('2026-12-24', 'Christmas Eve',                              'special', 1), -- Thursday
    ('2026-12-31', 'Last Day of the Year',                       'special', 1)  -- Thursday
ON DUPLICATE KEY UPDATE
    `holiday_name` = VALUES(`holiday_name`),
    `holiday_type` = VALUES(`holiday_type`),
    `is_active`    = VALUES(`is_active`);
