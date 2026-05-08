<?php

namespace Modules\Weather\Console;

use Illuminate\Console\Command;
use Modules\Weather\Notifications\WeatherSent;
use Modules\Weather\Services\WeatherService;
use Modules\Telegram\Models\TelegramUser;
use Carbon\Carbon;

class SendWeatherNotifications extends Command
{
  protected $signature = 'app:weather-sent';
  protected $description = 'Kirim notifikasi cuaca harian ke pengguna yang mengaktifkan';

  protected $weatherService;

  public function __construct(WeatherService $weatherService) {
    parent::__construct();
    $this->weatherService = $weatherService;
  }

  public function handle() {
    $this->info('Memulai pengiriman notifikasi cuaca...');
    \Log::info("Command SendWeatherNotifications started...");

    // Ambil semua user yang mengaktifkan notifikasi cuaca (struktur baru)
    $users = TelegramUser::whereRaw('JSON_EXTRACT(data, "$.weather.notifications_enabled") = true')->get();

    if ($users->isEmpty()) {
      $this->info('Tidak ada pengguna dengan notifikasi cuaca aktif.');
      \Log::info("No users with weather notifications enabled");
      return 0;
    }

    $today = Carbon::today()->toDateString();
    $slot = date("H") < 12 ? "morning" : "evening";
    $sentCount = 0;
    $skipCount = 0;
    $failCount = 0;

    foreach ($users as $user) {
      try {
        $userData = $user->data ?? [];
        $weather = $userData['weather'] ?? [];
        $defaultLocation = $weather['default_location'] ?? [];

        if (empty($defaultLocation)) {
          $this->warn("User {$user->telegram_id} tidak menyimpan lokasi default.");
          \Log::warning("User has no default location", [
            "telegram_id" => $user->telegram_id
          ]);
          $failCount++;
          continue;
        }

        // Ambil history pengiriman notifikasi cuaca
        $weatherNotificationsSent = $weather['notifications_sent'] ?? [];
        if (!is_array($weatherNotificationsSent)) {
          $weatherNotificationsSent = [];
        }

        if (!isset($weatherNotificationsSent[$today]) || !is_array($weatherNotificationsSent[$today])) {
          $weatherNotificationsSent[$today] = [];
        }

        // Cek apakah untuk slot ini sudah dikirim hari ini
        if (isset($weatherNotificationsSent[$today][$slot]) && $weatherNotificationsSent[$today][$slot] === true) {
          $this->info("Notifikasi cuaca sudah dikirim untuk slot {$slot} hari ini, lewati.");
          \Log::debug("Weather notification already sent for {$slot} slot", [
            "telegram_id" => $user->telegram_id
          ]);
          $skipCount++;
          continue;
        }

        // Ambil cuaca untuk user
        $weatherData = $this->weatherService->getWeatherForUser($user);
        if (!$weatherData) {
          $this->warn("Gagal ambil cuaca untuk user {$user->telegram_id}");
          \Log::warning("Failed to get weather for user", [
            "telegram_id" => $user->telegram_id
          ]);
          $failCount++;
          continue;
        }

        $user->notify(new WeatherSent($weatherData));

        // Tandai slot ini sebagai sudah dikirim
        $weatherNotificationsSent[$today][$slot] = true;
        // Simpan kembali ke struktur
        $weather['notifications_sent'] = $weatherNotificationsSent;
        $userData['weather'] = $weather;
        $user->data = $userData;
        $user->save();

        $this->info("Notifikasi cuaca terkirim ke {$user->telegram_id} (slot {$slot})");
        \Log::info("Weather notification sent", [
          "telegram_id" => $user->telegram_id,
          "slot" => $slot
        ]);
        $sentCount++;
      } catch (\Exception $e) {
        \Log::error("Failed to send weather notification.", [
          'telegram_id' => $user->telegram_id,
          'message' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
        ]);
        $this->error("Gagal kirim notifikasi cuaca untuk user {$user->telegram_id}");
        $failCount++;
      }
    }

    $this->info("Selesai. {$sentCount} notifikasi terkirim, {$skipCount} dilewati, {$failCount} gagal");
    \Log::info("Command SendWeatherNotifications finished", [
      "sent" => $sentCount,
      "skip" => $skipCount,
      "fail" => $failCount
    ]);
    return 0;
  }
}