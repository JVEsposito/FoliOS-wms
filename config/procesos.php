<?php

return [
    'worker_umbral_segundos' => max(30, (int) env('WMS_WORKER_HEARTBEAT_STALE_SECONDS', 120)),
    'scheduler_umbral_segundos' => max(60, (int) env('WMS_SCHEDULER_HEARTBEAT_STALE_SECONDS', 150)),
    'worker_intervalo_segundos' => 30,
];
