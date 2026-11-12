<?php

use Illuminate\Support\Facades\Schedule;

// Convites pendentes vencidos viram "expired" (e geram invitation.expired na auditoria).
Schedule::command('obraflow:invitations:expire')->hourly()->withoutOverlapping();
