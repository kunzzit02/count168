-- Create data capture tables
-- Main table to store the capture session information
CREATE TABLE IF NOT EXISTS data_captures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    capture_date DATE NOT NULL,
    process_id INT NOT NULL,
    currency_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    INDEX idx_process (process_id),
    INDEX idx_currency (currency_id),
    INDEX idx_capture_date (capture_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Detail table to store each row from the summary table
CREATE TABLE IF NOT EXISTS data_capture_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    capture_id INT NOT NULL,
    id_product_main VARCHAR(255),
    description_main VARCHAR(255),
    id_product_sub VARCHAR(255),
    description_sub VARCHAR(255),
    product_type ENUM('main', 'sub') NOT NULL DEFAULT 'main',
    account_id INT NOT NULL,
    currency_id INT NOT NULL,
    columns_value VARCHAR(100),
    source_value TEXT,
    source_percent DECIMAL(10, 2),
    formula TEXT,
    processed_amount DECIMAL(15, 2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (capture_id) REFERENCES data_captures(id) ON DELETE CASCADE,
    INDEX idx_capture (capture_id),
    INDEX idx_account (account_id),
    INDEX idx_product_type (product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

