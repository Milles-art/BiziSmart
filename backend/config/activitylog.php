<?php

return ['enabled' => true, 'delete_records_older_than_days' => 365, 'default_log_name' => 'staff',
    'default_auth_driver' => 'sanctum', 'subject_returns_soft_deleted_models' => false,
    'activity_model' => App\Models\Activity::class, 'table_name' => 'activity_log', 'database_connection' => null];
