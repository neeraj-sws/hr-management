<?php

/*
 * Parts of the app that can be switched off without removing their code or data.
 * goals: Performance > Goals (KRA / OKR) and the goals section of reviews — hidden for now.
 */
return [
    'goals' => (bool) env('FEATURE_GOALS', false),
    // company_billing: Billing (Sales menu) for a login limited to one company (e.g. the ITWeb owner). Off: closed to him
    // (menu hidden, pages 403), so he never sees another company's customers or invoices. Turn on only when every Billing
    // page and model is split by company.
    'company_billing' => (bool) env('FEATURE_COMPANY_BILLING', false),
];
