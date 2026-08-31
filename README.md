# BluPin Capital Trading Indicator

# BluPinJS
> BluPinJS gives traders the ability to make informed decisions about the commodity markets. This Indicator gives traders the ability to identify and trade different market conditions. It does not focus on one trading strategy but it adjusts according to the current opportunity offered.
<!-- > Live demo [_here_](https://files.infodot.co.za). -->

## Table of Contents
* [General Info](#general-information)
* [Project Status](#project-status)
* [Contact](#contact)
<!-- * [License](#license) -->


## General Information
- A way to trade the commodity markets with ease. (Works on GOLD, SILVER, PLATINUM, USOIL, US30, NAS100 & BTCUSD)
- This script is still under development but it is also ready for use. (Please proceed with causion)


## Features
- Buy / Sell Signals When Opportunities Open Up.
- Supports Multiple Time Frames From M5 - H4

## Project Status
- Project Status: _Development_.
- Vesion 0.10
- Stage 1: Currently working on the time frames, I noticed that this script works best on the H4 time frame, so we have narrowed the script down to higher time frames for better results, and we'll probably add more once we've tested this method and are sure that it is effective.
- Stage 2: Adding Take Profit signals just before the opposite signal appears, converted the script to pine version 6. The script also works well on the 30 minute time frame (This is still in BETA).
- Stage 3: Auto execute trades based on signals provided by indicator, this will be the last stage of the script but will only be implemented once the first two stages are completely functional. 

## Contact
Created by [BluPin Capital](https://capital.blupininc.com/)


<!-- Optional -->
<!-- ## License -->
<!-- This project is open source and available under the Mit License](). -->

<!-- You don't have to include all sections - just the one's relevant to your project -->



---

## BluPin ORD v3 + Ultimate (2026)

`pine/BluPin_ORD_Ultimate_Combined.pine` — the current production system for
TVC:GOLD (Africa/Johannesburg): the frozen ORD session engine plus the
Ultimate Signal of the Day (20:00–00:00 body range, 00:00–03:00 latest-sweep
fade, contrarian fallback) hardened into the **Survivor configuration**: four
backtested day-filters (NFP Friday, prior-day bias, noise floor, thin-level)
and a 05:00 proof checkpoint — only signals that survive uninvalidated show
and trade. Tested on 2y of hourly data: 42.8% win +103.1 ATR (2026 window
65.4% win in 1h-sim terms). Full research record in `docs/research/`.

`tools/blupin_daily.py` + the `blupin-daily` workflow reproduce the engine
headlessly every weekday at 05:20 SAST: today's signal is journaled to
`signals/journal.jsonl` and emitted to Dot.Memory's intelligence loop
(observation → decision → outcome), where Dot.Brain studies the patterns.
The v1 indicator files above are preserved unchanged.
