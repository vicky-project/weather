<?php

return [
  'id' => 'weather',
  'name' => 'Info Cuaca',
  'description' => 'Informasi cuaca terkini',
  'icon_class' => 'bi bi-cloud-sun',
  'render_type' => 'iframe',
  'render_config' => [
    'url' => env('APP_URL') . '/apps/weather'
  ]
];