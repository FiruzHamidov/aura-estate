<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationPriority;
use App\Support\Notifications\NotificationStatus;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => NotificationType::LEAD_NEW,
            'category' => NotificationCategory::WORKFLOW,
            'status' => NotificationStatus::UNREAD,
            'priority' => NotificationPriority::HIGH,
            'channels' => [NotificationChannel::IN_APP],
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(10),
            'action_url' => '/crm/leads/'.fake()->numberBetween(1, 1000),
            'action_type' => 'open_lead',
            'dedupe_key' => fake()->uuid(),
            'occurrences_count' => 1,
            'last_occurred_at' => now(),
            'delivered_at' => now(),
            'data' => [],
        ];
    }
}
