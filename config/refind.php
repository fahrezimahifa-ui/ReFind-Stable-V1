<?php

return ['query_metrics' => getenv('REFIND_QUERY_METRICS') !== '0', 'report_effects' => true, 'report_write_profiles' => ['academic-dev', 'academic-test', 'phase6-dev', 'phase7-dev', 'phase8-dev', 'demo']];
// Claim write stages are not configurable here by design; see App\Support\ClaimStages (fail closed).
