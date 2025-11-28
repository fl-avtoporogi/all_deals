-- ==========================================
-- Миграция БД: Добавление функционала "Премия за клиента"
-- Дата: 2025-11-28
-- ==========================================

-- Шаг 1: Создание резервной копии таблицы all_deals
CREATE TABLE all_deals_backup_20251128 LIKE all_deals;
INSERT INTO all_deals_backup_20251128 SELECT * FROM all_deals;

-- Проверка: должны быть одинаковое количество строк
SELECT
    (SELECT COUNT(*) FROM all_deals) as original_count,
    (SELECT COUNT(*) FROM all_deals_backup_20251128) as backup_count;

-- Шаг 2: Добавление новых колонок в таблицу all_deals
ALTER TABLE all_deals
ADD COLUMN contact_id INT(11) AFTER responsible_name,
ADD COLUMN contact_responsible_id INT(11) AFTER contact_id,
ADD COLUMN contact_responsible_name VARCHAR(255) AFTER contact_responsible_id,
ADD COLUMN client_bonus DECIMAL(15,2) DEFAULT 0.00 AFTER contact_responsible_name,
ADD COLUMN client_bonus_rate DECIMAL(5,4) DEFAULT 0.0500 AFTER client_bonus,
ADD INDEX idx_contact_id (contact_id),
ADD INDEX idx_contact_responsible_id (contact_responsible_id);

-- Шаг 3: Проверка новой структуры таблицы
DESCRIBE all_deals;

-- Шаг 4 (опционально): Откат в случае проблем
-- DROP TABLE all_deals;
-- RENAME TABLE all_deals_backup_20251128 TO all_deals;

-- ==========================================
-- ИНСТРУКЦИЯ ПО ПРИМЕНЕНИЮ:
-- ==========================================
-- 1. Выполните Шаг 1 для создания резервной копии
-- 2. Проверьте что backup создался корректно
-- 3. Выполните Шаг 2 для добавления колонок
-- 4. Выполните Шаг 3 для проверки структуры
-- 5. После успешного тестирования можно удалить backup:
--    DROP TABLE all_deals_backup_20251128;
