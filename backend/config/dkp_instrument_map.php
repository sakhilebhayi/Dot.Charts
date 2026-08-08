<?php

// Seed instrument map for the inbound MNPI content-materiality screen.
// Deliberately small and illustrative -- NOT a comprehensive or
// professionally-maintained instrument-mapping dataset. Mirrors the
// Kolomela/Kumba Iron Ore example already used in Dot.Brain's own
// brain.security.md §6 worked example (a false-correlation poisoning
// attempt targeting a Dot.Charts recommendation via a commodity/mining
// domain reference), rather than inventing a new fictional mapping.
// Per platforms/dot-charts.md §12's open question, this map's ongoing
// maintenance ownership is unresolved ecosystem-wide -- this seed exists
// so the gate has something real to check against, not as a claim that
// ChartSense unilaterally owns instrument-mapping maintenance.
return [
    'kolomela' => ['KIO.JO'],
    'sishen' => ['KIO.JO'],
    'kumba iron ore' => ['KIO.JO'],
];
