<?php

use Illuminate\Support\Facades\Route;
use Modules\Weather\Http\Controllers\WeatherController;

Route::prefix('weather')->middleware('auth:sanctum')->name('weather.')->group(function () {
  Route::get('settings', [WeatherController::class, 'settings'])->name('settings')->middleware('auth:telegram');
  Route::post("current", [WeatherController::class, "getWeather"])->name("current");
  Route::post("hourly-forecast", [WeatherController::class, "getHourlyForecast"])->name("hourly-forecast");
  Route::post('air-quality', [WeatherController::class, 'getAirQuality'])->name('air-quality');
  Route::post('uv-index', [WeatherController::class, 'getUVIndex'])->name('uv-index');
  Route::post("settings", [WeatherController::class, "saveSettings"])->name("save-settings");
  Route::post("refresh", [WeatherController::class, 'refresh'])->name('refresh');
});