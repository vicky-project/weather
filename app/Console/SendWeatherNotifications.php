<?php

namespace Modules\Weather\Console;

use Illuminate\Console\Command;
use Modules\Telegram\Models\TelegramUser;
use Modules\Weather\Services\WeatherService;
use Modules\Telegram\Services\Support\TelegramApi;
use Modules\Telegram\Services\Support\TelegramMarkdownHelper;
use Carbon\Carbon;

class SendWeatherNotifications extends Command
{
  protected $signature = 'app:weather-sent';
  protected $description = 'Kirim notifikasi cuaca harian ke pengguna yang mengaktifkan';

  protected $weatherService;
  protected $telegramApi;

  public function __construct(
    WeatherService $weatherService,
    TelegramApi $telegramApi
  ) {
    parent::__construct();
    $this->weatherService = $weatherService;
    $this->telegramApi = $telegramApi;
  }

  public function handle() {
    $this->info('Memulai pengiriman notifikasi cuaca...');

    // Ambil semua user yang mengaktifkan notifikasi cuaca
    $users = TelegramUser::all()->filter(function ($user) {
      $data = $user->data ?? [];
      return ($data['weather_notifications'] ?? false) === true;
    });

    if ($users->isEmpty()) {
      $this->info('Tidak ada pengguna dengan notifikasi cuaca aktif.');
      return 0;
    }

    $today = Carbon::today()->toDateString();
    $sentCount = 0;

    foreach ($users as $user) {
      $data = $user->data ?? [];
      $defaultLocation = $data['default_location'] ?? [];

      if (empty($defaultLocation)) {
        continue;
      }

      // Cek apakah sudah dikirim hari ini (mencegah pengiriman duplikat)
      $lastWeatherNotification = $data['last_weather_notification'] ?? null;
      if ($lastWeatherNotification === $today) {
        // Sudah dikirim hari ini, lewati
        continue;
      }

      // Ambil cuaca untuk user
      $weatherData = $this->weatherService->getWeatherForUser($user);
      if (!$weatherData) {
        $this->warn("Gagal ambil cuaca untuk user {$user->telegram_id}");
        continue;
      }

      $message = $this->formatWeatherMessage($weatherData);
      $sent = $this->telegramApi->sendMessage($user->telegram_id, TelegramMarkdownHelper::escapeMarkdownV2($message), 'MarkdownV2');

      if ($sent) {
        // Simpan catatan pengiriman
        $data['last_weather_notification'] = $today;
        $user->data = $data;
        $user->save();

        $this->info("Notifikasi cuaca terkirim ke {$user->telegram_id}");
        $sentCount++;
      }
    }

    $this->info("Selesai. {$sentCount} notifikasi cuaca terkirim.");
    return 0;
  }

  protected function formatWeatherMessage(array $weather): string
  {
    $w = $weather;
    $emoji = $this->getWeatherEmoji($w['weather']['main']);

    return "🌤 *Prakiraan Cuaca Hari Ini*\n" .
    "📍 {$w['location']['name']}, {$w['location']['country']}\n\n" .
    "{$emoji} {$w['weather']['description']}\n" .
    "🌡 Suhu: {$w['current']['temperature']}°C (terasa {$w['current']['feels_like']}°C)\n" .
    "💧 Kelembaban: {$w['current']['humidity']}%\n" .
    "💨 Angin: {$w['current']['wind_speed']} m/s\n" .
    "☁️ Awan: {$w['current']['clouds']}%\n\n" .
    "🌅 Terbit: {$w['sun']['rise']}\n" .
    "🌇 Terbenam: {$w['sun']['set']}\n\n" .
    "Semoga hari Anda menyenangkan!";
  }

  protected function getWeatherEmoji($condition) {
    $map = [
      'Clear' => '☀️',
      'Clouds' => '☁️',
      'Rain' => '🌧',
      'Drizzle' => '🌦',
      'Thunderstorm' => '⛈',
      'Snow' => '🌨',
      'Mist' => '🌫',
      'Fog' => '🌫',
      'Haze' => '🌫',
    ];
    return $map[$condition] ?? '🌤';
  }
}