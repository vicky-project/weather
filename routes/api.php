<?php

use Illuminate\Support\Facades\Route;
use Modules\Weather\Http\Controllers\WeatherController;

Route::prefix('weather')->name('weather.')->group(function () {
  Route::get("current", [WeatherController ::class, "current"])->name("current");
});