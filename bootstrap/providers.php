<?php

use App\Providers\AppServiceProvider;
use App\Webminder\Providers\WebminderServiceProvider;
use App\Webminder\Panel\Providers\WebminderPanelServiceProvider;

return [
    AppServiceProvider::class,
    WebminderServiceProvider::class,
    WebminderPanelServiceProvider::class,
];
