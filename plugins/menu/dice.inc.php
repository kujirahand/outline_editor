<?php

declare(strict_types=1);

function get_menu_dice(array $args): array
{
    return [
        'label' => 'サイコロ',
        'html' => '
          <form class="plugin-api-form dice-plugin" data-plugin-api-form data-plugin-result="#dice-result">
            <label>
              個数
              <input type="number" name="count" min="1" max="20" value="2">
            </label>
            <label>
              面数
              <input type="number" name="sides" min="2" max="100" value="6">
            </label>
            <button type="submit">振る</button>
            <button class="plugin-secondary-button" type="button" data-plugin-insert-result="total" disabled>合計を追加</button>
          </form>
          <div id="dice-result" class="plugin-api-result" aria-live="polite"></div>
        ',
    ];
}

function exec_dice(array $params): array
{
    $count = dice_int($params['count'] ?? 1, 1, 20);
    $sides = dice_int($params['sides'] ?? 6, 2, 100);
    $rolls = [];
    $total = 0;

    for ($i = 0; $i < $count; $i++) {
        $roll = random_int(1, $sides);
        $rolls[] = $roll;
        $total += $roll;
    }

    return [
        'count' => $count,
        'sides' => $sides,
        'rolls' => $rolls,
        'total' => $total,
        'label' => implode(' + ', array_map('strval', $rolls)) . ' = ' . $total,
    ];
}

function dice_int(mixed $value, int $min, int $max): int
{
    if (!is_int($value) && !(is_string($value) && preg_match('/\A\d+\z/', $value))) {
        return $min;
    }

    return max($min, min($max, (int)$value));
}
