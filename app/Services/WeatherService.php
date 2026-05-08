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

  public function __construct() {
    $this->apiKey = config('weather.openweather.api_key');
    $this->uviUrl = config("weather.openweather.base_url") . "/3.0/onecall";
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
      Log::info("Weather cache hit", ["key" => $cacheKey]);
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
      $response = Http::get(config("weather.openweather.base_url") . "/weather", [
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
      $response = Http::get(config("weather.openweather.base_url") . "/weather", [
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
      Log::warning('Langsung gagal, coba geocoding', ['city' => $city, 'error' => $e->getMessage()]);
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
        'wind_deg' => $wind->deg ?? 0,
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
      Log::info("Hourly forecast cache hit", ["key" => $cacheKey]);
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
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
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
      $response = Http::get(config("weather.openweather.base_url") . "/forecast", [
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
      $response = Http::get(config("weather.openweather.base_url") . "/forecast", [
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
      Log::warning('Forecast langsung gagal, coba geocoding', ['city' => $city, 'error' => $e->getMessage()]);
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
      $dateLabel = $dt->format('d/m/Y');
      $fullDateTime = $dt->format('d-m-Y H:i:s');
      $weather = $item['weather'][0] ?? [];
      $temp = $item['main']['temp'] ?? 0;
      $feelsLike = $item['main']['feels_like'] ?? 0;

      $hourly[] = [
        'time' => $timeLabel,
        'date' => $dateLabel,
        'datetime' => $fullDateTime,
        'temp' => round($temp),
        'feels_like' => round($feelsLike),
        'humidity' => $item['main']['humidity'] ?? 0,
        'pop' => ($item['pop'] ?? 0) * 100,
        'icon' => $weather['icon'] ?? null,
        'description' => $weather['description'] ?? null,
        'pressure' => $item['main']['pressure'] ?? 0,
        'wind_speed' => $item['wind']['speed'] ?? 0,
        'wind_deg' => $item['wind']['deg'] ?? 0,
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
  * Mendapatkan data kualitas udara (AQI) berdasarkan koordinat
  */
  public function getAirQuality(float $lat, float $lon): ?array
  {
    $cacheKey = 'air_quality_' . md5("{$lat},{$lon}");
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
      Log::info("Air quality cache hit", ["key" => $cacheKey]);
      return $cached;
    }

    try {
      $response = Http::get(config("weather.openweather.base_url") . "/air_pollution", [
        'lat' => $lat,
        'lon' => $lon,
        'appid' => $this->apiKey,
      ]);

      if (!$response->successful()) {
        Log::warning('Air pollution API error', ['status' => $response->status()]);
        return null;
      }

      $data = $response->json();
      if (empty($data) || !isset($data['list'][0])) {
        return null;
      }

      $aqiData = $data['list'][0];
      $aqi = $aqiData['main']['aqi']; // 1-5
      $components = $aqiData['components'];

      $result = [
        'aqi' => $aqi,
        'level' => $this->getAqiLevel($aqi),
        'recommendation' => $this->getAqiRecommendation($aqi),
        'components' => [
          'pm2_5' => round($components['pm2_5'] ?? 0, 1),
          'pm10' => round($components['pm10'] ?? 0, 1),
          'o3' => round($components['o3'] ?? 0, 1),
          'no2' => round($components['no2'] ?? 0, 1),
          'so2' => round($components['so2'] ?? 0, 1),
          'co' => round($components['co'] ?? 0, 1),
        ],
      ];

      Cache::put($cacheKey, $result, $this->cacheDuration);
      return $result;
    } catch (Throwable $e) {
      Log::error('Gagal mengambil data kualitas udara', [
        'lat' => $lat,
        'lon' => $lon,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
      return null;
    }
  }

  protected function getAqiLevel($aqi): string
  {
    switch ($aqi) {
      case 1: return 'Baik';
      case 2: return 'Sedang';
      case 3: return 'Tidak Sehat untuk Sensitif';
      case 4: return 'Tidak Sehat';
      case 5: return 'Sangat Tidak Sehat';
      default: return 'Tidak diketahui';
    }
  }

  protected function getAqiRecommendation($aqi): string
  {
    switch ($aqi) {
    case 1: return 'Aktivitas luar ruangan aman.';
    case 2: return 'Kelompok sensitif sebaiknya kurangi aktivitas luar yang lama.';
    case 3: return 'Kurangi aktivitas luar ruangan yang berat.';
    case 4: return 'Hindari aktivitas luar ruangan. Gunakan masker jika keluar.';
    case 5: return 'Tetap di dalam ruangan. Gunakan pembersih udara.';
    default: return '';
    }
  }

  /**
  * Mendapatkan indeks UV berdasarkan koordinat
  */
  public function getUVIndex(float $lat, float $lon, ?string $timezone = null): ?array
  {
    $cacheKey = 'uv_index_' . md5("{$lat},{$lon}_{$timezone}");
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
      Log::info("UV index cache hit", ["key" => $cacheKey]);
      return $cached;
    }

    try {
      // Menggunakan Open-Meteo API (gratis, tanpa API key)
      $url = "https://api.open-meteo.com/v1/forecast";
      $params = [
        'latitude' => $lat,
        'longitude' => $lon,
        'daily' => 'uv_index_max',
      ];

      if ($timezone) {
        $params["timezone"] = $timezone;
      } else {
        $params["timezone"] = "auto";
      }
      $response = Http::get($url, $params);

      if (!$response->successful()) {
        Log::warning('Open-Meteo API error for UV', ['status' => $response->status()]);
        return null;
      }

      $data = $response->json();
      if (empty($data) || !isset($data['daily']['uv_index_max'][0])) {
        return null;
      }

      $uvi = $data['daily']['uv_index_max'][0]; // Nilai UV maksimum hari ini
      $result = [
        'uvi' => $uvi,
        'level' => $this->getUvLevel($uvi),
        'recommendation' => $this->getUvRecommendation($uvi),
        'color' => $this->getUvColor($uvi),
      ];

      Cache::put($cacheKey, $result, $this->cacheDuration);
      return $result;
    } catch (Throwable $e) {
      Log::error('Gagal mengambil indeks UV', [
        'lat' => $lat,
        'lon' => $lon,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
      return null;
    }
  }

  protected function getUvLevel($uvi): string
  {
    if ($uvi <= 2) return 'Rendah';
    if ($uvi <= 5) return 'Sedang';
    if ($uvi <= 7) return 'Tinggi';
    if ($uvi <= 10) return 'Sangat Tinggi';
    return 'Ekstrem';
  }

  protected function getUvRecommendation($uvi): string
  {
    if ($uvi <= 2) return 'Aman beraktivitas di luar.';
    if ($uvi <= 5) return 'Gunakan tabir surya dan topi.';
    if ($uvi <= 7) return 'Hindari matahari langsung (10-16). Gunakan pelindung.';
    if ($uvi <= 10) return 'Bahaya! Gunakan tabir surya SPF 30+, kacamata hitam, dan pakaian tertutup.';
    return 'Sangat berbahaya! Jangan keluar rumah jika tidak perlu.';
  }

  protected function getUvColor($uvi): string
  {
    if ($uvi <= 2) return '#198754'; // hijau
    if ($uvi <= 5) return '#ffc107'; // kuning
    if ($uvi <= 7) return '#fd7e14'; // oranye
    if ($uvi <= 10) return '#dc3545'; // merah
    return '#6f42c1'; // ungu untuk ekstrem
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
    $data = $telegramUser->data['weather'] ?? [];
    return $data['default_location'] ?? null;
  }

  /**
  * Simpan pengaturan pengguna.
  */
  public function updateUserSettings(TelegramUser $telegramUser, array $locationData, bool $notificationsEnabled): void
  {
    $data = $telegramUser->data ?? [];
    $currentData = $data['weather'] ?? [];
    $currentData['default_location'] = $locationData;
    $currentData['weather_notifications'] = $notificationsEnabled;
    $data['weather'] = $currentData;
    $telegramUser->data = $data;
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
    $data = $user->data['weather'] ?? [];
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
      Log::info("Weather cache cleared", ["key" => $cacheKey]);
      return true;
    } catch(\Exception $e) {
      Log::error("Failed to clear weather cache");
      return false;
    }
  }
}