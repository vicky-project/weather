<?php

use Illuminate\Support\Facades\Route;
use Modules\Weather\Http\Controllers\WeatherController;

Route::prefix('apps')
->name('apps.')
->middleware(['web', 'telegram.miniapp'])
->group(function () {
  Route::get('weather', [WeatherController::class, "index"])->name('weather');
  Route::get('weather/settings', [WeatherController::class, "settings"])->name('weather.settings');
});