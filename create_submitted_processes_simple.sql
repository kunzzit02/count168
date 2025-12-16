-- 创建简化的submitted_processes表
CREATE TABLE IF NOT EXISTS submitted_processes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    process_id INT NOT NULL,
    date_submitted DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- 外键约束
    FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE,
    FOREIGN KEY (process_id) REFERENCES process(id) ON DELETE CASCADE,
    
    -- 索引
    INDEX idx_user_date (user_id, date_submitted),
    INDEX idx_process (process_id)
);

-- 添加注释
ALTER TABLE submitted_processes 
COMMENT = '记录用户提交process的简单历史记录';
