<?php

use Illuminate\Support\Facades\Route;
use Modules\Weather\Http\Controllers\WeatherController;

Route::prefix('weather')->middleware("telegram.miniapp")->name('weather.')->group(function () {
  Route::post("current", [WeatherController ::class, "getWeather"])->name("current");
  Route::post("settings", [WeatherController ::class, "saveSettings"])->name("save-settings");
});