# 🎮 NEW RAID BATTLE SYSTEM 2026

Полностью переделанная система рейдов с современным дизайном, улучшенной графикой и data-driven логикой.

## 📁 Структура файлов

### Frontend (JS)
```
js/raid_new/
├── RaidApi.js              # API клиент для взаимодействия с бэкендом
├── RaidApp.js              # Главный контроллер приложения
├── RaidSearchManager.js    # Облачные эффекты поиска противника
├── ScoutScene.js           # Экран разведки (30 сек)
├── BattleScene.js          # Главный экран боя (3 мин)
└── battle_entry.js         # Точка входа
```

### Frontend (CSS)
```
css/
└── raid_battle_new.css     # Современный дизайн с адаптивностью
```

### Frontend (HTML)
```
app/locations/
└── battle.php              # UI шаблон рейда
```

### Backend (PHP)
```
app/
└── battle_api.php          # API endpoints (уже существовал, оставлен без изменений)
```

## 🎨 Визуальные улучшения

### Дизайн
- ✅ Компактный viewport 820x560 (адаптивный)
- ✅ Современные градиенты и glass-морфизм
- ✅ Плавные анимации (fade, slide, pulse, glow)
- ✅ Backdrop filters с blur
- ✅ Многослойные box shadows
- ✅ Responsive breakpoints: 980px, 760px, 540px, 320px

### Эффекты
- ✅ 28 динамических облаков при поиске
- ✅ 45 fog particles
- ✅ Sparkles анимация
- ✅ Wobble effects
- ✅ Pulse glow
- ✅ Проектайлы с трейлами
- ✅ Hit flashes и частицы
- ✅ Explosion effects
- ✅ Spell визуальные эффекты

## 🎯 Фазы боя

### 1. Поиск противника
- Облачная анимация на Canvas
- Кнопка смены цели за золото
- Плавные переходы между состояниями
- Shimmer/glow эффекты

### 2. Разведка (30 секунд)
- Визуализация вражеской базы
- Показ угроз и защит
- Hover highlights
- Показ своей армии
- Таймер обратного отсчета

### 3. Бой (3 минуты)
- Lane-based система (3 линии, 5 сегментов)
- Data-driven боевая логика
- Высадка войск/героев
- Применение заклинаний
- Способности героев
- Реальные характеристики всех сущностей

### 4. Результат
- Звёзды (0-3)
- % разрушения
- Украденные ресурсы
- Статистика боя

## 🔧 Боевая механика

### Data-Driven подход
Все характеристики юнитов, героев, заклинаний и зданий используются в расчётах:
- ✅ HP, Damage, DPS, Attack Speed
- ✅ Range, Movement Type (ground/air/hybrid)
- ✅ Target Priority (defense/resource/townhall/any)
- ✅ Special Abilities
- ✅ Splash Damage
- ✅ Wall Breaking
- ✅ Summon Mechanics
- ✅ Hero Abilities

### Приоритеты целей
```javascript
PRIORITY_WEIGHTS = {
  townhall: 100,
  defense: 85,
  resource: 40,
  wall: 30,
  building: 20,
  any: 0
}
```

### Заклинания
- **Rage**: +30% скорости атаки, +40% урона
- **Heal**: Восстановление HP
- **Freeze**: Замораживание защит
- **Lightning**: Мгновенный урон 3 целям
- **Earthquake**: 35% урона стенам, 18% зданиям
- **Invisibility**: Невидимость для юнитов

### Способности героев
- **Barbarian King - Iron Fist**: +36% HP, Rage 10 сек
- **Archer Queen - Royal Cloak**: Invisibility 5 сек, Rage 6 сек, +22% HP
- **Grand Warden - Eternal Tome**: Invulnerability в радиусе 4.5 сек
- **Royal Champion - Seeking Shield**: 2.5x урон по 4 защитам

## 📱 Адаптивность

### Desktop (1200px+)
- Полный размер viewport 820x560
- Все элементы видны
- Hover эффекты

### Tablet (760-980px)
- Адаптированный размер 760x620
- Компактные элементы UI
- Touch-friendly кнопки (44x44px)

### Mobile (540px и меньше)
- Fullscreen viewport
- Вертикальная раскладка панелей
- Swipe gestures
- Упрощённые эффекты

## 🚀 Как использовать

### 1. Запуск игры
Откройте игру и нажмите кнопку "В БОЙ!" в верхней панели.

### 2. Поиск противника
- Система автоматически начнёт поиск
- Дождитесь пока облака рассеются
- Если цель не понравилась, нажмите "Сменить" (стоит золото)

### 3. Разведка
- Изучите вражескую базу (30 секунд)
- Обратите внимание на угрозы (красные кружки)
- Посмотрите на расположение защит и стен
- Нажмите "Атаковать" когда готовы

### 4. Бой
- Выбирайте юнитов/героев/заклинания из нижней панели
- Кликайте на поле боя для высадки
- Для заклинаний выбирайте точку применения
- Активируйте способности героев кнопкой "⚡ Способность"
- Следите за таймером и процентом разрушения
- Нажмите "Завершить", когда готовы закончить

### 5. Результат
- Смотрите итоги боя
- Получайте ресурсы и трофеи
- Нажмите "Выход" для возврата в деревню

## 🔌 API Endpoints

### Bootstrap
```
POST /app/battle_api.php
action=bootstrap

Response:
{
  ok: true,
  player: {...},
  army: {troops, heroes, spells},
  next_cost: 1000
}
```

### Search Opponent
```
POST /app/battle_api.php
action=search_opponent
reroll=0|1

Response:
{
  ok: true,
  target: {...},
  player: {...},
  army: {...},
  next_cost: 1500
}
```

### Start Raid
```
POST /app/battle_api.php
action=start_raid
defender_id=123

Response:
{
  ok: true,
  raid: {
    id: 456,
    target: {...},
    army: {...},
    player: {...}
  }
}
```

### Resolve Raid
```
POST /app/battle_api.php
action=resolve_raid
raid_id=456
result_json={...}

Response:
{
  ok: true,
  result: {
    stars: 2,
    destructionPercent: 67,
    loot: {...},
    trophyDelta: +23
  }
}
```

## 🐛 Известные особенности

### Совместимость
- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+
- ✅ Mobile browsers

### Производительность
- Object pooling для юнитов и эффектов
- Culling для off-screen entities
- RequestAnimationFrame оптимизация
- Debounced resize handlers

## 📝 Changelog

### v2.0.0 (2026-03-18)
- ✅ Полностью переписана система рейдов
- ✅ Новый современный дизайн
- ✅ Облачные эффекты поиска
- ✅ Улучшенная боевая механика
- ✅ Data-driven подход
- ✅ Адаптивный интерфейс
- ✅ Touch поддержка
- ✅ Продвинутые визуальные эффекты
- ✅ Реальное использование характеристик
- ✅ Способности героев
- ✅ Заклинания с эффектами

## 🎯 Будущие улучшения

### Планируется
- [ ] WebGL рендеринг для ещё более крутых эффектов
- [ ] Replay система для просмотра боёв
- [ ] Расширенная статистика
- [ ] Кланвар система
- [ ] Турнирный режим
- [ ] Достижения за бои
- [ ] Leaderboard
- [ ] Battle pass

## 👨‍💻 Разработка

### Структура кода
Весь код модульный и расширяемый:
- `RaidApp` - главный контроллер
- `RaidApi` - абстракция API
- `RaidSearchManager` - поиск
- `ScoutScene` - разведка
- `BattleScene` - бой (самый большой модуль)

### Добавление нового юнита
1. Добавьте данные в `game_data.php`
2. Система автоматически подхватит характеристики
3. Добавьте иконку в `/images/units/`

### Добавление нового заклинания
1. Добавьте данные в `game_data_spells.php`
2. Реализуйте эффект в `BattleScene.applySpellEffect()`
3. Добавьте визуал в `BattleScene.createSpellParticles()`

### Добавление нового здания
1. Добавьте данные в `game_data_buildings.php`
2. Система автоматически обработает боевую логику
3. Добавьте иконку в `/images/buildings/`

## 📄 Лицензия

Proprietary - Clash Browser Game © 2026

---

**Создано с ❤️ и Canvas API**
