# Dot.Brain Integration Plan — outside influences (news / global events)

Status: PLANNED (follows the intelligence loop, ADR-0015). Do via Dot.Brain's
PR + plan workflow, not ad-hoc commits.

1. **Context stage**: a Dot.Brain enrichment step that, for each
   `blupin-gold-<date>` loop, attaches a `context` envelope: economic-calendar
   events for the day (NFP/CPI/FOMC — extend the tested first-Friday NFP rule
   to the real calendar), DXY overnight move, and notable global events.
2. **Reasoning**: `services/intelligence` grades signal outcomes against that
   context — the BluPin research showed NFP days (1-for-24) and macro-shock
   days are where the fade dies; the loop should learn WHICH event classes
   hurt and feed back a veto list.
3. **Continuous improvement covenant**: every proposed rule change must be
   backtested on the cached 2y record (see docs/research/) and hold in both
   halves before it ships to the Pine script. The journal grows the forward
   sample that judges the wired configuration (survivor win-rate target:
   60%+ forward).
4. **Guardian**: once the daily workflow has run for a while, enroll a
   `blupin` manifest in Dot.Brain's guardian (autonomy_level 2, recommend-only
   per the registry ceiling) so a silent/failed daily run opens an issue.
