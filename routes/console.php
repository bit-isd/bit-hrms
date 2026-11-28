<?php

use App\Services\RetirementRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command("inspire", function () {
    $this->comment("Ogun State BIT HRMS Services");
})->purpose("Display an inspiring quote");

Artisan::command('hrms:record-retirements', function (RetirementRecorder $recorder) {
    $count = $recorder->recordDueRetirements();
    $this->info("Recorded {$count} automatic staff retirement(s) in the audit log.");
})->purpose('Audit-log staff whose statutory retirement date (age 60 or 35 years of service) has arrived');

Schedule::command('hrms:record-retirements')->dailyAt('00:05');
