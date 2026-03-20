<?php

return [
  'name' => 'Weather',
  "hook" => [
    "enabled" => env("WEATHER_HOOK_ENABLED", true),
    "service" => \Modules\CoreUI\Services\UIService::class,
    "name" => "main-apps",
  ],
  "openweather" => [
    "api_key" => env("OPENWEATHER_API_KEY")
  ]
];