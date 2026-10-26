<?php

/*
 * Parts of the app that can be switched off without removing their code or data.
 * goals: Performance > Goals (KRA / OKR) and the goals section of reviews — hidden for now.
 */
return [
    'goals' => (bool) env('FEATURE_GOALS', false),
];
