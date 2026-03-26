<?php

namespace Modules\Weather\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class WeatherSent extends Notification implements ShouldQueue
{
  use Queueable;

  /**
  * The number of times the notification may be attempted.
  *
  * @var int
  */
  public $tries = 5;

  /**
  * The number of seconds the notification can run before timing out.
  *
  * @var int
  */
  public $timeout = 120;

  /**
  * The maximum number of unhandled exceptions to allow before failing.
  *
  * @var int
  */
  public $maxExceptions = 3;

  public function __construct(protected array $weatherData) {}

  public function via($notifiable) {
    return ["telegram"];
  }

  public function toTelegram($notifiable) {
    $message = $this->formatWeatherMessage($this->weatherData);

    return [
      "text" => $message,
      "parse_mode" => "MarkdownV2"
    ];
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