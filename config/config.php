<?php

return [
  'name' => 'Weather',
  "hook" => [
    "enabled" => env("WEATHER_HOOK_ENABLED", true),
    "service" => \Modules\CoreUI\Services\UIService::class,
    "name" => "main-apps",
  ],
  "openweather" => [
    "api_key" => env("OPENWEATHER_API_KEY"),
    "base_url" => env("OPENWEATHER_BASE_URL", "https://api.openweathermap.org/data")
  ],

  "notifications" => [
    /**
    * Comma separated for multiple notifications.
    * Currently support via telegram
    */
    "stack" => env("WEATHER_NOTIFICATIONS", "telegram")
  ]
];