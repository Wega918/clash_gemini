<?php
// api.php (дополненная версия)

// ... (существующий код)

try {
    // ... (проверки авторизации и CSRF)

    switch ($action) {
        case 'production/collect':
            // ... (уже реализовано)
            break;

        case 'production/upgrade':
            $buildingId = toInt($payload['building_id'] ?? 0);
            if ($buildingId === 0) {
                throw new GameActionException('Не указан ID здания для улучшения');
            }
            
            $building = getPlayerBuildingById($mysqli, $buildingId);
            if (!$building) {
                throw new GameActionException('Здание для улучшения не найдено');
            }

            // Эта функция сама проверит все условия (ресурсы, ТХ) и либо вернет ошибку, либо запустит таймер
            $userAfterUpgrade = startBuildingUpgrade($mysqli, $user, $building);
            $buildingAfterUpgrade = getPlayerBuildingById($mysqli, $buildingId);

            $response['success'] = true;
            $response['message'] = 'Улучшение начато';
            $response['data'] = [
                'building' => $buildingAfterUpgrade, // Отправляем обновленные данные здания
                'new_balance' => [
                    'gold' => (int)$userAfterUpgrade['gold'],
                    'elixir' => (int)$userAfterUpgrade['elixir'],
                    'dark_elixir' => (int)$userAfterUpgrade['dark_elixir'],
                    'gems' => (int)$userAfterUpgrade['gems'],
                ],
            ];
            break;
            
        case 'production/finalize_upgrade':
            // Этот метод будет вызываться JS, когда таймер дойдет до 0
            finalizeCompletedBuildTimers($mysqli, (int)$user['id']);
            
            $buildingId = toInt($payload['building_id'] ?? 0);
            $finalBuilding = getPlayerBuildingById($mysqli, $buildingId);

            $response['success'] = true;
            $response['message'] = 'Улучшение завершено';
            $response['data'] = [
                'building' => $finalBuilding,
            ];
            break;

        default:
            throw new RuntimeException('Unknown action', 400);
    }

} catch (GameActionException $e) {
    // ... (обработка ошибок)
} catch (Throwable $e) {
    // ... (обработка ошибок)
}

echo json_encode($response);
