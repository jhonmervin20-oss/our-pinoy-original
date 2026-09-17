# Restaurant demand forecasting service

One Prophet model predicts how busy the restaurant will be. Everything after that —
which dishes, which ingredients, how much to buy — is arithmetic that can be checked
by hand. That is the whole design, and it is why it holds up under questioning.

## Ground rules

- The database schema is fixed. Read `docs/schema-notes.md` before writing any query.
  Never invent a table or column. If something you need is missing, stop and say so.
- Every threshold comes from `system_settings`. No magic numbers in code, ever.
- Every run writes a `forecast_runs` row: status, params_json, engine_version,
  error_message. A stage that fails marks the run `partial` or `failed` — it never
  leaves a half-written forecast with no record.
- Money and quantities are `Decimal`, never float. Round only at the last step.
- Timezone is Asia/Manila. The business day ends at `business_day_cutoff_time` (04:00),
  so an order at 01:30 belongs to the previous day.

## The pipeline

    s1 history -> s2 Prophet -> s3 menu split -> s4 explode -> s5 reconcile
    -> s6 reorder -> s7 accuracy

Each stage is a module with one public function taking a `run_id` and returning a
DataFrame. Stages never call each other; `run.py` orchestrates.

Only stage 2 estimates anything. Stages 3-6 are deterministic arithmetic with known
answers — write the test first.

## Prophet configuration — do not change without updating this file

    yearly_seasonality=False      # 90 days of history cannot estimate a year
    weekly_seasonality=True       # 1.70x weekend effect, 12-13 samples per weekday
    daily_seasonality=False       # the series is one point per day
    seasonality_mode='multiplicative'
    seasonality_prior_scale=5.0   # below the default 10; 13 samples/weekday is not many
    changepoint_prior_scale=0.05  # the default; raise only if the backtest demands it
    interval_width=0.80

Holidays are NOT fitted. Four holidays fall inside the 90-day window, one observation
each. Flag them on charts, exclude them from training, revisit after a full year.

## Verified facts about this database (2026-08-31)

Measured by running stage 1 against live data. These reproduce the written plan
exactly -- an earlier check appeared to contradict it only because it skipped the
closure rule. The closure rule is what makes these numbers correct.

| Fact | Value |
|---|---|
| Active menu items / ingredients / suppliers | 47 / 52 / 2 |
| Training window | 2026-05-14 -> 2026-08-11, 90 trading days |
| Weekday means | Mon 17.3 - Tue 18.6 - Wed 18.9 - Thu 17.2 - Fri 28.7 - Sat 29.5 - Sun 29.1 |
| Weekend / Monday | 1.70x |
| Fridays in window | 13, totalling 373 orders |
| Grilled Tanigue on Fridays | 46 -> share 0.1233 -> 3.61 servings -> 0.723 kg tanigue |
| Days excluded by the closure rule | **4** (2026-08-16, 08-23, 08-24, 08-30) |
| `shelf_life_days` populated | 0 of 52 -- all on category defaults |
| `adjustment_delta_qty` non-zero | 0 rows -- Trend Setter has never run |
| Ingredients in no recipe | Oyster Sauce only |

Note on the excluded count: the plan says 13 days are excluded. The rule removes 4.
The ten zero-order days between 12 and 22 August are not excluded because they were
never rows -- no orders means no record to drop. Same outcome, different number, and
the run log shows 4.

## Training window

`forecast_history_days` = 90 trading days, rolling, ending at the last day of
CONTINUOUS recording — not the last day a sale happened. Those are different questions.

A single sale rung up after a long silence does not mean the business traded through
that silence. Training to it makes the model read the gap as demand collapsing to zero.
Live data shows exactly that: 17-32 orders/day through 2026-08-11, then near-nothing.

Rule: exclude any day whose order count is below `closure_detect_pct` (20) of the
trailing 28-day median, unless an operator confirmed it genuinely traded that low.
Excluded dates go into `forecast_runs.params_json` so every run says what it ignored.

## Safety stock

Safety stock is a number of DAYS of forecast demand -- `safety_stock_days`, default 1.
Nothing else. There is no `z`, no sigma, no square root, and no service level.

    reorder at = demand over (lead time)          + demand over (safety stock days)
    target     = demand over (lead time + review) + demand over (safety stock days)
    order      = min(round_up(target - on hand - on order), demand over (shelf life))

This replaced `SS = z * sigma * sqrt(L + R)`, and the swap was MEASURED before it was
made: at `safety_stock_days = 1` the simple rule reproduced all 52 purchase decisions
identically. What is genuinely given up is that safety stock no longer widens for an
erratic ingredient -- every item now gets the same days of cover. Say that plainly
rather than letting it be found.

`reorder_level` is an OUTPUT of the sweep (`LTD + SS`), never an input.

## Rounding — there are no pack sizes and no steps

Round UP to something a supplier can measure out. `count` goes to a whole unit (you
cannot order 6.4 eggs). Weight and volume go to TWO decimals -- 10 g, or 10 ml.
Three decimals was false precision: it asked for 2.724 kg of beef and 0.034 l of
concentrate, quantities nobody weighs at a market stall.

This is not the old pack-size step. That rounded to 0.25 on an unverified assumption
about the suppliers and inflated a 0.296 l order to 0.500 l -- a 69% overbuy. Rounding
up to 10 g adds at most 10 g, and `test_rounding_up_never_inflates_more_than_one_step`
holds it there.

The PHP side (owner/includes/forecast_view_functions.php) repeats this rounding for the
quantity shown on the Purchase Plan. If the two drift, the page stops matching the PO.

## Testing

Stages 3-6 are pure arithmetic. Write the test first. `pytest tests/ -q` must pass
before any stage is considered done.

Two invariants that must always hold:

- s3: sum of split servings == predicted total item count (within 0.01)
- s4: contribution rows for an ingredient sum exactly to its forecast row
