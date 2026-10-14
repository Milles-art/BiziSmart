<?php
return ['admin_domain'=>env('ADMIN_DOMAIN','admin.bizismart.co.tz'),'allowed_ips'=>array_values(array_filter(explode(',',env('ADMIN_ALLOWED_IPS','')))),
    'features'=>['departments','inventory','reports','loss_prevention','suppliers','banking','promotions','loyalty','commissions','display','scales','messaging','offline','multi_branch','api_access','tra']];
