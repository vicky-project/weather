<?php

namespace Modules\Weather\Services;

use Modules\Telegram\Models\TelegramUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Carbon\Carbon;

class WeatherService
{
  protected int $cacheDuration = 900; // 30 menit
  protected string $geocodingUrl = 'http://api.openweathermap.org/geo/1.0/direct';
  protected string $weatherUrl;
  protected string $apiKey;

  public function __construct() {
    $this->apiKey = config('weather.openweather.api_key');
    $this->weatherUrl = config("weather.openweather.base_url") . "/weather";
    $this->forecastUrl = config("weather.openweather.base_url") . "/forecast";
  }

  /**
  * Mendapatkan data cuaca untuk pengguna Telegram tertentu.
  */
  public function getWeatherForUser(TelegramUser $telegramUser): ?array
  {
    $location = $this->getUserDefaultLocation($telegramUser);
    if (!$location) {
      return null;
    }
    return $this->fetchWeatherWithCache($location);
  }

  /**
  * Ambil data cuaca berdasarkan input manual.
  */
  public function getWeatherByInput(?string $city, ?float $latitude, ?float $longitude): ?array
  {
    $location = [];
    if ($city) {
      $location['city'] = $city;
    } elseif ($latitude && $longitude) {
      $location['latitude'] = $latitude;
      $location['longitude'] = $longitude;
    } else {
      return null;
    }
    return $this->fetchWeatherWithCache($location);
  }

  /**
  * Ambil data cuaca dengan cache.
  */
  protected function fetchWeatherWithCache(array $location): ?array
  {
    $cacheKey = $this->generateCacheKey($location);
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
      Log::debug("Weather cache hit", ["key" => $cacheKey]);
      return $cached;
    }

    try {
      $weatherData = $this->fetchFromApi($location);
      if ($weatherData) {
        Cache::put($cacheKey, $weatherData, $this->cacheDuration);
        return $weatherData;
      }
    } catch (Throwable $e) {
      Log::error('Gagal mengambil data cuaca dari API', [
        'location' => $location,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
    }

    return null;
  }

  /**
  * Panggil API dengan fallback geocoding.
  */
  protected function fetchFromApi(array $location): ?array
  {
    if (isset($location['latitude']) && isset($location['longitude'])) {
      return $this->getWeatherByCoordinates($location['latitude'], $location['longitude']);
    }

    if (isset($location['city'])) {
      return $this->getWeatherByCityName($location['city']);
    }

    return null;
  }

  /**
  * Dapatkan cuaca berdasarkan koordinat.
  */
  protected function getWeatherByCoordinates(float $lat, float $lon): ?array
  {
    try {
      $response = Http::get($this->weatherUrl, [
        'lat' => $lat,
        'lon' => $lon,
        'units' => 'metric',
        'appid' => $this->apiKey,
      ]);

      if (!$response->successful()) {
        Log::warning('Weather API error', [
          'status' => $response->status(),
          'body' => $response->body()
        ]);
        return null;
      }

      $data = $response->json();
      if (empty($data) || ($data['cod'] ?? 200) != 200) {
        Log::warning('Weather API invalid response', ['data' => $data]);
        return null;
      }

      // Konversi ke object agar sesuai dengan formatWeatherData
      $rawData = json_decode(json_encode($data));
      return $this->formatWeatherData($rawData);
    } catch (Throwable $e) {
      Log::warning('Gagal get weather by coordinates', [
        'lat' => $lat,
        'lon' => $lon,
        'error' => $e->getMessage()
      ]);
      return null;
    }
  }

  /**
  * Dapatkan cuaca berdasarkan nama kota dengan fallback geocoding.
  */
  protected function getWeatherByCityName(string $city): ?array
  {
    // Coba langsung dengan nama kota
    try {
      $response = Http::get($this->weatherUrl, [
        'q' => $city,
        'units' => 'metric',
        'appid' => $this->apiKey,
      ]);

      if ($response->successful()) {
        $data = $response->json();
        if (($data['cod'] ?? 200) == 200) {
          $rawData = json_decode(json_encode($data));
          return $this->formatWeatherData($rawData);
        }
      }
    } catch (Throwable $e) {
      Log::debug('Langsung gagal, coba geocoding', ['city' => $city, 'error' => $e->getMessage()]);
    }

    // Fallback: geocoding
    $coordinates = $this->geocodeCity($city);
    if (!$coordinates) {
      Log::warning('Geocoding gagal untuk kota', ['city' => $city]);
      return null;
    }

    return $this->getWeatherByCoordinates($coordinates['lat'], $coordinates['lon']);
  }

  /**
  * Geocoding: dapatkan koordinat dari nama kota dengan prioritas Indonesia.
  */
  protected function geocodeCity(string $city): ?array
  {
    try {
      // 1. Cari di Indonesia terlebih dahulu
      $indonesianLocation = $this->searchGeocoding($city, 'ID');
      if ($indonesianLocation) {
        return $indonesianLocation;
      }

      // 2. Cari global
      $response = Http::get($this->geocodingUrl, [
        'q' => $city,
        'limit' => 5,
        'appid' => $this->apiKey,
      ]);

      if (!$response->successful()) {
        return null;
      }

      $locations = $response->json();
      if (empty($locations)) {
        return null;
      }

      $filtered = $this->filterAndSortLocations($locations, $city);
      $best = $filtered[0] ?? null;

      if ($best) {
        return [
          'lat' => $best['lat'],
          'lon' => $best['lon'],
          'name' => $best['name'],
          'country' => $best['country'] ?? null
        ];
      }

      return null;
    } catch (Throwable $e) {
      Log::error('Geocoding exception', [
        'city' => $city,
        'error' => $e->getMessage()
      ]);
      return null;
    }
  }

  /**
  * Cari geocoding dengan filter negara.
  */
  protected function searchGeocoding(string $city, ?string $countryCode = null): ?array
  {
    $params = [
      'q' => $city,
      'limit' => 5,
      'appid' => $this->apiKey,
    ];
    if ($countryCode) {
      $params['country'] = $countryCode;
    }

    $response = Http::get($this->geocodingUrl, $params);
    if (!$response->successful()) {
      return null;
    }

    $locations = $response->json();
    if (empty($locations)) {
      return null;
    }

    $filtered = $this->filterAndSortLocations($locations, $city);
    $best = $filtered[0] ?? null;

    if ($best) {
      return [
        'lat' => $best['lat'],
        'lon' => $best['lon'],
        'name' => $best['name'],
        'country' => $best['country'] ?? null
      ];
    }

    return null;
  }

  /**
  * Filter dan urutkan hasil geocoding berdasarkan skor relevansi.
  */
  protected function filterAndSortLocations(array $locations, string $searchCity): array
  {
    $searchCityLower = strtolower(trim($searchCity));

    $scored = array_map(function ($loc) use ($searchCityLower) {
      $score = 0;
      $nameLower = strtolower($loc['name']);
      $country = $loc['country'] ?? '';

      if ($nameLower === $searchCityLower) {
        $score += 100;
      } elseif (strpos($nameLower, $searchCityLower) !== false) {
        $score += 50;
      }
      if ($country === 'ID') {
        $score += 80;
      }
      if (isset($loc['population'])) {
        $score += min(50, floor($loc['population'] / 1000000));
      }
      return array_merge($loc, ['score' => $score]);
    },
      $locations);

    usort($scored,
      function ($a, $b) {
        return $b['score'] <=> $a['score'];
      });

    return $scored;
  }

  /**
  * Format data cuaca dengan aman.
  */
  protected function formatWeatherData(object $rawData): array
  {
    $coord = $rawData->coord ?? null;
    $main = $rawData->main ?? null;
    $weather = $rawData->weather[0] ?? null;
    $wind = $rawData->wind ?? null;
    $clouds = $rawData->clouds ?? null;
    $sys = $rawData->sys ?? null;
    $visibility = $rawData->visibility ?? null;
    $timezoneOffset = $rawData->timezone ?? 0;

    return [
      'location' => [
        'name' => $rawData->name ?? 'Lokasi tidak diketahui',
        'country' => $sys->country ?? null,
        'latitude' => $coord->lat ?? null,
        'longitude' => $coord->lon ?? null,
        'timezone_offset' => $timezoneOffset
      ],
      'current' => [
        'temperature' => round($main->temp ?? 0),
        'feels_like' => round($main->feels_like ?? 0),
        'humidity' => $main->humidity ?? 0,
        'pressure' => $main->pressure ?? 0,
        'wind_speed' => $wind->speed ?? 0,
        'clouds' => $clouds->all ?? 0,
        'visibility' => $visibility,
      ],
      'weather' => [
        'main' => $weather->main ?? null,
        'description' => $weather->description ?? null,
        'icon' => $weather->icon ?? null,
      ],
      'sun' => [
        'rise' => isset($sys->sunrise) ? date('H:i',
          $sys->sunrise + $rawData->timezone) : null,
        'set' => isset($sys->sunset) ? date('H:i',
          $sys->sunset + $rawData->timezone) : null,
      ],
      'updated_at' => now()->toIso8601String(),
    ];
  }

  /**
  * Mendapatkan data forecast per jam (24 jam ke depan)
  */
  public function getHourlyForecast($location,
    int $timezoneOffset = 0): ?array
  {
    $cacheKey = 'hourly_forecast_' . $this->generateCacheKey($location) . '_offset_'. $timezoneOffset;
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
      Log::debug("Hourly forecast cache hit", ["key" => $cacheKey]);
      return $cached;
    }

    try {
      $forecastData = $this->fetchHourlyForecastFromApi($location, $timezoneOffset);
      if ($forecastData) {
        Cache::put($cacheKey, $forecastData, $this->cacheDuration);
        return $forecastData;
      }
    } catch (Throwable $e) {
      Log::error('Gagal mengambil data forecast per jam', [
        'location' => $location,
        'error' => $e->getMessage()
      ]);
    }
    return null;
  }

  protected function fetchHourlyForecastFromApi($location, int $timezoneOffset = 0): ?array
  {
    if (isset($location['latitude']) && isset($location['longitude'])) {
      return $this->getForecastByCoordinates($location['latitude'], $location['longitude'],
        $timezoneOffset);
    }

    if (isset($location['city'])) {
      return $this->getForecastByCityName($location['city'], $timezoneOffset);
    }

    return null;
  }

  protected function getForecastByCoordinates(float $lat, float $lon, int $timezoneOffset = 0): ?array
  {
    try {
      $response = Http::get($this->forecastUrl, [
        'lat' => $lat,
        'lon' => $lon,
        'units' => 'metric',
        'appid' => $this->apiKey,
      ]);

      if (!$response->successful()) {
        Log::warning('Forecast API error', [
          'status' => $response->status(),
          'body' => $response->body()
        ]);
        return null;
      }

      $data = $response->json();
      if (empty($data) || ($data['cod'] ?? '200') != '200') {
        return null;
      }

      return $this->formatHourlyForecastData($data, $timezoneOffset);
    } catch (Throwable $e) {
      Log::warning('Gagal get forecast by coordinates', [
        'lat' => $lat,
        'lon' => $lon,
        'error' => $e->getMessage()
      ]);
      return null;
    }
  }

  protected function getForecastByCityName(string $city, int $timezoneOffset = 0): ?array
  {
    try {
      $response = Http::get($this->forecastUrl, [
        'q' => $city,
        'units' => 'metric',
        'appid' => $this->apiKey,
      ]);

      if ($response->successful()) {
        $data = $response->json();
        if (($data['cod'] ?? '200') == '200') {
          return $this->formatHourlyForecastData($data, $timezoneOffset);
        }
      }
    } catch (Throwable $e) {
      Log::debug('Forecast langsung gagal, coba geocoding', ['city' => $city, 'error' => $e->getMessage()]);
    }

    // Fallback: geocoding
    $coordinates = $this->geocodeCity($city);
    if (!$coordinates) {
      return null;
    }

    return $this->getForecastByCoordinates($coordinates['lat'], $coordinates['lon'], $timezoneOffset);
  }

  protected function formatHourlyForecastData(array $data, int $timezoneOffset = 0): array
  {
    $list = $data['list'] ?? [];
    if (empty($list)) {
      return ['hourly' => [],
        'chart' => ['labels' => [],
          'temps' => []]];
    }

    $hourly = [];
    $chartLabels = [];
    $chartTemps = [];

    // Ambil 8 data pertama (24 jam ke depan, interval 3 jam)
    $limit = 8;
    $now = Carbon::now()->addSeconds($timezoneOffset);
    $filtered = [];
    foreach ($list as $item) {
      $dt = Carbon::createFromTimestamp($item['dt'] + $timezoneOffset);
      if ($dt->greaterThanOrEqualTo($now)) {
        $filtered[] = $item;
        if (count($filtered) >= $limit) break;
      }
    }

    if (empty($filtered)) {
      $filtered = array_slice($list, 0, $limit);
    }

    foreach ($filtered as $item) {
      $dt = Carbon::createFromTimestamp($item['dt'] + $timezoneOffset);
      $timeLabel = $dt->format('H:i');
      $weather = $item['weather'][0] ?? [];
      $temp = $item['main']['temp'] ?? 0;
      $feelsLike = $item['main']['feels_like'] ?? 0;

      $hourly[] = [
        'time' => $timeLabel,
        'temp' => round($temp),
        'feels_like' => round($feelsLike),
        'humidity' => $item['main']['humidity'] ?? 0,
        'icon' => $weather['icon'] ?? null,
        'description' => $weather['description'] ?? null,
        'pressure' => $item['main']['pressure'] ?? 0,
        'wind_speed' => $item['wind']['speed'] ?? 0,
        'clouds' => $item['clouds']['all'] ?? 0,
        'visibility' => $item['visibility'] ?? 0
      ];
      $chartLabels[] = $timeLabel;
      $chartTemps[] = round($temp);
    }

    return [
      'hourly' => $hourly,
      'chart' => [
        'labels' => $chartLabels,
        'temps' => $chartTemps,
      ]
    ];
  }

  /**
  * Generate cache key.
  */
  protected function generateCacheKey(array $location): string
  {
    if (isset($location['city'])) {
      return 'weather_' . md5(strtolower(trim($location['city'])));
    }
    return 'weather_' . md5("{$location['latitude']},{$location['longitude']}");
  }

  /**
  * Ambil lokasi default dari data pengguna.
  */
  protected function getUserDefaultLocation(TelegramUser $telegramUser): ?array
  {
    $data = $telegramUser->data ?? [];
    return $data['default_location'] ?? null;
  }

  /**
  * Simpan pengaturan pengguna.
  */
  public function updateUserSettings(TelegramUser $telegramUser, array $locationData, bool $notificationsEnabled): void
  {
    $currentData = $telegramUser->data ?? [];
    $currentData['default_location'] = $locationData;
    $currentData['weather_notifications'] = $notificationsEnabled;
    $telegramUser->data = $currentData;
    $telegramUser->save();

    Log::info("User weather setting updated.", ["telegram_id" => $telegramUser->telegram_id, "notifications" => $notificationsEnabled]);
  }

  /**
  * Mendapatkan setting pengguna
  */
  public function getUserSettings($telegramUserId) {
    $user = TelegramUser::find($telegramUserId);
    if (!$user) {
      return null;
    }
    $data = $user->data ?? [];
    // Buat object untuk view
    return (object) [
      'city' => $data['default_location']['city'] ?? null,
      'latitude' => $data['default_location']['latitude'] ?? null,
      'longitude' => $data['default_location']['longitude'] ?? null,
      'notifications_enabled' => $data['weather_notifications'] ?? false
    ];
  }

  public function refresh(array $location): bool
  {
    try {
      $cacheKey = $this->generateCacheKey($location);
      Cache::forget($cacheKey);
      Log::debug("Weather cache cleared", ["key" => $cacheKey]);
      return true;
    } catch(\Exception $e) {
      Log::error("Failed to clear weather cache");
      return false;
    }
  }
}