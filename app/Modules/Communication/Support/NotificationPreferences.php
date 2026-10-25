<?php

namespace App\Modules\Communication\Support;

use App\Models\User;
use App\Modules\Communication\Enums\NotificationCategory;
use App\Modules\Communication\Enums\NotificationChannel;
use App\Modules\Communication\Models\NotificationPreference;

/**
 * Per-user channel choices per category. Everything is on unless switched off;
 * mandatory categories are always on.
 */
class NotificationPreferences
{
    public function allows(User $user, NotificationCategory $category, NotificationChannel $channel): bool
    {
        if ($category->isMandatory()) {
            return true;
        }

        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('category', $category->value)
            ->where('channel', $channel)
            ->first();

        return $preference === null || $preference->enabled;
    }

    /**
     * Full matrix for the settings screen.
     *
     * @return array<int, array{category: string, label: string, mandatory: bool, channels: array<string, bool>}>
     */
    public function matrix(User $user): array
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (NotificationPreference $preference): string => $preference->category.'|'.$preference->channel->value);

        $rows = [];

        foreach (NotificationCategory::cases() as $category) {
            $channels = [];

            foreach (NotificationChannel::cases() as $channel) {
                $preference = $stored->get($category->value.'|'.$channel->value);
                $channels[$channel->value] = $category->isMandatory() || $preference === null || $preference->enabled;
            }

            $rows[] = [
                'category' => $category->value,
                'label' => $category->label(),
                'mandatory' => $category->isMandatory(),
                'channels' => $channels,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, bool>>  $matrix  category => channel => enabled
     */
    public function update(User $user, array $matrix): void
    {
        foreach ($matrix as $categoryValue => $channels) {
            $category = NotificationCategory::from($categoryValue);

            if ($category->isMandatory()) {
                continue;
            }

            foreach ($channels as $channelValue => $enabled) {
                NotificationPreference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'category' => $category->value, 'channel' => NotificationChannel::from($channelValue)],
                    ['enabled' => (bool) $enabled],
                );
            }
        }
    }
}
