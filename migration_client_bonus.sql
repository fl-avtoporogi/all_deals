-- Миграция для добавления функциональности бонусов за клиента
-- Создание таблицы для хранения истории процентов премии за клиента

CREATE TABLE bonus_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_date DATE NOT NULL,
    bonus_rate DECIMAL(5,2) NOT NULL CHECK (bonus_rate >= 0 AND bonus_rate <= 100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_date (created_date)
) COMMENT='История процентов премии за клиента';

-- Поля client_bonus и client_bonus_rate уже существуют в таблице all_deals
-- Индексы уже добавлены ранее

-- Вставляем начальное значение процента премии (текущие 5%)
INSERT INTO bonus_clients (created_date, bonus_rate) 
VALUES (CURDATE(), 5.00);
