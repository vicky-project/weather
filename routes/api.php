<?php

use Illuminate\Support\Facades\Route;
use Modules\Weather\Http\Controllers\WeatherController;

Route::prefix('weather')->name('weather.')->group(function () {
  Route::post("current", [WeatherController::class, "getWeather"])->name("current");
  Route::post("hourly-forecast", [WeatherController::class, "getHourlyForecast"])->name("hourly-forecast");
  Route::post('air-quality', [WeatherController::class, 'getAirQuality'])->name('air-quality');
  Route::post("settings", [WeatherController::class, "saveSettings"])->middleware("telegram.miniapp")->name("save-settings");
  Route::post("refresh", [WeatherController::class, 'refresh'])->name('refresh');
});