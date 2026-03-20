<?php

use Illuminate\Support\Facades\Route;
use Modules\Weather\Http\Controllers\WeatherController;

Route::prefix('weather')->name('weather.')->group(function () {
  Route::post("current", [WeatherController ::class, "getWeather"])->name("current");
  Route::post("settings", [WeatherController ::class, "saveSettings"])->middleware("telegram.miniapp")->name("save-settings");
});