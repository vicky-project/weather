<?php

use Illuminate\Support\Facades\Route;
use Modules\Weather\Http\Controllers\WeatherController;

Route::prefix('apps')
->name('apps.')
->group(function () {
  Route::get('weather', [WeatherController::class, "index"])->name('weather');
});