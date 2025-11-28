-- Миграция для добавления функциональности бонусов за клиента
-- Создание таблицы для хранения истории процентов премии за клиента

CREATE TABLE bonus_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_date DATE NOT NULL,
    bonus_rate DECIMAL(5,2) NOT NULL CHECK (bonus_rate >= 0 AND bonus_rate <= 100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_date (created_date)
) COMMENT='История процентов премии за клиента';

-- Добавляем поля в основную таблицу all_deals
ALTER TABLE all_deals 
ADD COLUMN client_bonus DECIMAL(12,2) DEFAULT 0.00 COMMENT 'Премия за клиента',
ADD COLUMN client_bonus_rate DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Процент премии за клиента';

-- Добавляем индексы для новых полей
ALTER TABLE all_deals 
ADD INDEX idx_client_bonus (client_bonus),
ADD INDEX idx_client_bonus_rate (client_bonus_rate);

-- Вставляем начальное значение процента премии (текущие 5%)
INSERT INTO bonus_clients (created_date, bonus_rate) 
VALUES (CURDATE(), 5.00);
