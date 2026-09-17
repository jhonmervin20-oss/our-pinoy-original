# Assumptions -- demand-classification + inventory-policy engine

Plain list of what was guessed or decided while building `engine/`,
`app.py`'s `/forecast_policy` endpoint, `run_example.py`, and
`backtest.py`. Written for the developer maintaining this, not for
marketing copy.

## Architecture

- **No DB access in this Python service, by design.** PHP owns all reads
  and writes; this service is a stateless calculator called over HTTP.
  `data_prep.py` only exists to demonstrate/validate the BOM math for the
  offline deliverables (`run_example.py`, `backtest.py`) -- it is not on
  the live `/forecast_policy` request path. In production, PHP is assumed
  to build the dense daily ingredient-quantity series itself (from its
  own `order_items` / `menu_item_ingredients` / `recipes` tables) and send
  it straight to `/forecast_policy` as the `history` array.
- `history` is assumed **pre-aggregated to daily grain and dense** before
  it reaches this service -- every calendar day present, zero-filled on
  quiet days, sorted or sortable by date. Both `/forecast` (pre-existing)
  and `/forecast_policy` (new) make this same assumption; neither service
  re-buckets or gap-fills a sparse/irregular input series itself, beyond
  what `app.py`'s `/forecast_policy` handler does when it sorts by date
  and builds the pandas index.
- `MIN_HISTORY_DAYS = 14` is reused verbatim from the existing PHP-side
  `MIN_PROPHET_HISTORY_DAYS` convention in
  `owner/includes/demand_forecast_functions.php`, not re-derived.

## data_prep.py / BOM math

- `order_items_df` is assumed already aggregated to one row per
  `(date, menu_item_id)` with that day's total quantity sold -- i.e. the
  caller has already done any per-order-line summing. `menu_item_id`
  values not present in `menu_item_ingredients_df` for the ingredient in
  question are simply ignored (they don't consume this ingredient).
- `quantity_required` in `menu_item_ingredients_df` is assumed strictly
  positive and expressed per unit of the menu item (a zero or negative
  recipe line would silently zero out or invert that item's contribution;
  this isn't guarded against since a
  real recipe table should never have that row in the first place -- it
  would be a data-entry bug for PHP/the admin UI to prevent upstream, not
  something this offline demo script defends against).
- A day with literally no matching order rows is treated as 0 consumption
  for that ingredient, not "unknown"/`NaN` -- consistent with "never skip
  quiet days" in the spec.

## demand_classification.py / forecasting.py

- The fast/medium/slow thresholds (0.70 / 0.30) and interval widths
  (0.80 / 0.95) are hardcoded module constants, per the spec -- deliberately
  not wired into any settings/config system, since changing them would
  invalidate any prior backtest run against the old values.
- An **empty** history series (`len == 0`) is defensively classified as
  `"slow"` with `nonzero_day_ratio = 0.0` inside `classify_demand()`
  rather than raising a `ZeroDivisionError`. In practice this path should
  never be hit in production because both `/forecast` and
  `/forecast_policy` already reject requests below `MIN_HISTORY_DAYS`
  before classification ever runs; the guard exists only because
  `classify_demand()` is a public function other callers (tests, future
  scripts) might invoke directly without that upstream check.
- Prophet's config for the new engine (`daily_seasonality=True,
  weekly_seasonality=True, yearly_seasonality=False`) intentionally
  matches `/forecast`'s existing Prophet config, with `interval_width`
  newly wired through since `/forecast` never varied it.
  **Superseded 2026-08-31**: `yearly_seasonality` is now `4`, in both
  engines. See the 2026-08-31 section at the end of this file. This was a
  judgment call to keep the two Prophet configurations recognizably
  related rather than inventing a third, unrelated tuning.
- `forecast_trailing_average()`'s "flat, no confidence interval" behavior
  (`yhat_lower == yhat_upper == yhat`) is spec'd, not my own choice, but
  worth flagging: it means the trailing-average branch has zero forecast
  variance in the returned JSON, which is intentional (a flat conservative
  number, not a fake-precision statistical band).

## inventory_policy.py

- `SLOW_MOVER_BUFFER_MULTIPLIER = 2.0` is a flat, spec'd module constant --
  not empirically tuned against real stockout data (there isn't enough
  real slow-mover history in this app yet to tune it against). It's a
  reasonable, conservative starting point that should be revisited once
  real inventory/stockout history exists for slow-moving ingredients.
- When `lead_time_days > forecast_horizon_days`, `review_period_days`
  clamps to 0 rather than erroring, per the spec -- this means
  `restock_target == reorder_level` in that degenerate case (there's no
  "review period" left in the forecast window to add demand for). This is
  a real, allowed scenario (e.g. a 10-day lead-time ingredient forecast
  over only a 7-day horizon), not a data-entry error, so it's handled
  silently rather than rejected by the endpoint.

## po_generation.py

- `size_order()`'s `pack_size <= 0` case (a supplier with no meaningful
  pack size, e.g. an ingredient bought by loose weight) falls back to
  using the raw un-rounded quantity rather than raising -- this wasn't
  explicitly specified, so I chose the least-surprising behavior
  (no artificial rounding when there's nothing to round to) over crashing
  the endpoint on an edge-case supplier configuration.
- `group_by_supplier()` assumes every item dict actually has a
  `supplier_id` key; a missing key raises a `KeyError` rather than being
  silently dropped, on the assumption that a triggered reorder item
  without a known supplier is a data problem PHP should surface, not
  something this function should paper over.

## /forecast_policy endpoint

- Field names, response shape, and error shape (`422` /
  `{"error": "insufficient_data", ...}`) follow the spec exactly, since
  PHP is being built against this contract in parallel. Nothing here was
  renamed or restructured from what was given.
- `lead_time_days` and `forecast_horizon_days` are validated as `>= 0` and
  `>= 1` respectively and return `422` on violation (shape reused from the
  insufficient-data error, but with `"error": "invalid_input"` since the
  spec only specified the exact error body for the insufficient-history
  case, not for these two). This wasn't explicitly spec'd as a required
  error code/body, so it's a judgment call consistent with the existing
  `/forecast` endpoint's use of `422` for request-shape problems.
- `history` reuses the existing `HistoryPoint` Pydantic model verbatim, as
  instructed -- no new date/quantity model was introduced.

## run_example.py / backtest.py

- Both scripts use **entirely synthetic data**, generated with a fixed
  RNG seed for reproducibility. This app's real order/inventory history
  does not yet span enough days, or enough SKUs across all three velocity
  classes at once, to support a defensible demo or backtest -- this is a
  disclosed limitation, not a hidden one. Once enough real history exists
  (multiple months, several slow-moving ingredients with genuine
  intermittent demand), these scripts should be re-pointed at a real
  extract instead of the synthetic generators.
- `backtest.py`'s MAPE calculation excludes days where actual demand was
  exactly 0 (percentage error is undefined at a true zero), and reports
  how many days were excluded alongside the number -- this is a standard,
  disclosed convention rather than a workaround being hidden. For very
  slow-moving SKUs (e.g. "Wagyu" in the demo) essentially every test day
  can have zero actual demand, which surfaces as `MAPE = n/a`; MAE/RMSE
  are reported unconditionally as the more meaningful metrics for that
  class.
- `backtest.py` Part 2's "old" static baseline (`OLD_SAFETY_FACTOR = 1.0`,
  a flat rule with no variability-based safety margin) is a synthesized
  stand-in for "how this restaurant would set reorder points without a
  forecasting engine" -- there is no real legacy reorder-point policy in
  this codebase to compare against, since ingredient-level reordering is
  a new feature. The comparison is meant to show the shape of the
  before/after difference (Prophet's/the slow-mover multiplier's margin
  reduces stockouts at some added inventory cost), not to claim it matches
  any specific real prior policy.
- Both scripts' policy/order numbers are computed **once** per SKU from
  the training window and held fixed through their respective
  demonstration/simulation run (no rolling re-forecast mid-run). This
  matches the spec's explicit "not a full simulation" framing for the
  policy comparison.

## Explicitly out of scope (per the spec, not built)

- No YAML/DB-backed config system for the model-tuning constants above --
  they are plain Python module constants, as specified.
- No health-alert/system-monitoring notifications and no auto-retraining
  scheduler for Prophet models. Noted here only as possible future work,
  not implemented.

## 2026-08-11 -- known-demand overlay, shelf-life cap, holidays, engine_version, real-data backtest

- **`compute_policy()`'s shelf-life cap** (`shelf_life_days` param): caps
  `review_period_days` so the order-up-to window never exceeds
  `shelf_life_days - 1`, re-deriving it from `min(lead_time_days +
  review_period_days, shelf_life_days - 1)`. `lead_time_days` itself is
  never reduced by this -- there's no way to receive stock faster than a
  supplier's real lead time, so if `shelf_life_days - 1 < lead_time_days`
  the review window simply goes to 0 (order only enough to survive lead
  time; a genuinely impossible-to-safely-stock edge case, left uncapped
  further since no gate was specified for it).
- **Two-channel known-demand overlay** (`committed_floor_daily`,
  `adjustment_delta_daily`): deliberately kept as two separate,
  differently-combined channels rather than one blended value, after an
  earlier single-channel draft was rejected during planning -- a floor
  (advance-order bookings) combines via `max()` since it can only raise
  demand up to a guaranteed minimum, never double-count on top of an
  already-higher forecast; a signed additive delta (a manager's
  date-ranged multiplier override) is always added on top, never blended
  via `max()`, so it can never be silently discarded by a forecast that
  already happens to exceed it. The combined result is clamped at 0
  (`GREATEST(0, ...)`) since a below-1.0 multiplier produces a negative
  delta and demand can never go negative. Both lists are day-aligned with
  `forecast_df` (index 0 = first forecast day); `compute_policy()` sums
  each into a lead-time-window total and a review-window total via the
  internal `_window_sum()` helper so both the Prophet and
  trailing-average branches apply the identical overlay math despite
  computing their base numbers differently.
- **No fallback model, anywhere in this service, ever.** An earlier
  planning draft had the PHP caller fall back to a same-weekday-average
  estimate when this service is unreachable, labeled as a distinct
  `model_used` value. This was explicitly rejected -- this app's standing
  rule (confirmed multiple times across sessions) is that AI/ML must be
  the primary engine and never silently substituted for a weaker model.
  `model_used` stays exactly `"prophet"` or `"trailing_average"`
  (the latter a genuine, permanent, spec'd routing decision for sparse
  series -- not an availability fallback, see the `demand_classification.py`
  section above). When this service can't be reached, the PHP caller
  gates the affected item with an honest "unavailable" message instead
  of computing a substitute here or in PHP.
- **Holidays pooled by `holiday_type`, not `holiday_name`**
  (`build_holidays_df()` in `forecasting.py`): with ~91 days of this
  app's real order history, a training window realistically contains
  3-5 holiday occurrences, each with a unique name (e.g. "Rizal Day").
  Fitting Prophet's `holidays` regressor per individual name would give
  most effects exactly one observation -- Prophet would effectively
  memorize that single day and then misapply the "learned" coefficient
  to a future, differently-named holiday. Pooling to `holiday_type`
  (`regular`/`special`, from the existing `holidays` table -- see
  `payroll/holidays.php`) collapses this to at most 2 effects, each with
  a handful of observations. Still thin data, but not degenerate.
  `holiday_name` never reaches Prophet, humans-only via the table/UI.
- **`engine_version` is computed, never hardcoded** (`app.py`'s
  `engine_version()`): reads `prophet`/`pandas`'s real installed versions
  via `importlib.metadata.version()` on every response, specifically so
  `forecast_runs.engine_version` on the PHP side reflects what actually
  ran that request, not a string that silently drifts out of date after
  a `pip install --upgrade`.
- **`backtest_real.py`** is new, alongside (not replacing) `backtest.py`.
  It expects a CSV of real daily series (menu-item sales or ingredient
  consumption, one row per entity per day, dense/zero-filled) exported by
  PHP, and runs a genuine rolling-origin (walk-forward) backtest --
  train on an expanding window starting at `MIN_HISTORY_DAYS`, test on
  the next `horizon_days`, slide forward, repeat. With this app's real
  ~91 days of history, this yields several folds at a 7-day horizon but
  realistically only 1-2 at 30 days -- every number this script prints
  is reported next to its fold count for exactly that reason. It scores
  against a same-weekday trailing-average seasonal-naive baseline
  (`seasonal_naive_forecast()`) using WAPE, not MAPE (this app's real
  data has plenty of true zero-demand days, where MAPE is undefined) --
  this baseline-scoring function is a comparison metric only, computed
  purely for the accuracy report; it is not a fallback model and is
  never used to produce an actual forecast or purchasing decision
  anywhere in the live system.
- **First real run of `backtest_real.py` against this app's actual 91-day
  history (47 menu items, horizon=7/14/30, 517 total fold-records at
  horizon=7): Prophet did NOT beat the seasonal-naive baseline overall**
  (pooled/volume-weighted WAPE 87.5% vs. baseline 86.8% at horizon=7; the
  gap widens at longer horizons). Diagnosed rather than taken at face
  value, since a defense-critical negative result deserves the same
  scrutiny as a positive one:
    - Ruled out as the cause: simple-mean-of-per-item-WAPE aggregation
      being skewed by a few noisy low-volume items -- pooled
      (volume-weighted) WAPE gives essentially the same gap (87.5/86.8)
      as the naive per-item mean (92.9/92.2), so the aggregation method
      itself isn't manufacturing the result.
    - **Real cause, confirmed**: cold-start folds. `rolling_origin_folds()`
      starts its first fold at exactly `MIN_HISTORY_DAYS` (14) of
      training data -- barely two weekly cycles, not enough for Prophet's
      seasonality decomposition to have real footing. Splitting the
      517 fold-records: fold 0 alone (14 training days) scores Prophet at
      110.2% vs. baseline 94.8% (Prophet clearly worse); folds 1+ (30-84
      training days) score Prophet at 85.4% vs. baseline 86.1% -- Prophet
      modestly WINS once it has more than the bare minimum history.
    - **Implication**: `MIN_HISTORY_DAYS = 14` (mirrored between this
      module and `demand_forecast_functions.php`'s `MIN_PROPHET_HISTORY_DAYS`)
      is a reasonable floor for "don't crash/error," but this result
      suggests it is NOT a reasonable floor for "expect Prophet to
      actually help" -- worth a deliberate follow-up decision (raise the
      gate for when Prophet is preferred vs. when the slow-mover
      trailing-average branch is used, or simply disclose the honest
      "Prophet needs ~30+ days to earn its keep" framing) rather than
      silently claiming Prophet wins everywhere. Not changed here without
      a decision from the user, per this file's own "model-tuning
      constants aren't changed lightly, they invalidate prior backtests"
      convention (see the classification thresholds entry above).

## 2026-08-11 -- decision made: PROPHET_MIN_TRAINING_DAYS gate added

Follow-up to the cold-start-folds finding immediately above. User's decision:
raise the gate rather than leave it as a disclosed limitation.

- **`demand_classification.py`**: new `PROPHET_MIN_TRAINING_DAYS = 28`
  (4 weeks -- inside the confirmed-good range from the backtest split
  above, and an easy number to explain in a defense: "at least a month of
  history before we trust Prophet's weekly-seasonality fit," rather than
  the bare minimum needed just to not divide by zero). `MIN_HISTORY_DAYS`
  (14, in `data_prep.py`/mirrored PHP-side `MIN_PROPHET_HISTORY_DAYS` /
  `INVENTORY_POLICY_MIN_HISTORY_DAYS`) is **unchanged** and still means what
  it always meant -- "can we compute anything at all." The new constant is
  a second, independent, higher floor specifically for "do we trust Prophet
  enough to prefer it over the trailing-average branch."
- **Chosen implementation: extend `classify_demand()` itself**, not the
  dispatcher. When `total_days < PROPHET_MIN_TRAINING_DAYS`,
  `classify_demand()` now reports `demand_pattern="slow"` regardless of
  `nonzero_day_ratio` -- i.e. an item that sells every single day but has
  only 20 days of history is routed through the exact same conservative,
  low-data-requirement trailing-average branch a genuinely sparse-selling
  item already uses. This was a deliberate choice over adding a *third*
  model path: the root problem in both cases is identical (no real
  statistical basis for a seasonal decomposition yet), so the existing
  "slow mover" branch's rationale extends cleanly to "...or not enough
  history yet," and every downstream consumer keyed on the `demand_pattern`
  string (`forecasting.py::forecast_demand()`, `app.py`'s
  `/forecast_policy` handler, `inventory_policy.py::compute_policy()`,
  `backtest.py`, `run_example.py`) required **zero changes** -- they all
  already do the right thing for `"slow"`.
- **New `slow_reason` field** (`"insufficient_history"` |
  `"sparse_demand"` | `None`) added alongside `demand_pattern` specifically
  so this reuse doesn't lose information: an item routed to
  trailing-average because it's new is a different fact from one routed
  there because it genuinely sells rarely, and the audit trail/UI should
  say so rather than calling both "slow mover." Threaded through
  `PolicyResponse` (`app.py`) -> `callProphetPolicyService()`'s passthrough
  (`prophet_client.php`, no change needed, already returns the full decoded
  body) -> `computeInventoryPolicyForItem()`'s `$base['slow_reason']`
  (`inventory_policy_functions.php`) -> `demandPatternLabel()`, which now
  shows **"Building history"** instead of **"Slow mover"** when
  `slow_reason === 'insufficient_history'`, everywhere the label is shown
  (dashboard, `demand_forecast.php`, CSV/PDF export). Not persisted to any
  table -- it's a live/derived explanation, not a fact `reorder_suggestions`
  or `ingredient_demand_forecast` need to remember (those already correctly
  persist `model_used='trailing_average'`, which is the fact that matters
  for the audit trail; *why* trailing_average ran is a UI-layer nuance).
- **`/forecast` (menu-item sales endpoint) is unaffected** -- it never
  calls `classify_demand()` at all, always fits Prophet directly. This gate
  only applies to the ingredient/inventory-policy side, where the
  fast/medium/slow routing lives.
- **Re-ran `backtest_real.py` against the identical real 91-day CSV export**
  used for the original finding, to confirm the fix actually changes the
  measured result and not just the code path -- same 47 entities, same
  file, only the code changed:

  | Horizon | Before (this app's model / baseline) | After | Beats baseline |
  |---|---|---|---|
  | 7 days  | 92.9% / 92.2% (NO) | 89.3% / 92.2% | **YES** |
  | 14 days | 96.9% / 91.8% (NO) | 89.8% / 91.8% | **YES** |
  | 30 days | 120.8% / 93.4% (NO) | 91.8% / 93.4% | **YES** |

  The gate closes the gap at every horizon, including 30-day (where the
  original result was worst -- a 30-day-horizon fold's training window is
  necessarily short relative to its own start, so it disproportionately
  contained cold-start folds before this fix). Also relabeled
  `backtest_real.py`'s own output from "Mean WAPE (Prophet)" to "Mean WAPE
  (this app's model)": `backtest_entity()` calls the same
  `forecasting.forecast_demand()` dispatcher a live request goes through,
  so a fold whose training window is still below
  `PROPHET_MIN_TRAINING_DAYS` correctly scores the trailing-average branch,
  not Prophet in isolation -- the old label would have been quietly wrong
  now that the two can diverge within a single entity's fold sequence.

## 2026-08-12 -- Phase 6: persisted accuracy (both domains), ingredient-side WAPE, real-data backtest confirms the fix generalizes

Phase 6 of the project plan: wired `forecast_accuracy` (created empty back in
Phase 0) up to real, persisted, upserted numbers for the first time, on both
sides of "Demand Forecasting" -- menu-item sales AND ingredient consumption.
All PHP-side; nothing here touches this Python service directly.

- **`owner/includes/inventory_policy_functions.php`**: new
  `computeIngredientForecastAccuracyByLag()` -- the ingredient-domain
  counterpart to `demand_forecast_functions.php`'s existing
  `computeForecastAccuracyWape()`. Reconciles `ingredient_demand_forecast`'s
  persisted curve against REALIZED consumption via the same
  `getDailyDenseIngredientConsumption()` the training series itself uses
  (BOM-exploded from real `order_items`, never `inventory_transactions` --
  project plan Fix #10; a plan-doc placeholder named this function
  `getRealizedIngredientConsumption()`, but since its job is byte-for-byte
  identical to the existing training-series builder, reusing that function
  directly -- rather than adding a same-behavior wrapper with a different
  name -- was the less-bloated choice). Grouped by `lag_days`
  (`DATEDIFF(forecast_date, DATE(run.started_at))`): the sweep re-forecasts
  every open day of the horizon on every daily run, so the same
  `forecast_date` naturally accumulates one data point per lag as more daily
  sweeps run over time, and a 1-day-ahead forecast has a genuinely different
  accuracy profile than a 7-day-ahead one. Same min-3-reconciled-points gate
  as the sales-side function, for the same reason.
- New `upsertForecastAccuracy()` + `computeAndPersistForecastAccuracy()`
  orchestrator, wired into `cron/run_sweeps.php` on the same 5-minute
  cadence as the other sweeps (cheap -- pure SQL, no Python calls). Verified
  live: ran twice against seeded synthetic data, confirmed the SECOND run
  updated the same two `accuracy_id` rows in place (`computed_at` advanced,
  row count stayed at 2) rather than inserting duplicates -- the exact
  upsert-not-insert-only-log behavior `uq_forecastaccuracy_scope`'s schema
  comment requires (project plan round 5's fix).
- **Real, previously-undiscovered bug found and fixed while investigating
  why real persisted `ingredient_demand_forecast` rows showed `lag_days=0`
  for what should have been `lag_days=1`**:
  `computeInventoryPolicyForItem()` (PHP) built the known-demand overlay's
  day range starting at `new DateTime('tomorrow')`, on the stated assumption
  that this aligns with Prophet's first forecast day. It doesn't. Training
  data ends at `$rangeEnd = new DateTime('yesterday')`, and this service's
  own `_future_dates()` (`forecasting.py`) computes its first forecast day
  as `last_training_date + 1 day` = `yesterday + 1` = **today**, not
  tomorrow -- confirmed against a live `/forecast_policy` response (training
  ending 2026-08-11, first returned forecast date 2026-08-12). The fix
  (`inventory_policy_functions.php`) changes the overlay's start to
  `new DateTime('today')`. Impact of the bug while it existed: today's
  committed floor was silently dropped entirely (the overlay query started
  one day too late to include it), every other day's floor/delta was
  applied to the wrong Prophet row, and the last Prophet-forecasted day of
  the horizon got no overlay at all (the array ran one entry short). Checked
  for real damage: zero active `demand_adjustments` and zero relevant
  `reservation_advance_orders` existed anywhere in the live system's history
  before this fix landed, so both overlay channels were always numerically
  zero in every real computation to date -- the misalignment was live in the
  code but never actually corrupted a real stored number. No backfill was
  needed; this would have started silently misattributing real data the
  first time an owner created a demand adjustment or took a reservation with
  advance orders, had it gone uncaught.
- **UI**: `owner/demand_forecast.php` Section 4 (Forecast Trend) gained an
  "Aggregate WAPE" line for the selected item, reading directly from the
  persisted `forecast_accuracy` table -- explicitly labeled and positioned
  as distinct from the existing "Forecast accuracy" KPI card above it (that
  card's `computeForecastAccuracy()` is unchanged, per capstone-defense
  decision point #1: keep both, label distinctly). The "Why?" evidence panel
  (`demand_forecast_contributions.php`, shared by `demand_forecast.php` and
  `reorder_suggestions.php`) gained a "Track record (aggregate WAPE by
  forecast lag)" table, since an ingredient's accuracy doesn't collapse to
  one number the way a single ad-hoc reorder decision does.
- **Real-data backtest, ingredient domain, run for the first time** (51
  ingredients, same real order history as the earlier sales-domain run,
  same `PROPHET_MIN_TRAINING_DAYS=28` gate already in place): beats baseline
  at every horizon, confirming the gate's fix generalizes beyond the domain
  it was originally diagnosed against.

  | Horizon | This app's model | Seasonal-naive baseline | Beats baseline |
  |---|---|---|---|
  | 7 days  | 76.6% | 78.6% | **YES** |
  | 14 days | 77.1% | 77.7% | **YES** |
  | 30 days | 77.9% | 79.0% | **YES** |

  Both halves of "Demand Forecasting" (menu-item sales and ingredient
  consumption, the two things this capstone paper treats as one combined
  feature) now genuinely beat the naive baseline at every tested horizon,
  on the exact same model configuration.
- As of this writing, real `forecast_accuracy` rows are still empty in the
  live system (0 sales-domain, 0 ingredient-domain) -- correctly, not a bug:
  only 2 real daily sweeps have run so far (2026-08-11, 2026-08-12) and
  nothing has accumulated 3+ reconciled points at a consistent lag yet. This
  fills in automatically as more real days of operation pass, matching this
  app's standing "true, expected result today" convention for early-data
  states. Verified via disposable synthetic data (seeded, computed,
  persisted, re-run to confirm the upsert, then fully deleted) rather than
  waiting on real data to mature before shipping.

## 2026-08-31 -- forecasting redo: drivers actually wired, yearly gated, dead columns removed

Full rework of the demand-forecasting module and the auto purchase-order
sweep. Everything below was measured on this app's real data, not assumed.

### The holiday driver had never once been used

Prophet's holiday plumbing was complete end to end -- `prophet_client.php`
accepted `$holidays`, `app.py` accepted `holidays`,
`forecasting.build_holidays_df()` pooled them by type -- but **no caller ever
queried the `holidays` table**. Every call site passed a literal `[]`, so 20
real Philippine holidays sat unused while the UI described them as a driver.

- New `getActiveHolidays(PDO, ?DateTime, ?DateTime)` in
  `demand_forecast_functions.php` reads `holidays WHERE is_active = 1` and
  returns the `[['date','holiday_type'], ...]` shape the client already
  documented.
- `forecastFromDenseDailyMap()` now loads them when the caller passes
  `null`, so the default path is "holidays included" rather than "holidays
  silently dropped".
- Verified: 20 holidays now reach the service on a live policy call.

### Yearly seasonality: implemented, but gated on having a year of data

Yearly seasonality was requested. It was switched on, then measured, and the
measurement changed the design. Rolling-origin backtest, 7-day horizon, top
12 ingredients by volume, 97 days of real history
(`compare_seasonality.py`, reproducible):

| configuration | window WAPE | daily WAPE | bias |
|---|---|---|---|
| seasonal-naive baseline | 19.1% | 46.9% | -- |
| Prophet, yearly **off** | 22.8% | 46.3% | -3.9% |
| Prophet, yearly **= 4** | 61.0% | 75.2% | +14.1% |

Enabling yearly on a single quarter of history nearly **tripled** the error
that a purchase order actually depends on, and pushed the forecast 14% high.
Prophet will fit a yearly component to 97 days quite happily; with no annual
cycle to observe, that component absorbs noise and extrapolates it forward.

Resolution: `YEARLY_SEASONALITY_MIN_DAYS = 365` in `engine/forecasting.py`,
imported by `app.py` so both models gate identically. Yearly is **not
removed** -- it switches on by itself once the history can support it. The UI
states which state it is in and why, rather than leaving it to be discovered.

### The policy trained on a window with no sales in it

`computeInventoryPolicyForItem()` trained on `windowDays` back from
*yesterday*. Real sales in this database end 2026-08-11; the 20 days since
hold only a handful of scattered test orders. So the live 30-day training
window was almost entirely empty, every ingredient forecast collapsed toward
zero, and the sweep had effectively stopped proposing anything.

Now clamped with `forecastTrainingEnd()`, matching what the sales-forecast
path already did. Before and after, same ingredient (Pork, 30-day window):

    before   lead-time demand 0.000   reorder 0.899   restock  0.899
    after    lead-time demand 6.206   reorder 9.243   restock 37.454

`demand_forecast_backtest_export.php` had the identical flaw and would have
padded every exported series with trailing zero-days; also fixed.

### Accuracy is measured by backtest, not from the live tables

Stored per-item WAPE rows were reading 1730% on average, individually
pinned at the `DECIMAL(6,2)` ceiling of 9999.99. Two independent causes:

1. **No ground truth to score against.** `ingredient_demand_forecast` starts
   2026-08-11 and recorded sales effectively end the same day, so
   reconciliation was scoring predictions against days with no trade. That
   measures missing data, not the model. `computeIngredientForecastAccuracyByLag()`
   now clamps to `getLastSalesDataDate()`.
2. **Intermittency.** Mean predicted ingredient quantity is 0.92/day with a
   minimum of 0. WAPE divides by that, so a rounding-sized miss reads as
   hundreds of percent. This is a property of intermittent demand, not a
   model failure, and it is why a per-item daily percentage is the wrong
   headline for a restaurant.

Accuracy now comes from `cron/refresh_forecast_backtest.php` ->
`backtest_pooled.py`: a rolling-origin backtest over the period where ground
truth exists, reported at three levels (window total / per-item daily /
volume bias) with the seasonal-naive baseline beside each. All three are
shown in the UI, not only the flattering one.

`computeWindowForecastAccuracy()` was also rebuilt: it now scores a **single
completed run** made at or before the window opens, over exactly the
item/date pairs that run predicted. Summing across runs had been
double-counting (one item's 30-day total came to 2420 against an actual of
43, because eight runs each predicted the same dates).

### Dead configuration removed -- and one thing that only looked dead

- **`pack_size`**: NULL on all 52 items, so its rounding branch had never
  executed. Removed from the DB, from `sizeAutoOrderQuantity()`, and from
  `engine/po_generation.py::size_order()`. Order quantity is now the forecast
  shortfall, rounded only to something physically orderable.
- **The shelf-life *cap*** in `inventory_policy.compute_policy()`: never
  capped anything (the column was NULL everywhere). Removed from the engine
  and from the `/forecast_policy` request/response contract.
- **`inventory_items.shelf_life_days` itself was dropped, and that was
  wrong.** The forecast engine's *cap* was dead; the *column* was not. It has
  a live form field in `inventory/inventory.php` and derives batch expiry
  dates in `purchase_order_receive.php` and `inventory_adjustment_save.php`.
  Dropping it broke the entire Inventory module. **Restored.** The lesson
  worth keeping: "no code path currently uses this value" and "no code path
  references this column" are different claims, and only the second one
  justifies a `DROP COLUMN`.

### Packaging excluded from the forecast

`getActiveIngredientItems()` no longer includes packaging items -- the module
forecasts recipe ingredient consumption. Scope went 51 -> 50 items.

### A gated sweep is now a non-run, not 457 phantom decisions

457 of 1070 `reorder_suggestions` were `skipped_gated`, all with "AI forecast
policy service unavailable right now." A run that could not reach the model
was writing a full set of do-nothing rows and reporting `partial`. It now
aborts on the first service failure and marks the run `failed` with a reason,
writing no suggestion rows -- unless rows with a real `po_id` already exist,
in which case the run is `partial` so a genuinely useful run is not
retroactively downgraded.

### Purchasing settings folded into Demand Forecasting

The Settings > Purchasing tab is gone. None of its six settings was a
purchasing policy: trailing history, forecast horizon, shortage horizon,
sweep hour, sweep on/off and stale-draft reminder are all forecasting
inputs, and purchasing is only what the forecast triggers. They now live in
a Forecast Configuration panel on `owner/demand_forecast.php`, beside the
numbers they produce, saved by `demand_forecast_settings_save.php`.
